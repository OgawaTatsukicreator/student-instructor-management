#!/usr/bin/env python3
"""Run HTTP security regressions against an explicitly configured local test DB.

Requires Python's standard library and the MySQL CLI. This script never creates a
database. See --help for the deliberately restrictive connection requirements.
"""
from __future__ import annotations

import argparse
import datetime as dt
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request


TABLES = ("teachers", "students", "student_teacher", "password_changes")
KEYS = {"teachers": "id", "students": "id", "student_teacher": "student_id,teacher_id", "password_changes": "teacher_id"}
PASSWORD = "SqlTest123!"
SESSION_NAME = "betateam_session"


class TestFailure(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Client:
    def __init__(self, base: str, seed_session: str | None = None):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect()
        )
        if seed_session:
            self.jar.set_cookie(http.cookiejar.Cookie(
                version=0, name=SESSION_NAME, value=seed_session, port=None, port_specified=False,
                domain="127.0.0.1", domain_specified=False, domain_initial_dot=False,
                path=urllib.parse.urlsplit(base).path, path_specified=True, secure=False,
                expires=None, discard=True, comment=None, comment_url=None, rest={}, rfc2109=False,
            ))

    def request(self, path: str, data=None, *, method=None, raw: bytes | None = None, content_type=None):
        body = raw if raw is not None else (urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None)
        headers = {"Content-Type": "application/x-www-form-urlencoded"} if body is not None else {}
        if content_type is not None:
            headers['Content-Type'] = content_type
        request = urllib.request.Request(self.base + path, data=body, headers=headers, method=method)
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode("utf-8", "replace")

    def token(self, path: str):
        response = self.request(path)
        match = re.search(r'name="csrf"\s+value="([a-f0-9]{64})"', response[2])
        if response[0] != 200 or not match:
            raise TestFailure("CSRF token could not be obtained from the expected form")
        return match.group(1)

    def session_id(self):
        values = [cookie.value for cookie in self.jar if cookie.name == SESSION_NAME]
        return values[-1] if values else None


