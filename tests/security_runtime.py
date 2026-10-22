#!/usr/bin/env python3
"""Exercise PHP failure handling in disposable, DB-free shadow applications.

Example:
  python tests/security_runtime.py --php ABSOLUTE/php.exe --ini ABSOLUTE/php.ini

Only the script's own loopback servers and temporary directories are modified.
The fault router is generated under the OS temporary directory, never in the app.
"""
from __future__ import annotations

import argparse
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlencode


REPOSITORY = Path(__file__).resolve().parent.parent
FORBIDDEN_PORTS = {33318, 33319, 33321}
SECRET = "BETA_PRIVATE_MARKER_9d172e6f_password_otp_cookie"
PARTIAL = "BETA_PARTIAL_OUTPUT_SHOULD_DISAPPEAR"
PATCHED_BRANCHES = {802: 80235, 803: 80336, 804: 80427, 805: 80512}

ROUTER = r'''<?php
declare(strict_types=1);
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($route === '/health') { echo 'ready'; return; }
$fault = trim($route, '/');
// The built-in server otherwise resolves extensionless routes to index.php,
// whose real contract correctly prohibits POST. Model a normal form route.
$_SERVER['SCRIPT_NAME'] = '/login.php';
if ($fault === 'session_write') {
    class FailingSessionWrite implements SessionHandlerInterface {
        public function open(string $path, string $name): bool { return true; }
        public function close(): bool { return true; }
        public function read(string $id): string|false { return ''; }
        public function write(string $id, string $data): bool { return false; }
        public function destroy(string $id): bool { return true; }
        public function gc(int $max_lifetime): int|false { return 0; }
    }
    session_set_save_handler(new FailingSessionWrite(), false);
}
require __DIR__ . '/bootstrap.php';
$secret = 'BETA_PRIVATE_MARKER_9d172e6f_password_otp_cookie';
switch ($fault) {
case 'runtime': case 'log_blocked':
    throw new RuntimeException($secret);
case 'type':
    strlen([]);
    break;
case 'warning':
    trigger_error($secret, E_USER_WARNING);
    break;
case 'pdo_2002': case 'pdo_1205': case 'pdo_1146':
    $error = new PDOException($secret);
    $error->errorInfo = ['HY000', (int)substr($fault, 4), $secret];
    throw $error;
case 'partial':
    echo '<section>BETA_PARTIAL_OUTPUT_SHOULD_DISAPPEAR' . $secret . '</section>';
    throw new RuntimeException($secret);
case 'session_write':
    $_SESSION['fault_probe'] = $secret;
    echo 'BETA_PARTIAL_OUTPUT_SHOULD_DISAPPEAR';
    break;
case 'shutdown_fatal':
    echo 'BETA_PARTIAL_OUTPUT_SHOULD_DISAPPEAR';
    ini_set('memory_limit', '8M');
    $allocation = str_repeat('x', 64 * 1024 * 1024);
    break;
case 'logout_unavailable':
    $_SESSION['user_id'] = 1;
    $_SESSION['auth_nonce'] = $secret;
    end_login();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['user_id_cleared' => !isset($_SESSION['user_id']),
        'session_empty' => $_SESSION === []]);
    break;
case 'session_idle': case 'session_absolute':
    $_SESSION['user_id'] = 1;
    $_SESSION['auth_nonce'] = $secret;
    $_SESSION['authenticated_at'] = time() - ($fault === 'session_absolute' ? 28801 : 0);
    $_SESSION['last_activity'] = time() - ($fault === 'session_idle' ? 1801 : 0);
    $current = current_user();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['current_user_null' => $current === null,
        'user_id_cleared' => !isset($_SESSION['user_id']),
        'auth_nonce_cleared' => !isset($_SESSION['auth_nonce']),
        'flash' => $_SESSION['flash'] ?? '']);
    break;
case 'config_type': case 'config_syntax': case 'session_path':
    throw new LogicException('The expected bootstrap failure did not occur');
default:
    http_response_code(404);
    echo 'unknown temporary test route';
}
'''


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def choose_port() -> int:
    while True:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]
        if port not in FORBIDDEN_PORTS:
            return port


def request(port: int, route: str, post: bool = False) -> tuple[int, dict, str]:
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=12)
    headers = {"Cookie": "fault_cookie=" + SECRET, "X-Test-Private": SECRET}
    body = None
    if post:
        body = urlencode({"password": SECRET, "code": SECRET, "notes": SECRET})
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    try:
        connection.request("POST" if post else "GET", route, body=body, headers=headers)
        response = connection.getresponse()
        data = response.read(2 * 1024 * 1024).decode("utf-8", errors="replace")
        return response.status, {name.lower(): value for name, value in response.getheaders()}, data
    finally:
        connection.close()


def safe_remove_temporary_tree(path: Path, temporary_parent: Path) -> None:
    resolved = path.resolve()
    if (resolved.parent != temporary_parent.resolve()
            or not resolved.name.startswith("betateam-security-runtime-")
            or resolved != path.absolute()):
        raise RuntimeError("Refusing to remove an unexpected temporary directory")
    if resolved.exists():
        shutil.rmtree(resolved)


