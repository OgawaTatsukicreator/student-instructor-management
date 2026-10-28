#!/usr/bin/env python3
"""Bounded, serial attack regressions for the student list in an owned test DB.

The real XAMPP site is never a valid target. A literal 127.0.0.1 URL with an
explicit private test port, test-router database headers, beta_security_test_*
database, exact synthetic fixtures and an explicit matching private @@datadir
are required before any probe or write. Common service ports are rejected
before any database connection or HTTP request.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import html
import json
import os
from pathlib import Path
import re
import sys
import urllib.parse

from security_http import Client, Database, PASSWORD, Suite, TABLES, TestFailure, verify_server_binding


MAX_REQUESTS = 80
FORBIDDEN_PORTS = frozenset((80, 443, 8080, 3306, 3307))


def validate_target(candidate: str | None) -> str:
    """Validate only URL text; this function never opens a socket or a DB."""
    if not isinstance(candidate, str) or not candidate or any(ord(character) <= 32 or ord(character) == 127 for character in candidate):
        raise TestFailure("An explicit private test URL without whitespace or control characters is required")
    try:
        url = urllib.parse.urlsplit(candidate)
        port = url.port
    except ValueError as error:
        raise TestFailure("The private test URL or port is invalid") from error
    if (url.scheme not in ("http", "https") or url.hostname != "127.0.0.1"
            or url.username is not None or url.password is not None or "?" in candidate or "#" in candidate):
        raise TestFailure("Only a literal loopback test URL without credentials, query or fragment is allowed")
    if port is None or port < 1 or port in FORBIDDEN_PORTS:
        raise TestFailure("An explicit private test port is required; common HTTP and database service ports are forbidden")
    path = url.path
    while True:
        if ".." in path.replace("\\", "/").split("/"):
            raise TestFailure("Path traversal is not allowed in a private test URL")
        decoded = urllib.parse.unquote(path)
        if decoded == path:
            break
        path = decoded
    return candidate.rstrip("/") + "/"


class ProbeClient(Client):
    requests = 0

    def request(self, *args, **kwargs):
        if ProbeClient.requests >= MAX_REQUESTS:
            raise TestFailure("The bounded serial HTTP request budget was exhausted")
        ProbeClient.requests += 1
        return super().request(*args, **kwargs)


def escaped(value: str) -> str:
    return html.escape(value, quote=True).replace("&#x27;", "&#039;")


def verify_owned_datadir(db: Database, expected: str | None):
    if not expected:
        raise TestFailure("An explicit expected private datadir is required")
    target = Path(expected).resolve()
    # Tests own a disposable data/ folder, never an installation's mysql/data.
    if target.name.lower() != "data" or not target.parent.name.lower().startswith(("betateam-", "beta-security-", "beta-ui-")):
        raise TestFailure("The expected datadir is not inside a named disposable test folder")
    encoded = db.sql("SELECT HEX(@@datadir)")
    if not re.fullmatch(r"[0-9A-Fa-f]+", encoded) or len(encoded) % 2:
        raise TestFailure("The database datadir response was invalid")
    raw = bytes.fromhex(encoded)
    candidates = []
    for encoding in ("utf-8", "cp932"):
        try:
            candidates.append(raw.decode(encoding))
        except UnicodeDecodeError:
            continue
    normalized = os.path.normcase(os.path.normpath(str(target)))
    if not any(os.path.normcase(os.path.normpath(value.rstrip("/\\"))) == normalized for value in candidates):
        raise TestFailure("The database is not the explicitly owned private datadir")


class StudentsSuite(Suite):
    def __init__(self, base, db):
        super().__init__(base, db)
        self.search_requests = {1: 0, 2: 0, 3: 0}

    def login(self, index):
        client = ProbeClient(self.base)
        token = client.token("login.php")
        self.status(client.request("login.php", {"csrf": token, "email": f"teacher{index}@example.invalid", "password": PASSWORD}), 303)
        return client

    @staticmethod
    def listed_ids(body, kind="student"):
        return set(map(int, re.findall(r'href="' + kind + r'_detail\.php\?id=([0-9]+)"', body)))

    def search(self, client, index, query, expected=200, expected_ids=None, *, path="students.php"):
        self.search_requests[index] += 1
        if self.search_requests[index] >= 20:
            raise TestFailure("Search probes must remain below twenty per account")
        response = self.unchanged(lambda: client.request(path + "?" + query), expected)
        if response[0] == 200 and expected_ids is not None:
            self.require(self.listed_ids(response[2], "teacher" if path == "teachers.php" else "student") == set(expected_ids), "Search returned unexpected record IDs")
        return response

    def run(self):
        admin, operator, general = self.login(1), self.login(2), self.login(3)
        original = self.db.snapshot()
        expected_ids = set(range(10001, 10011))
        for client, label in ((admin, "admin"), (operator, "operator"), (general, "general")):
            def all_students(c=client):
                response = self.unchanged(lambda: c.request("students.php"), 200)
                self.require(self.listed_ids(response[2]) == expected_ids, "Role could not view all ten fixture students")
            self.case(label + " may view every student by specification", all_students)
        self.case("general may view another instructor's student detail", lambda: self.unchanged(lambda: general.request("student_detail.php?id=10001"), 200))

        for label, value in (
            ("quote and boolean expression", "' OR 1=1 --"),
            ("UNION expression", "' UNION SELECT NULL --"),
            ("double quote expression", '\" OR \"1\"=\"1'),
            ("SQL comment", "/**/ OR 1=1"),
            ("percent wildcard", "%"),
            ("underscore wildcard", "_"),
        ):
            self.case("search treats SQL syntax literally: " + label,
                      lambda q=value: self.search(admin, 1, urllib.parse.urlencode({"q": q}), expected_ids=set()))

        def reflected_xss():
            payload = '<svg/onload=alert("search-probe")>'
            response = self.search(admin, 1, urllib.parse.urlencode({"q": payload}), expected_ids=set())
            self.require(escaped(payload) in response[2] and payload not in response[2], "Reflected search text was not escaped")
        self.case("reflected search HTML is escaped", reflected_xss)

        for label, query in (
            ("array", "q%5B%5D=value"), ("NUL", "q=bad%00value"),
            ("invalid UTF-8", "q=%FF%FE"), ("101 characters", urllib.parse.urlencode({"q": "あ" * 101})),
        ):
            self.case("invalid search rejected: " + label,
                      lambda q=query: self.search(admin, 1, q, 400))

        def duplicate_search(query, ids):
            # PHP may resolve the last scalar key, or the application may reject
            # duplicate keys. Neither behavior may alter SQL structure or data.
            response = self.search(admin, 1, query, {200, 400})
            if response[0] == 200:
                self.require(self.listed_ids(response[2]) == ids, "Duplicate search keys changed safe scalar semantics")
        self.case("HPP search cannot turn an injection into a filter bypass", lambda: duplicate_search(
            urllib.parse.urlencode([("q", "試験生徒"), ("q", "' OR 1=1 --")]), set()))
        self.case("HPP search follows a single validated scalar or rejects it", lambda: duplicate_search(
            urllib.parse.urlencode([("q", "no-student-match"), ("q", "試験生徒")]), expected_ids))

        for label, path, expected in (
            ("page array", "students.php?page%5B%5D=1", 400),
            ("page SQL expression", "students.php?page=1%20OR%201%3D1", 400),
            ("duplicate page ending in invalid ID", "students.php?page=1&page=0", 400),
            ("student ID array pollution", "student_detail.php?id=10001&id%5B%5D=10003", 400),
            ("missing student", "student_detail.php?id=999999", 404),
        ):
            self.case("identifier tampering: " + label, lambda p=path, e=expected: self.unchanged(lambda: general.request(p), e))
        def huge_page():
            response = self.unchanged(lambda: general.request("students.php?page=4294967295"), 200)
            self.require(self.listed_ids(response[2]) == expected_ids, "Huge page was not bounded to existing pages")
        self.case("huge valid page is clamped before a database offset", huge_page)

        general_token = general.token("students.php")
        for label, path, payload in (
            ("create", "student_create.php", {"last_name": "forbidden"}),
            ("edit", "student_edit.php?id=10003", {"version": "0", "notes": "forbidden"}),
            ("delete", "student_delete.php?id=10003", {"version": "0"}),
            ("unassigned notes", "student_notes.php?id=10001", {"version": "0", "notes": "forbidden"}),
        ):
            self.case("general forbidden write: " + label,
                      lambda p=path, d=dict(payload, csrf=general_token): self.unchanged(lambda: general.request(p, d), 403))
        self.case("operator cannot view an administrator's detail", lambda: self.unchanged(lambda: operator.request("teacher_detail.php?id=10001"), 404))
        self.case("operator cannot search an administrator into the visible list", lambda: self.search(
            operator, 2, urllib.parse.urlencode({"q": "teacher1@example.invalid"}), expected_ids=set(), path="teachers.php"))
        self.case("operator SQL syntax stays literal inside the teacher role filter", lambda: self.search(
            operator, 2, urllib.parse.urlencode({"q": "' OR 1=1 --"}), expected_ids=set(), path="teachers.php"))

        admin_token = admin.token("student_edit.php?id=10001")
        self.case("missing CSRF rejects student editing without changes", lambda: self.unchanged(
            lambda: admin.request("student_edit.php?id=10001", {"version": "0", "last_name": "forbidden"}), 403))
        self.case("foreign CSRF rejects assigned notes editing without changes", lambda: self.unchanged(
            lambda: general.request("student_notes.php?id=10003", {"csrf": admin_token, "version": "0", "notes": "forbidden"}), 403))

        def mass_assignment():
            before = self.db.snapshot()
            source = next(row for row in before["students"] if row["id"] == 10003)
            payload = {"csrf": general_token, "version": str(source["version"]), "notes": "Allowed notes only",
                       "last_name": "forbidden", "birth_date": "2000-01-01", "role": "admin", "email": "changed@example.invalid",
                       "teacher_ids[]": ["10007"], "credential_version": "999", "user_id": "10001"}
            try:
                response = general.request("student_notes.php?id=10003", payload)
                self.status(response, {303, 400, 422})
                after = self.db.snapshot()
                if response[0] == 303:
                    row = next(item for item in after["students"] if item["id"] == 10003)
                    self.require(row["notes"] == "Allowed notes only" and int(row["version"]) == int(source["version"]) + 1, "Allowed notes were not saved correctly")
                    for key in source:
                        if key not in ("notes", "version", "updated_at"):
                            self.require(row[key] == source[key], "Mass assignment changed a protected student field")
                    self.require([item for item in after["students"] if item["id"] != 10003]
                                 == [item for item in before["students"] if item["id"] != 10003],
                                 "Mass assignment changed another student's data")
                    for table in TABLES:
                        if table != "students":
                            self.require(after[table] == before[table], "Mass assignment changed teacher, assignment or pending-password data")
                else:
                    self.require(after == before, "Rejected mass-assignment request changed business data")
            finally:
                if self.db.snapshot() != before:
                    self.db.restore(before)
        self.case("assigned notes accept only permitted fields or reject mass assignment", mass_assignment)

        student_last = '<script>alert("student-last")</script>%_'
        student_first = '\"><img src=x onerror=alert("student-first")>'
        student_notes = '</textarea><script>alert("student-notes")</script>'
        teacher_last = '<svg onload=alert("teacher-last")>'
        teacher_first = '担当<script>alert(1)</script>'
        created_id = None

        def store_and_check():
            nonlocal created_id
            teacher = next(row for row in original["teachers"] if row["id"] == 10004)
            self.status(admin.request("teacher_edit.php?id=10004", {
                "csrf": admin_token, "version": str(teacher["version"]), "last_name": teacher_last, "first_name": teacher_first,
                "email": teacher["email"], "role": "general", "notes": teacher["notes"],
            }), 303)
            data = {"csrf": admin_token, "last_name": student_last, "first_name": student_first,
                    "birth_date": "2012-02-29", "notes": student_notes, "teacher_ids[]": ["10004"]}
            self.status(admin.request("student_create.php", data), 303)
            after = self.db.snapshot()
            ids = {row["id"] for row in after["students"]} - expected_ids
            self.require(len(ids) == 1, "Stored-XSS setup did not create exactly one synthetic student")
            created_id = ids.pop()
            source = next(row for row in original["students"] if row["id"] == 10001)
            self.status(admin.request("student_edit.php?id=10001", dict(data, version=str(source["version"]))), 303)
            # The only successful writes are the two intended students and one
            # teacher. Privileged account credentials and pending changes stay put.
            after = self.db.snapshot()
            for row in after["teachers"]:
                old = next(item for item in original["teachers"] if item["id"] == row["id"])
                for key in old:
                    if row["id"] != 10004 or key not in ("last_name", "first_name", "notes", "version", "updated_at"):
                        self.require(row[key] == old[key], "Stored-XSS setup unexpectedly changed protected teacher data")
            self.require(after["password_changes"] == original["password_changes"], "Stored-XSS setup changed pending-password data")
            for path in ("students.php", f"student_detail.php?id={created_id}", f"student_edit.php?id={created_id}"):
                response = admin.request(path)
                self.status(response, 200)
                for value in (student_last, student_first, student_notes, teacher_last, teacher_first):
                    self.require(escaped(value) in response[2] and value not in response[2], "A stored student or instructor value was not HTML-escaped")
            response = self.search(admin, 1, urllib.parse.urlencode({"q": "%_"}), expected_ids={10001, created_id})
            self.require(student_last not in response[2], "Literal-wildcard search emitted raw stored HTML")
        self.case("regular forms store text safely in list, detail, edit and literal wildcard search", store_and_check)

        # All successful fixture writes are restored even if a payload assertion
        # fails. This is the same independent snapshot used by the run-level guard.
        if self.db.snapshot() != original:
            self.db.restore(original)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=os.environ.get("BETATEAM_SECURITY_BASE_URL"))
    parser.add_argument("--expected-datadir", default=os.environ.get("BETATEAM_SECURITY_EXPECTED_DATADIR"))
    parser.add_argument("--server-kind", choices=("apache-cgi", "php-cli-server"), default="php-cli-server")
    args = parser.parse_args()
    report = {"executed_at": dt.datetime.now(dt.timezone.utc).isoformat(), "cases": [], "owned_database_verified": False,
              "http_binding_verified": False, "business_data_restored": False, "request_budget": MAX_REQUESTS}
    report["server_kind"] = args.server_kind
    report["runtime_scope"] = ("Isolated Apache CGI; .htaccess and encoded-path cookies are exercised. Shared mod_php is not exercised."
                               if args.server_kind == "apache-cgi" else "Isolated PHP development server; Apache dispatch is not exercised.")
    db = initial = suite = None
    fatal = False
    try:
        base = validate_target(args.base_url)
        db = Database()
        verify_owned_datadir(db, args.expected_datadir)
        report["owned_database_verified"] = True
        # The binding helper uses one ordinary Client request. Reserve it in
        # the same budget before calling it, including a failed binding probe.
        ProbeClient.requests += 1
        verify_server_binding(base, db)
        report["http_binding_verified"] = True
        candidate = db.snapshot()
        db.fixture_guard(candidate)
        initial = candidate
        # Reset only known synthetic login/search buckets after both ownership
        # guards. Never clear a production limiter or an unrelated rate bucket.
        keys = ["login-ip:127.0.0.1"] + [f"login-account:teacher{i}@example.invalid" for i in (1, 2, 3)] + [f"search-user:{10000+i}" for i in (1, 2, 3)]
        hashed = ",".join("'" + hashlib.sha256(key.encode()).hexdigest() + "'" for key in keys)
        db.sql("DELETE FROM rate_limits WHERE bucket_key IN (" + hashed + ")")
        report["private_synthetic_rate_buckets_reset"] = len(keys)
        suite = StudentsSuite(base, db)
        suite.run()
    except TestFailure as error:
        fatal = True
        report["failure"] = str(error)
    except Exception as error:
        fatal = True
        report["failure"] = "Test setup or execution failed [" + type(error).__name__ + "]"
    finally:
        if db is not None and initial is not None:
            try:
                if db.snapshot() != initial:
                    db.restore(initial)
                report["business_data_restored"] = db.snapshot() == initial
            except Exception:
                fatal = True
                report["failure"] = "Dedicated fixture restoration failed"
        if suite:
            report["cases"] = suite.results
            report["search_requests_by_synthetic_account"] = suite.search_requests
        report["http_requests"] = ProbeClient.requests
        report["total"] = len(report["cases"])
        report["passed"] = sum(case["passed"] for case in report["cases"])
        report["failed"] = report["total"] - report["passed"]
        report["complete"] = not fatal
        output = Path(__file__).resolve().parent.parent / "output" / "students-attack"
        output.mkdir(parents=True, exist_ok=True)
        (output / "report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"students-security: {report['passed']}/{report['total']} passed; requests={report['http_requests']}; restored={report['business_data_restored']}")
    if report.get("failure"):
        print(report["failure"], file=sys.stderr)
    return 1 if fatal or report["failed"] or not report["business_data_restored"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