class Database:
    def __init__(self):
        names = {key: os.environ.get("BETATEAM_SQL_TEST_" + key) for key in ("DATABASE", "HOST", "PORT", "USER")}
        if not all(names.values()) or not re.fullmatch(r"beta_security_test_[a-z0-9_]+", names["DATABASE"] or ""):
            raise TestFailure("Explicit BETATEAM_SQL_TEST_* settings and a beta_security_test_ database are required")
        if names["HOST"] not in ("127.0.0.1", "localhost", "::1"):
            raise TestFailure("The test database must be on the loopback interface")
        if not (names["PORT"] or "").isdigit() or not 1 <= int(names["PORT"]) <= 65535:
            raise TestFailure("The test database port is invalid")
        executable = os.environ.get("BETATEAM_MYSQL_CLI", os.environ.get("MYSQL_CLI", "C:/xampp/mysql/bin/mysql.exe"))
        self.command = [executable, "--no-defaults", "--protocol=TCP", "--connect-timeout=3",
                        "--host=" + names["HOST"], "--port=" + names["PORT"], "--user=" + names["USER"],
                        "--database=" + names["DATABASE"], "--default-character-set=utf8mb4", "--batch", "--raw", "--skip-column-names", "--unbuffered"]
        self.environment = os.environ.copy()
        self.database_name = names['DATABASE']
        self.database_host = names['HOST']
        self.database_port = names['PORT']
        self.environment["MYSQL_PWD"] = os.environ.get("BETATEAM_SQL_TEST_PASSWORD", "")
        self.columns = {}
        rows = self.sql("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION")
        for line in rows.splitlines():
            table, column = line.split("\t", 1)
            if table in TABLES:
                if not re.fullmatch(r"[a-z_]+", column):
                    raise TestFailure("Unexpected schema identifiers")
                self.columns.setdefault(table, []).append(column)
        if set(self.columns) != set(TABLES):
            raise TestFailure("The four expected application tables are required")

    def sql(self, statement: str):
        result = subprocess.run(self.command, input=statement.encode(), env=self.environment, capture_output=True, timeout=20)
        if result.returncode:
            raise TestFailure("The dedicated test database query failed")
        return result.stdout.decode("utf-8").replace("\r\n", "\n").strip()

    def snapshot(self):
        statements = []
        for table in TABLES:
            fields = ",".join("'" + name + "',`" + name + "`" for name in self.columns[table])
            statements.append(f"SELECT '__snapshot_count__','{table}',COUNT(*) FROM `{table}`")
            statements.append(f"SELECT '{table}',JSON_OBJECT({fields}) FROM `{table}` ORDER BY {KEYS[table]}")
        statements.append("SELECT '__snapshot_end__','complete'")
        result = {table: [] for table in TABLES}
        counts = {}
        # The bundled Windows client can return exit code 0 after truncating a
        # buffered stdout stream. Flush its output and verify an explicit frame;
        # never treat a partial snapshot as an unchanged database.
        lines = self.sql(";\n".join(statements)).split("\n")
        if not lines or lines[-1] != "__snapshot_end__\tcomplete":
            raise TestFailure("The dedicated database snapshot output was truncated (missing end marker)")
        for line in lines[:-1]:
            if line.startswith("__snapshot_count__\t"):
                parts = line.split("\t")
                if len(parts) != 3 or parts[1] not in result or parts[1] in counts or not parts[2].isdigit():
                    raise TestFailure("The dedicated database snapshot count frame is invalid")
                counts[parts[1]] = int(parts[2])
                continue
            table, separator, row = line.partition("\t")
            if not separator or table not in result:
                raise TestFailure("The dedicated database snapshot row frame is invalid")
            try:
                value = json.loads(row)
            except json.JSONDecodeError as error:
                raise TestFailure("The dedicated database snapshot JSON is incomplete [JSONDecodeError]") from error
            if not isinstance(value, dict) or set(value) != set(self.columns[table]):
                raise TestFailure("The dedicated database snapshot row shape is invalid")
            result[table].append(value)
        if set(counts) != set(TABLES) or any(len(result[table]) != counts[table] for table in TABLES):
            raise TestFailure("The dedicated database snapshot row counts do not match")
        return result

    @staticmethod
    def literal(value):
        if value is None:
            return "NULL"
        if isinstance(value, (int, float)):
            return str(value)
        return "CONVERT(0x" + str(value).encode().hex() + " USING utf8mb4)" if value != "" else "''"

    def restore(self, snapshot):
        statements = ["START TRANSACTION"]
        for table in reversed(TABLES):
            statements.append(f"DELETE FROM `{table}`")
        for table in TABLES:
            columns = self.columns[table]
            for row in snapshot[table]:
                values = ",".join(self.literal(row[column]) for column in columns)
                statements.append(f"INSERT INTO `{table}` (`" + "`,`".join(columns) + f"`) VALUES ({values})")
        statements.append("COMMIT")
        self.sql(";\n".join(statements))

    def fixture_guard(self, snapshot):
        if any(len(snapshot[table]) != 10 for table in TABLES):
            raise TestFailure("Exactly ten fixture records per variable table are required")
        if {row["id"] for row in snapshot["teachers"]} != set(range(10001, 10011)) or {row["id"] for row in snapshot["students"]} != set(range(10001, 10011)):
            raise TestFailure("Only the designated synthetic fixture IDs may be used")
        teachers = {row["id"]: row for row in snapshot["teachers"]}
        for index in range(1, 11):
            row = teachers[10000 + index]
            if row["email"] != f"teacher{index}@example.invalid" or int(row["must_change_password"]) != 0:
                raise TestFailure("Synthetic teacher emails and completed initial password change flags are required")
        if self.sql("SELECT GROUP_CONCAT(r.name ORDER BY t.id) FROM teachers t JOIN roles r ON r.id=t.role_id WHERE t.id IN(10001,10002,10003)") != "admin,operator,general":
            raise TestFailure("The first three fixture roles do not match the test contract")
        if self.sql("SELECT COUNT(*) FROM roles") != "3" or self.sql("SELECT COUNT(*) FROM system_locks WHERE name='management'") != "1":
            raise TestFailure("The initial role and management lock definitions are required")
        pairs = {(row["student_id"], row["teacher_id"]) for row in snapshot["student_teacher"]}
        if (10003, 10003) not in pairs or (10001, 10003) in pairs:
            raise TestFailure("The assigned and unassigned student fixtures do not match")