def read_records(text: str) -> list[dict]:
    records = []
    for line in text.splitlines():
        start = line.find('{"time":')
        if start < 0:
            continue
        try:
            record = json.loads(line[start:])
        except json.JSONDecodeError:
            continue
        if isinstance(record, dict) and "reference" in record and "event" in record:
            records.append(record)
    return records


def run_case(case: str, status: int, php: Path, ini: Path | None,
             temporary: Path, source: Path, db_port: int) -> dict:
    app = temporary / case
    app.mkdir()
    for file in source.glob("*.php"):
        shutil.copy2(file, app / file.name)
    sessions = app / "sessions"
    sessions.mkdir()
    config = "<?php return ['db'=>['host'=>'127.0.0.1','port'=>" + str(db_port)
    config += ",'name'=>'beta_security_test_fault','user'=>'security_test','password'=>"
    config += php_string(SECRET) + "]];\n"
    if case == "config_type":
        config = "<?php return " + php_string(SECRET) + ";\n"
    elif case == "config_syntax":
        config = "<?php /* " + SECRET + " */ $invalid = ;\n"
    (app / "config.local.php").write_text(config, encoding="utf-8")
    if case == "session_path":
        sessions = app / "not-a-session-directory"
        sessions.write_text("This file intentionally blocks session storage", encoding="utf-8")
    if case == "log_blocked":
        (app / "runtime").write_text("This file intentionally blocks application logging", encoding="utf-8")
    (app / "fault-router.php").write_text(ROUTER, encoding="utf-8")
    port = choose_port()
    command = [str(php)]
    if ini:
        command += ["-c", str(ini)]
    command += ["-d", "session.save_path=" + sessions.as_posix(),
                "-d", "error_log=" + (app / "native-php.log").as_posix(),
                "-d", "display_errors=0", "-d", "log_errors=1",
                "-S", "127.0.0.1:" + str(port), "-t", str(app), str(app / "fault-router.php")]
    environment = os.environ.copy()
    environment.pop("PHP_CLI_SERVER_WORKERS", None)
    server = None
    response = None
    startup_error = None
    output = app / "server.log"
    try:
        with output.open("wb") as server_log:
            server = subprocess.Popen(command, cwd=str(php.parent), env=environment,
                                      stdin=subprocess.DEVNULL, stdout=server_log, stderr=server_log)
            deadline = time.monotonic() + 10
            while time.monotonic() < deadline:
                if server.poll() is not None:
                    raise RuntimeError("Temporary PHP server exited during startup")
                try:
                    if request(port, "/health")[0] == 200:
                        break
                except (OSError, http.client.HTTPException):
                    pass
                time.sleep(0.05)
            else:
                raise RuntimeError("Temporary PHP server did not start")
            response = request(port, "/" + case, post=True)
    except (OSError, RuntimeError, http.client.HTTPException) as error:
        startup_error = type(error).__name__
    finally:
        if server is not None and server.poll() is None:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
    assertions = []

    def check(condition: bool, label: str) -> None:
        assertions.append({"check": label, "passed": bool(condition)})

    if response is None:
        check(False, "temporary_server_request_completed_" + str(startup_error))
        return {"case": case, "passed": False, "assertions": assertions}
    actual_status, headers, body = response
    log_path = app / "runtime" / "application.log"
    application_log = log_path.read_text(encoding="utf-8", errors="replace") if log_path.is_file() else ""
    server_text = output.read_text(encoding="utf-8", errors="replace")
    records = read_records(application_log if case != "log_blocked" else server_text)
    check(actual_status == status, "expected_http_status")
    check(SECRET not in body, "private_inputs_and_exception_message_absent_from_response")
    check(SECRET not in application_log, "private_inputs_and_exception_message_absent_from_application_log")
    check(PARTIAL not in body, "partial_output_discarded")
    if status >= 500:
        reference_match = re.search(r"問い合わせID：([0-9a-f]{16})", body)
        reference = reference_match.group(1) if reference_match else None
        check(reference is not None, "safe_response_has_reference")
        check(body.lower().count("<!doctype html>") == 1, "one_complete_error_page")
        check("<script" not in body.lower(), "no_executable_script_in_error_page")
        check(headers.get("cache-control") == "no-store, private", "error_response_not_cached")
        check(headers.get("x-content-type-options") == "nosniff", "content_type_sniffing_disabled")
        matched = [record for record in records
                   if record.get("reference") == reference and record.get("status") == status]
        check(bool(matched), "reference_matches_safe_diagnostic_record")
        check(all(SECRET not in json.dumps(record) for record in records), "diagnostic_records_contain_no_private_marker")
        if status == 503:
            check(headers.get("retry-after") == "60", "unavailable_response_has_retry_after")
        if case == "log_blocked":
            check(len(body) < 10000 and bool(matched), "log_failure_falls_back_without_recursion")
        if case == "shutdown_fatal":
            check(any(record.get("exception") == "ErrorException" for record in matched),
                  "fatal_shutdown_handler_recorded_failure")
    else:
        try:
            result = json.loads(body)
        except json.JSONDecodeError:
            result = {}
        if case in {"session_idle", "session_absolute"}:
            check(result.get("current_user_null") is True
                  and result.get("user_id_cleared") is True
                  and result.get("auth_nonce_cleared") is True,
                  "expired_session_clears_authentication_without_reading_user_row")
            expected_flash = ("時間が経過したため、もう一度ログインしてください。" if case == "session_idle"
                              else "ログインから8時間が経過したため、もう一度ログインしてください。")
            check(result.get("flash") == expected_flash, "expired_session_explains_required_login")
        else:
            check(result.get("user_id_cleared") is True and result.get("session_empty") is True,
                  "logout_clears_session_despite_db_unavailable")
        check(any(record.get("event") == "logout.cleanup" for record in records),
              "logout_cleanup_failure_is_logged")
    return {"case": case, "expected_status": status, "actual_status": actual_status,
            "passed": all(item["passed"] for item in assertions), "assertions": assertions,
            "diagnostic_events": [record.get("event") for record in records]}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", type=Path, required=True, help="Absolute path to patched PHP CLI executable")
    parser.add_argument("--ini", type=Path, help="Optional dedicated PHP configuration")
    arguments = parser.parse_args()
    if not arguments.php.is_absolute() or not arguments.php.is_file():
        parser.error("--php must identify an existing absolute PHP executable")
    php = arguments.php.resolve()
    ini = arguments.ini.resolve() if arguments.ini else None
    if ini is not None and not ini.is_file():
        parser.error("--ini must identify an existing configuration")
    version_command = [str(php)] + (["-c", str(ini)] if ini else [])
    version_command += ["-r", "echo json_encode(['version'=>PHP_VERSION,'id'=>PHP_VERSION_ID]);"]
    try:
        completed = subprocess.run(version_command, cwd=str(php.parent), check=True,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
        version = json.loads(completed.stdout)
        version_id = int(version["id"])
        threshold = PATCHED_BRANCHES.get(version_id // 100)
        if (threshold is None or version_id < threshold
                or re.fullmatch(r"\d+\.\d+\.\d+", version["version"]) is None):
            parser.error("Refusing fatal-memory tests on unpatched or unverified PHP branches; "
                         "use PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or a later stable patch in the same branch")
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        parser.error("Could not verify the requested PHP runtime")
    temporary_parent = Path(tempfile.gettempdir()).resolve()
    temporary = Path(tempfile.mkdtemp(prefix="betateam-security-runtime-", dir=temporary_parent)).resolve()
    cases = [("runtime", 500), ("type", 500), ("warning", 500),
             ("pdo_2002", 503), ("pdo_1205", 503), ("pdo_1146", 500),
             ("partial", 500), ("config_type", 500), ("config_syntax", 500),
             ("session_path", 503), ("session_write", 503), ("log_blocked", 500),
             ("logout_unavailable", 200), ("session_idle", 200), ("session_absolute", 200),
             ("shutdown_fatal", 500)]
    results = []
    try:
        snapshot = temporary / "source"
        snapshot.mkdir()
        for file in REPOSITORY.glob("*.php"):
            if file.name != "config.local.php":
                shutil.copy2(file, snapshot / file.name)
        # Bind without listening: no real MySQL server can acquire this port.
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as unavailable_db:
            unavailable_db.bind(("127.0.0.1", 0))
            db_port = unavailable_db.getsockname()[1]
            if db_port in FORBIDDEN_PORTS:
                raise RuntimeError("Reserved test port unexpectedly selected")
            for name, expected in cases:
                result = run_case(name, expected, php, ini, temporary, snapshot, db_port)
                results.append(result)
                print(("PASS" if result["passed"] else "FAIL") + " " + name, flush=True)
                for assertion in result["assertions"]:
                    if not assertion["passed"]:
                        print("  " + assertion["check"], flush=True)
    finally:
        safe_remove_temporary_tree(temporary, temporary_parent)
    report = {"php_version": version["version"], "database_access": "connection_refused_only",
              "application_database_name": "beta_security_test_fault", "smtp_used": False,
              "real_xampp_changed": False, "temporary_tree_cleaned": not temporary.exists(),
              "cases_passed": sum(item["passed"] for item in results), "cases_total": len(results),
              "assertions_passed": sum(assertion["passed"] for item in results for assertion in item["assertions"]),
              "assertions_total": sum(len(item["assertions"]) for item in results), "cases": results}
    output = REPOSITORY / "output"
    output.mkdir(exist_ok=True)
    (output / "security-runtime.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n",
                                                  encoding="utf-8")
    print(f"runtime security: {report['cases_passed']}/{report['cases_total']} cases, "
          f"{report['assertions_passed']}/{report['assertions_total']} assertions")
    return 0 if len(results) == len(cases) and all(item["passed"] for item in results) else 1


if __name__ == "__main__":
    sys.exit(main())
