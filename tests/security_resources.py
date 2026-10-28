#!/usr/bin/env python3
"""Small, serial resource-boundary checks against an owned disposable database.

Requires the explicit BETATEAM_SQL_TEST_* settings used by security_http.py,
the guarded loopback test router, and an existing ten-record synthetic fixture.
Never targets the shared XAMPP database or starts a load/concurrency test.
The cleanup boundary uses 1,002 synthetic SQL rows and at most seven list GETs.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import urllib.parse

from security_http import Client, Database, PASSWORD, TestFailure, verify_server_binding


REPOSITORY = Path(__file__).resolve().parent.parent
PATCHED_BRANCHES = {802: 80235, 803: 80336, 804: 80427, 805: 80512}


def require(condition: bool, message: str):
    if not condition:
        raise TestFailure(message)


class Resources:
    def __init__(self, args, db):
        self.args, self.db = args, db
        self.results = []
        self.observations = {}
        self.environment = os.environ.copy()
        self.environment.update(
            BETATEAM_DB_HOST=db.database_host, BETATEAM_DB_PORT=db.database_port,
            BETATEAM_DB_NAME=db.database_name, BETATEAM_DB_USER=os.environ['BETATEAM_SQL_TEST_USER'],
            BETATEAM_DB_PASSWORD=os.environ.get('BETATEAM_SQL_TEST_PASSWORD', ''),
            BETATEAM_MAIL_VERIFY='0', BETATEAM_APP_KEY='private-resource-test-only-0123456789abcdef0123456789abcdef',
        )

    def case(self, name, work):
        try:
            work()
            result = {'name':name, 'passed':True}
        except TestFailure as error:
            result = {'name':name, 'passed':False, 'failure':str(error)}
        except Exception as error:
            result = {'name':name, 'passed':False, 'failure':'Private test execution failed ['+type(error).__name__+']'}
        self.results.append(result)
        print(('PASS ' if result['passed'] else 'FAIL ')+name, flush=True)

    def php(self, *, script=None, code=None, arguments=(), check=True, timeout=15):
        command = [str(self.args.php), '-c', str(self.args.ini)]
        command += ['-r', code] if code is not None else [str(script)]
        result = subprocess.run(command+list(arguments), cwd=self.args.php.parent, env=self.environment,
                                capture_output=True, timeout=timeout)
        if check:
            require(result.returncode == 0, 'Private PHP probe did not complete')
            require(not result.stderr.strip(), 'Private PHP probe produced unexpected diagnostics')
        return result

    def php_json(self, **kwargs):
        result = self.php(**kwargs)
        try:
            value = json.loads(result.stdout.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise TestFailure('Private PHP probe returned invalid JSON') from error
        require(isinstance(value,dict), 'Private PHP probe JSON must be an object')
        return value

    def login(self, index):
        client = Client(self.args.base_url)
        token = client.token('login.php')
        response = client.request('login.php', {'csrf':token, 'email':f'teacher{index}@example.invalid','password':PASSWORD})
        require(response[0] == 303, 'Synthetic fixture login failed')
        return client

    def private_config_guard(self):
        # config.local.php would override the explicitly supplied environment.
        require(not (self.args.private_app/'config.local.php').exists(), 'Private app must not have a local configuration override')
        code = r'''require $argv[1].'/errors.php';
$GLOBALS['config']=require $argv[1].'/config.php'; require $argv[1].'/db.php';
$row=query('SELECT DATABASE() AS db_name,@@port AS db_port,HEX(@@datadir) AS data_path')->fetch();
echo json_encode(['host'=>$GLOBALS['config']['db']['host'],'name'=>$row['db_name'],'port'=>(int)$row['db_port'],'datadir_hex'=>$row['data_path']]);'''
        value = self.php_json(code=code,arguments=(str(self.args.private_app),))
        require(value.get('host') == self.db.database_host and value.get('name') == self.db.database_name
                and value.get('port') == int(self.db.database_port), 'Private PHP configuration points at another database')
        require(value.get('datadir_hex') == self.db.sql('SELECT HEX(@@datadir)'), 'Private PHP and SQL client use different data directories')

    def blocked_login_growth(self):
        self.db.sql('DELETE FROM rate_limits')
        key = hashlib.sha256(b'login-ip:127.0.0.1').hexdigest()
        self.db.sql(f"INSERT INTO rate_limits(bucket_key,hits,window_started) VALUES ('{key}',30,{int(time.time())})")
        client = Client(self.args.base_url)
        token = client.token('login.php')
        counts, statuses = [], []
        for index in (1,2):
            response = client.request('login.php', {'csrf':token,'email':f'resource-denied-{index}@example.invalid','password':'WrongPassword123!'})
            require(response[0] == 429, 'Seeded IP limit did not reject login')
            require(response[1].get('Retry-After','').isdigit() and int(response[1]['Retry-After']) > 0, 'Blocked login omitted positive Retry-After')
            counts.append(int(self.db.sql('SELECT COUNT(*) FROM rate_limits')))
            statuses.append(response[0])
        require(counts == [1,1], 'Blocked IP still creates account-specific rate buckets')
        require(self.db.sql(f"SELECT hits FROM rate_limits WHERE bucket_key='{key}'") == '30', 'Blocked IP changed its hit counter')
        self.observations['blocked_login'] = {'requests':2,'statuses':statuses,'bucket_counts':counts,'growth':counts[-1]-1}

    def search_account_budget(self):
        self.db.sql('DELETE FROM rate_limits')
        admin, operator = self.login(1), self.login(2)
        # Seed the first 28 uses; do not create HTTP load just to reach a boundary.
        self.db.sql('DELETE FROM rate_limits')
        key = hashlib.sha256(b'search-user:10001').hexdigest()
        self.db.sql(f"INSERT INTO rate_limits(bucket_key,hits,window_started) VALUES ('{key}',28,{int(time.time())})")
        paths = ['students.php?q=resource-first','teachers.php?q=resource-second',
                 'students.php?q=resource-third','teachers.php?q=resource-fourth']
        observed = []
        for path, expected in zip(paths,(200,200,429,429)):
            response = admin.request(path)
            require(response[0] == expected, 'Student/teacher search did not share the expected account budget')
            if expected == 429:
                require(response[1].get('Retry-After','').isdigit() and int(response[1]['Retry-After']) > 0, 'Search limit omitted positive Retry-After')
            observed.append(response[0])
        require(self.db.sql('SELECT COUNT(*) FROM rate_limits') == '1', 'Changing search terms created extra buckets')
        require(self.db.sql(f"SELECT hits FROM rate_limits WHERE bucket_key='{key}'") == '30', 'Account search counter exceeded its cap')
        for path in ('students.php','teachers.php?q='):
            require(admin.request(path)[0] == 200, 'Empty search or normal list was throttled')
        require(self.db.sql(f"SELECT hits FROM rate_limits WHERE bucket_key='{key}'") == '30', 'Empty list view consumed search budget')
        require(operator.request('students.php?q=independent-resource-search')[0] == 200, 'A different account inherited the search limit')
        other = hashlib.sha256(b'search-user:10002').hexdigest()
        require(self.db.sql(f"SELECT hits FROM rate_limits WHERE bucket_key='{other}'") == '1', 'Different account did not receive an independent fixed bucket')
        require(self.db.sql('SELECT COUNT(*) FROM rate_limits') == '2', 'Search accounting uses unexpected extra buckets')
        self.observations['search'] = {'seeded_hits':28,'list_gets':7,'shared_boundary_statuses':observed,
            'empty_lists_allowed':True,'independent_account_allowed':True,'final_bucket_count':2}

    def query_timeout(self):
        code = r'''require $argv[1].'/errors.php';
$GLOBALS['config']=require $argv[1].'/config.php'; require $argv[1].'/db.php';
$pdo=db(); $version=(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
if (stripos($version,'MariaDB')===false) { echo json_encode(['mariadb'=>false]); exit; }
$settings=query('SELECT @@innodb_lock_wait_timeout AS lock_wait,@@max_statement_time AS statement_limit')->fetch();
$start=microtime(true); $result=['mariadb'=>true,'lock_wait'=>(int)$settings['lock_wait'],'statement_limit'=>(float)$settings['statement_limit']];
try { query('SELECT SLEEP(4)')->fetchColumn(); $result+=['interrupted'=>false,'elapsed'=>microtime(true)-$start]; }
catch (PDOException $error) { $result+=['interrupted'=>true,'driver_code'=>(int)($error->errorInfo[1]??0),'status'=>failure_status($error),'elapsed'=>microtime(true)-$start]; }
$synthetic=new PDOException('synthetic'); $synthetic->errorInfo=['HY000',3024,'synthetic'];
$result['mysql_timeout_classification']=failure_status($synthetic); echo json_encode($result);'''
        value = self.php_json(code=code,arguments=(str(self.args.private_app),),timeout=12)
        require(value.get('mariadb') is True, 'This measured timeout case requires the dedicated MariaDB instance')
        require(value.get('lock_wait') == 5 and value.get('statement_limit') == 3, 'Session query budgets are not configured')
        require(value.get('interrupted') is True and value.get('driver_code') == 1969 and value.get('status') == 503, 'MariaDB timeout was not classified as service unavailable')
        require(2.5 <= value.get('elapsed',0) < 3.9, 'Single SLEEP query did not stop near the three-second budget')
        require(value.get('mysql_timeout_classification') == 503, 'Synthetic MySQL timeout classification is incorrect')
        source = (self.args.private_app/'db.php').read_text(encoding='utf-8-sig')
        require(re.search(r'PDO::ATTR_TIMEOUT\s*=>\s*5\b',source) is not None, 'Five-second PDO connection timeout is missing')
        self.observations['query_timeout'] = value | {'mysql_server_executed':False,'connection_timeout_verified':'configuration only'}

    def maintenance_boundary(self):
        require((self.args.private_app/'maintenance.php').is_file(), 'Private maintenance script is missing')
        self.db.sql('DELETE FROM rate_limits; DELETE FROM password_changes')
        require(self.db.sql('SELECT COUNT(*) FROM teachers WHERE id BETWEEN 30001 AND 31002') == '0', 'Maintenance fixture teacher IDs are occupied')
        now = int(time.time())
        # Each pending change has a distinct FK teacher. These additional teachers
        # are disposable synthetic rows; the original four-table snapshot restores them.
        teacher_values, rate_values, pending_values = [], [], []
        lit = self.db.literal
        fixture_hash = self.db.sql('SELECT password FROM teachers WHERE id=10003')
        require(fixture_hash.startswith('$2y$'), 'Synthetic fixture password hash is missing')
        for index in range(1,1003):
            identifier = 30000+index
            teacher_values.append(f"({identifier},{lit('資源試験')},{lit(str(index))},{lit(f'resource-{index}@example.invalid')},"
                                  f'{lit(fixture_hash)},'
                                  "(SELECT id FROM roles WHERE name='general'),1)")
            bucket = hashlib.sha256(f'resource-expired-{index}'.encode()).hexdigest()
            rate_values.append(f"('{bucket}',1,{now-90001})")
            values = [identifier,hashlib.sha256(f'resource-token-{index}'.encode()).hexdigest(),
                      hashlib.sha256(f'resource-session-{index}'.encode()).hexdigest(),f'resource-{index}@example.invalid',
                      hashlib.sha256(f'resource-code-{index}'.encode()).hexdigest(),'unused-synthetic-pending-hash',0,now-1]
            pending_values.append('('+','.join(lit(value) for value in values)+')')
        self.db.sql('INSERT INTO teachers(id,last_name,first_name,email,password,role_id,must_change_password) VALUES '+','.join(teacher_values))
        active_bucket = hashlib.sha256(b'resource-active').hexdigest()
        rate_values.append(f"('{active_bucket}',3,{now})")
        self.db.sql('INSERT INTO rate_limits(bucket_key,hits,window_started) VALUES '+','.join(rate_values))
        active_pending = [10001,hashlib.sha256(b'resource-active-token').hexdigest(),hashlib.sha256(b'resource-active-session').hexdigest(),
                          'teacher1@example.invalid',hashlib.sha256(b'resource-active-code').hexdigest(),'unused-active-pending-hash',0,now+600]
        pending_values.append('('+','.join(lit(value) for value in active_pending)+')')
        self.db.sql('INSERT INTO password_changes(teacher_id,token,session_binding,email,code_hash,pending_hash,credential_version,expires_at) VALUES '+','.join(pending_values))
        active_before = self.db.sql(f"SELECT JSON_OBJECT('key',bucket_key,'hits',hits,'window',window_started) FROM rate_limits WHERE bucket_key='{active_bucket}'")
        pending_before = self.db.sql('SELECT JSON_OBJECT(\'id\',teacher_id,\'token\',token,\'expiry\',expires_at) FROM password_changes WHERE teacher_id=10001')
        def counts():
            return [int(value) for value in self.db.sql(f'SELECT COUNT(*) FROM rate_limits WHERE window_started < {now-86400}; SELECT COUNT(*) FROM password_changes WHERE expires_at <= {now}').splitlines()]
        def active_retained():
            return (self.db.sql(f"SELECT JSON_OBJECT('key',bucket_key,'hits',hits,'window',window_started) FROM rate_limits WHERE bucket_key='{active_bucket}'") == active_before
                    and self.db.sql('SELECT JSON_OBJECT(\'id\',teacher_id,\'token\',token,\'expiry\',expires_at) FROM password_changes WHERE teacher_id=10001') == pending_before)
        dry = self.php_json(script=self.args.private_app/'maintenance.php')
        require(dry == {'mode':'dry-run','batch_limit':1000,'rate_limits_eligible':1002,'password_changes_eligible':1002}, 'Default maintenance did not report the expected eligible counts')
        require(counts() == [1002,1002] and active_retained(), 'Dry-run modified pending or active records')
        first = self.php_json(script=self.args.private_app/'maintenance.php',arguments=('--prune',))
        require(first == {'mode':'prune','batch_limit':1000,'rate_limits_deleted':1000,'password_changes_deleted':1000}, 'First prune exceeded or missed the per-table batch boundary')
        require(counts() == [2,2] and active_retained(), 'First prune did not retain the remainder and active records')
        second = self.php_json(script=self.args.private_app/'maintenance.php',arguments=('--prune',))
        require(second == {'mode':'prune','batch_limit':1000,'rate_limits_deleted':2,'password_changes_deleted':2}, 'Second prune did not remove only the remaining expired records')
        require(counts() == [0,0] and active_retained(), 'Second prune changed active records')
        invalid = self.php(script=self.args.private_app/'maintenance.php',arguments=('--unknown',),check=False)
        require(invalid.returncode != 0 and b'Usage:' in invalid.stderr, 'Unknown maintenance option was accepted')
        require(counts() == [0,0] and active_retained(), 'Invalid maintenance arguments changed data')
        self.observations['maintenance'] = {'dry_run':dry,'first_prune':first,'second_prune':second,
            'active_records_unchanged':True,'unknown_argument_rejected':True,'executed_only_in_private_app':True}


def parse_args():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url',required=True)
    parser.add_argument('--php',type=Path,required=True)
    parser.add_argument('--ini',type=Path)
    parser.add_argument('--private-root',type=Path,required=True)
    parser.add_argument('--private-app',type=Path,required=True)
    parser.add_argument('--report',type=Path,default=REPOSITORY/'output/security-resources.json')
    return parser.parse_args()


def main():
    args = parse_args()
    report = {'executed_at':dt.datetime.now(dt.timezone.utc).isoformat(),'cases':[],'restored':False,
              'limits':{'list_gets_max':7,'parallel_requests':0,'sleep_queries':1,'synthetic_expired_rows_per_table':1002},
              'limitations':['MySQL server timeout branch is not executed; only code 3024 classification is tested.',
                             'PDO connection timeout is checked in configuration; no blackhole-network test is performed.']}
    db = suite = snapshot = None
    fatal = False
    try:
        url = urllib.parse.urlsplit(args.base_url)
        require(url.scheme == 'http' and url.hostname == '127.0.0.1' and url.port is not None
                and url.port not in (80,8080,3306,3307) and not url.username and not url.password and not url.query and not url.fragment
                and url.path in ('','/'), 'Only an explicit dedicated loopback HTTP root is allowed')
        args.base_url = args.base_url.rstrip('/')+'/'
        require(args.php.is_absolute() and args.php.is_file(), 'An absolute patched PHP executable is required')
        args.php = args.php.resolve()
        args.ini = args.ini.resolve() if args.ini else args.php.parent/'php.ini'
        require(args.ini.is_file(), 'The private PHP configuration is missing')
        require(args.private_root.is_absolute() and args.private_app.is_absolute(), 'Absolute owned private paths are required')
        args.private_root, args.private_app = args.private_root.resolve(), args.private_app.resolve()
        require(args.private_root.parent == Path(tempfile.gettempdir()).resolve()
                and args.private_root.name.startswith('betateam-security-')
                and args.private_app == args.private_root/'app' and args.private_app.is_dir(), 'Private paths must be the owned temporary app root')
        output_root = (REPOSITORY/'output').resolve()
        args.report = args.report.resolve()
        require(args.report.is_relative_to(output_root) and args.report.suffix == '.json', 'Reports must stay inside the repository output folder')
        version = subprocess.run([str(args.php),'-c',str(args.ini),'-r','echo PHP_VERSION_ID;'],cwd=args.php.parent,capture_output=True,timeout=10)
        require(version.returncode == 0 and version.stdout.isdigit() and not version.stderr.strip(), 'Private PHP version probe failed')
        version_id = int(version.stdout)
        require(version_id >= PATCHED_BRANCHES.get(version_id//100,10**9), 'An explicitly supported patched PHP branch is required')
        db = Database()
        require(db.database_host == '127.0.0.1' and db.database_port not in ('3306','3307')
                and re.fullmatch(r'beta_security_test_[a-z0-9_]+',db.database_name), 'Dedicated loopback SQL settings are required')
        verify_server_binding(args.base_url,db)
        actual_hex = db.sql('SELECT HEX(@@datadir)')
        raw_path = bytes.fromhex(actual_hex)
        try: actual = raw_path.decode('utf-8')
        except UnicodeDecodeError: actual = raw_path.decode('cp932')
        expected = str(args.private_root/'data')
        require(actual.replace('\\','/').rstrip('/').lower() == expected.replace('\\','/').rstrip('/').lower(), 'SQL datadir is not the owned temporary database')
        candidate = db.snapshot()
        db.fixture_guard(candidate)
        suite = Resources(args,db)
        suite.private_config_guard()
        snapshot = candidate
        report['guards'] = {'loopback':True,'database_prefix':True,'http_binding':True,'owned_datadir':True,'private_php_binding':True,'fixture':True}
        suite.case('IP-limited login does not allocate new account buckets',suite.blocked_login_growth)
        suite.case('Search shares one fixed account budget and preserves independent accounts',suite.search_account_budget)
        suite.case('MariaDB statement budget interrupts one query and maps timeout to 503',suite.query_timeout)
        suite.case('Maintenance defaults to dry-run and prunes at most 1000 expired rows per table',suite.maintenance_boundary)
    except TestFailure as error:
        fatal = True
        report['failure'] = str(error)
    except Exception as error:
        fatal = True
        report['failure'] = 'Private test setup or transport failed ['+type(error).__name__+']'
    finally:
        if db is not None and snapshot is not None:
            try:
                if db.snapshot() != snapshot:
                    db.restore(snapshot)
                db.sql('DELETE FROM rate_limits')
                report['restored'] = db.snapshot() == snapshot
            except Exception:
                fatal = True
                report['failure'] = 'Private synthetic fixture restoration failed'
        if suite:
            report['cases'] = suite.results
            report['observations'] = suite.observations
        report['total'] = len(report['cases'])
        report['passed'] = sum(case['passed'] for case in report['cases'])
        report['failed'] = report['total']-report['passed']
        report['complete'] = not fatal
        # Invalid report paths are never used, including on a rejected setup.
        if args.report.resolve().is_relative_to((REPOSITORY/'output').resolve()) and args.report.suffix == '.json':
            args.report.parent.mkdir(parents=True,exist_ok=True)
            args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(f"security-resources: {report['passed']}/{report['total']} passed; fixture restored={report['restored']}")
    if report.get('failure'):
        print(report['failure'],file=sys.stderr)
    return 1 if fatal or report['failed'] or not report['restored'] else 0


if __name__ == '__main__':
    raise SystemExit(main())