class Suite:
    def __init__(self, base, db):
        self.base, self.db = base, db
        self.results = []

    def case(self, name, work):
        try:
            work()
            result = {"name": name, "passed": True}
        except TestFailure as error:
            result = {"name": name, "passed": False, "failure": str(error)}
        except Exception as error:
            # Bodies, cookies, SQL rows and connection details must not enter reports.
            result = {"name": name, "passed": False, "failure": "Unexpected test transport or execution failure [" + type(error).__name__ + "]"}
        self.results.append(result)
        print(("PASS " if result["passed"] else "FAIL ") + name, flush=True)

    @staticmethod
    def require(condition, message):
        if not condition:
            raise TestFailure(message)

    def status(self, response, expected):
        expected = {expected} if isinstance(expected, int) else set(expected)
        self.require(response[0] in expected, f"Expected HTTP {','.join(map(str, sorted(expected)))}; received {response[0]}")
        self.require(not re.search(r"(?:Fatal error|Stack trace|SQLSTATE\[|C:\\xampp\\|PDOException|Warning:)", response[2], re.I), "Response leaked internal diagnostics")

    def unchanged(self, request, expected):
        before = self.db.snapshot()
        try:
            response = request()
            after = self.db.snapshot()
            if after != before:
                self.db.restore(before)
                raise TestFailure("Rejected request changed application data; fixture was restored")
            self.status(response, expected)
            return response
        except Exception:
            if self.db.snapshot() != before:
                self.db.restore(before)
            raise

    def login(self, index):
        client = Client(self.base)
        token = client.token("login.php")
        previous = client.session_id()
        response = client.request("login.php", {"csrf": token, "email": f"teacher{index}@example.invalid", "password": PASSWORD})
        self.status(response, 303)
        self.require(previous and client.session_id() and previous != client.session_id(), "Session ID was not rotated after authentication")
        self.status(client.request("students.php"), 200)
        return client, token

    def run(self):
        anonymous = Client(self.base)
        anonymous.token("login.php")
        self.case("anonymous protected page redirects to login", lambda: self.status(anonymous.request("students.php"), 303))
        admin, prelogin = self.login(1)
        operator, _ = self.login(2)
        general, _ = self.login(3)
        self.case("authentication rotates session IDs for all roles", lambda: None)
        self.case("pre-login CSRF token is invalid after authentication", lambda: self.unchanged(
            lambda: admin.request("student_create.php", {"csrf": prelogin}), 403))

        fixtures = self.db.snapshot()
        student = next(row for row in fixtures["students"] if row["id"] == 10003)
        teacher = next(row for row in fixtures["teachers"] if row["id"] == 10004)
        student_payload = {"last_name": "試験生徒", "first_name": "番号3", "birth_date": "2013-03-15", "notes": "fixture", "teacher_ids[]": ["10003"], "version": str(student["version"])}
        teacher_payload = {"last_name": teacher["last_name"], "first_name": teacher["first_name"], "email": teacher["email"], "role": "general", "notes": teacher["notes"], "version": str(teacher["version"]), "initial_password": PASSWORD}
        routes = [
            (anonymous, "login.php", {"email": "teacher1@example.invalid", "password": PASSWORD}),
            (admin, "logout.php", {}),
            (admin, "student_create.php", student_payload),
            (admin, "student_edit.php?id=10003", student_payload),
            (general, "student_notes.php?id=10003", {"notes": "fixture", "version": str(student["version"])}),
            (admin, "student_delete.php?id=10003", {"version": str(student["version"])}),
            (admin, "teacher_create.php", dict(teacher_payload, email="new-security@example.invalid")),
            (admin, "teacher_edit.php?id=10004", teacher_payload),
            (admin, "teacher_delete.php?id=10004", {"version": str(teacher["version"])}),
            (admin, "password_change.php", {"current_password": PASSWORD, "new_password": "NewSqlTest123!", "confirm_password": "NewSqlTest123!"}),
        ]
        foreign_token = anonymous.token("login.php")
        for client, path, payload in routes:
            for label, addition in (("missing", {}), ("wrong", {"csrf": "0" * 64}), ("array", {"csrf[]": "0" * 64}), ("foreign", {"csrf": foreign_token if client is not anonymous else admin.token("students.php")})):
                self.case(f"CSRF {label}: {path}", lambda c=client, p=path, d=dict(payload, **addition): self.unchanged(lambda: c.request(p, d), 403))

        # The optional verification route can redirect when mail verification is
        # disabled or no pending session exists; it must never mutate fixture data.
        self.case("verification without a matching pending session is harmless", lambda: self.unchanged(lambda: admin.request("password_verify.php", {"csrf": "0" * 64, "action": "verify", "code": "000000"}), {303, 403}))
        self.case('JSON is not an accepted form encoding', lambda: self.unchanged(
            lambda: anonymous.request('login.php', raw=b'{}', content_type='application/json'), 415))
        self.case('too many input fields are rejected before CSRF', lambda: self.unchanged(
            lambda: anonymous.request('login.php', raw='&'.join(f'f{i}=x' for i in range(1002)).encode()), 413))

        for path in ("login.php", "students.php", "student_create.php", "student_edit.php?id=10003", "student_delete.php?id=10003", "teacher_create.php", "teacher_delete.php?id=10004", "password_change.php", "logout.php"):
            for method in ("PUT", "DELETE"):
                def wrong_method(p=path, m=method):
                    response = self.unchanged(lambda: admin.request(p, method=m), 405)
                    self.require(bool(response[1].get("Allow")), "405 response omitted Allow")
                self.case(f"method {method}: {path}", wrong_method)
        for path in ("student_delete.php?id=10003", "teacher_delete.php?id=10004", "logout.php"):
            def get_write_only(p=path):
                response = self.unchanged(lambda: admin.request(p), 405)
                self.require("POST" in response[1].get("Allow", ""), "Write-only route omitted POST from Allow")
            self.case("GET cannot perform mutation: " + path, get_write_only)

        for client, path, expected in (
            (general, "student_create.php", 403), (general, "student_edit.php?id=10003", 403),
            (general, "teacher_create.php", 403), (general, "teachers.php", 403),
            (general, "student_notes.php?id=10001", 403), (operator, "teacher_detail.php?id=10001", 404),
            (operator, "teacher_edit.php?id=10004", 403),
        ):
            self.case("authorization GET: " + path, lambda c=client, p=path, e=expected: self.unchanged(lambda: c.request(p), e))
        for client, path in ((general, "student_delete.php?id=10003"), (operator, "teacher_delete.php?id=10004"), (operator, "teacher_create.php")):
            token = client.token("students.php")
            self.case("authorization POST: " + path, lambda c=client, p=path, t=token: self.unchanged(lambda: c.request(p, {"csrf": t, "version": "0"}), 403))

        token = admin.token("student_create.php")
        invalid_students = [
            ("required name", {"last_name": ""}, 422), ("long name", {"first_name": "あ" * 51}, 422),
            ("future birthday", {"birth_date": "2099-01-01"}, 422), ("impossible birthday", {"birth_date": "2011-02-29"}, 422),
            ("long notes", {"notes": "あ" * 2001}, 422), ("missing assignment", {"teacher_ids[]": []}, 422),
            ("nonexistent assignment", {"teacher_ids[]": ["4294967295"]}, {409, 422}),
        ]
        for label, changed, expected in invalid_students:
            payload = dict(student_payload, csrf=token, **changed)
            self.case("student validation: " + label, lambda d=payload, e=expected: self.unchanged(lambda: admin.request("student_create.php", d), e))
        token = admin.token("teacher_create.php")
        for label, changed, expected in (
            ("invalid email", {"email": "invalid"}, 422), ("undefined role", {"role": "owner"}, 422),
            ("weak initial password", {"initial_password": "short"}, 422),
            ("duplicate email", {"email": "TEACHER1@EXAMPLE.INVALID"}, {409, 422}),
        ):
            payload = dict(teacher_payload, csrf=token, **changed)
            self.case("teacher validation: " + label, lambda d=payload, e=expected: self.unchanged(lambda: admin.request("teacher_create.php", d), e))

        for path in ("student_detail.php?id[]=10003", "student_detail.php?id=0", "student_detail.php?id=4294967296", "student_detail.php?id=1%20OR%201%3D1", "students.php?page[]=1", "students.php?page=%00"):
            self.case("invalid identifier: " + path, lambda p=path: self.unchanged(lambda: admin.request(p), {400, 422}))
        for path in ("student_detail.php?id=999999", "teacher_detail.php?id=999999"):
            self.case("missing record: " + path, lambda p=path: self.unchanged(lambda: admin.request(p), 404))

        token = admin.token("student_create.php")
        raw_base = urllib.parse.urlencode(dict(student_payload, csrf=token), doseq=True).encode()
        for label, raw in (
            ("scalar name as array", raw_base + b"&last_name%5B%5D=bad"),
            ("nested teacher IDs", raw_base + b"&teacher_ids%5B0%5D%5Bbad%5D=1"),
            ("NUL character", raw_base + b"&last_name=bad%00name"),
            ("invalid UTF-8", raw_base + b"&last_name=%FF%FE"),
        ):
            self.case("malformed POST: " + label, lambda d=raw: self.unchanged(lambda: admin.request("student_create.php", raw=d), {400, 422}))
        self.case("request body over 64 KiB is rejected", lambda: self.unchanged(lambda: admin.request("student_create.php", raw=raw_base + b"&notes=" + b"a" * 65537), 413))

        token = admin.token("student_edit.php?id=10003")
        stale = dict(student_payload, csrf=token, version="4294967295")
        self.case("stale student version conflicts", lambda: self.unchanged(lambda: admin.request("student_edit.php?id=10003", stale), 409))
        token = admin.token("teacher_edit.php?id=10004")
        stale_teacher = dict(teacher_payload, csrf=token, version="4294967295")
        self.case("stale teacher version conflicts", lambda: self.unchanged(lambda: admin.request("teacher_edit.php?id=10004", stale_teacher), 409))

        def mass_assignment():
            before = self.db.snapshot()
            notes = '<script>alert("security-fixture")</script> テスト'
            payload = {"csrf": general.token("student_notes.php?id=10003"), "version": str(student["version"]), "notes": notes,
                       "last_name": "改ざん", "email": "changed@example.invalid", "role": "admin", "password": "Changed123!", "teacher_ids[]": ["10007"]}
            try:
                response = general.request("student_notes.php?id=10003", payload)
                self.status(response, {303, 400, 422})
                after = self.db.snapshot()
                if response[0] == 303:
                    actual = next(row for row in after["students"] if row["id"] == 10003)
                    self.require(actual["notes"] == notes and int(actual["version"]) == int(student["version"]) + 1, "Allowed notes update was not persisted correctly")
                    for field in ("last_name", "first_name", "birth_date", "created_at"):
                        self.require(actual[field] == student[field], "Protected student field changed")
                    for table in ("teachers", "student_teacher", "password_changes"):
                        self.require(after[table] == before[table], "Unrelated or privileged data changed")
                    page = general.request("student_detail.php?id=10003")
                    self.status(page, 200)
                    self.require("&lt;script&gt;" in page[2] and '<script>alert("security-fixture")' not in page[2], "Stored notes were not escaped")
                else:
                    self.require(after == before, "Rejected mass-assignment request changed data")
            finally:
                if self.db.snapshot() != before:
                    self.db.restore(before)
        self.case("notes update resists mass assignment and stored XSS", mass_assignment)

        def security_headers():
            response = Client(self.base).request("login.php")
            self.status(response, 200)
            self.require("no-store" in response[1].get("Cache-Control", ""), "Missing no-store")
            self.require(response[1].get("X-Content-Type-Options", "").lower() == "nosniff", "Missing nosniff")
            self.require("frame-ancestors 'none'" in response[1].get("Content-Security-Policy", ""), "Missing frame protection")
            cookies = " ".join(response[1].get_all("Set-Cookie", []))
            self.require("httponly" in cookies.lower() and "samesite=lax" in cookies.lower(), "Session cookie attributes are incomplete")
        self.case("security response headers", security_headers)

        def fixation():
            supplied = "0123456789abcdef0123456789abcdef"
            client = Client(self.base, supplied)
            client.token("login.php")
            self.require(client.session_id() and client.session_id() != supplied, "Attacker-supplied session ID was accepted")
        self.case("strict sessions refuse attacker-supplied session ID", fixation)

        def logout():
            old_id = operator.session_id()
            token = operator.token("students.php")
            self.status(operator.request("logout.php", {"csrf": token}), 303)
            self.status(operator.request("students.php"), 303)
            replay = Client(self.base, old_id)
            self.status(replay.request("students.php"), 303)
            self.require(replay.session_id() != old_id, "Logged-out session ID remained usable")
        self.case("logout invalidates the old session", logout)

        # A separate account avoids previous malformed-input checks consuming the
        # current-password quota used by this boundary test.
        def current_password_limit():
            client, _ = self.login(4)
            token = client.token("password_change.php")
            for attempt in range(5):
                response = self.unchanged(lambda: client.request("password_change.php", {
                    "csrf": token, "current_password": "WrongPassword123!", "new_password": "DifferentSql123!", "confirm_password": "DifferentSql123!"
                }), 422)
            client, _ = self.login(4)
            token = client.token('password_change.php')
            response = self.unchanged(lambda: client.request("password_change.php", {
                "csrf": token, "current_password": "WrongPassword123!", "new_password": "DifferentSql123!", "confirm_password": "DifferentSql123!"
            }), 429)
            self.require(response[1].get("Retry-After", "").isdigit() and int(response[1]["Retry-After"]) > 0, "Rate limit omitted a positive Retry-After")
        self.case("current-password failures are rate limited across five attempts", current_password_limit)


def verify_server_binding(base: str, db: Database):
    probe = Client(base).request('login.php')
    if (probe[0] != 200 or probe[1].get('X-BetaTeam-Test-Database') != db.database_name
            or probe[1].get('X-BetaTeam-Test-Host') != db.database_host
            or probe[1].get('X-BetaTeam-Test-Port') != db.database_port):
        raise TestFailure('HTTP server is not bound to the configured dedicated test database; use tests/security_router.php')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=os.environ.get("BETATEAM_SECURITY_BASE_URL"), help="Required: HTTP(S) URL with literal host 127.0.0.1")
    args = parser.parse_args()
    report = {"executed_at": dt.datetime.now(dt.timezone.utc).isoformat(), "cases": [], "restored": False}
    db = initial = suite = None
    fatal = False
    try:
        if not args.base_url:
            raise TestFailure("A base URL argument or BETATEAM_SECURITY_BASE_URL is required")
        url = urllib.parse.urlsplit(args.base_url)
        if url.scheme not in ("http", "https") or url.hostname != "127.0.0.1" or url.username or url.password or url.query or url.fragment or ".." in urllib.parse.unquote(url.path).split("/"):
            raise TestFailure("Only an explicit loopback test URL without credentials, query or fragment is accepted")
        if url.port is not None and not 1 <= url.port <= 65535:
            raise TestFailure("The test HTTP port is invalid")
        base = args.base_url.rstrip("/") + "/"
        db = Database()
        verify_server_binding(base, db)
        initial = db.snapshot()
        db.fixture_guard(initial)
        suite = Suite(base, db)
        suite.run()
    except TestFailure as error:
        fatal = True
        report["failure"] = str(error)
    except Exception:
        fatal = True
        report["failure"] = "Test setup or transport failed; check the dedicated local test services"
    finally:
        if db is not None and initial is not None:
            try:
                if db.snapshot() != initial:
                    db.restore(initial)
                report["restored"] = db.snapshot() == initial
            except Exception:
                fatal = True
                report["failure"] = "Dedicated fixture restoration failed"
        if suite:
            report["cases"] = suite.results
        report["total"] = len(report["cases"])
        report["passed"] = sum(case["passed"] for case in report["cases"])
        report["failed"] = report["total"] - report["passed"]
        report["complete"] = not fatal
        output = Path(__file__).resolve().parent.parent / "output"
        output.mkdir(exist_ok=True)
        (output / "security-http.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"security-http: {report['passed']}/{report['total']} passed; fixture restored={report['restored']}; output/security-http.json")
    if report.get("failure"):
        print(report["failure"], file=sys.stderr)
    return 1 if fatal or report["failed"] or not report["restored"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
