#!/usr/bin/env python3
"""Test the repository's .htaccess using a private, disposable Apache instance.

No PHP module, existing webroot, database, service command or installed Apache
configuration is used. Python CGI is enabled only inside the disposable probe
directory to prove that request bodies are actually consumed before checking the
64 KiB limit; a static handler's 405 response never counts as this proof.
"""
from __future__ import annotations

import argparse
import ctypes
import datetime as dt
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request


PREFIX = "betateam-apache-security-"
PUBLIC = b"BETATEAM_PUBLIC_STATIC_PROBE\n"
PRIVATE = b"BETATEAM_PRIVATE_SENTINEL_MUST_NEVER_BE_SERVED\n"


class ProbeFailure(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def apache_path(path: Path) -> str:
    """Use existing Windows short names when available, without changing paths."""
    path = path.resolve()
    result = str(path)
    if os.name == "nt":
        get_short = ctypes.windll.kernel32.GetShortPathNameW
        get_short.argtypes = (ctypes.c_wchar_p, ctypes.c_wchar_p, ctypes.c_uint)
        get_short.restype = ctypes.c_uint
        size = get_short(result, None, 0)
        if size:
            buffer = ctypes.create_unicode_buffer(size)
            if get_short(result, buffer, size):
                result = buffer.value
    return result.replace("\\", "/")


def quoted(path: Path) -> str:
    value = apache_path(path)
    if '"' in value or "\n" in value or "\r" in value:
        raise ProbeFailure("The probe path cannot be represented in an Apache configuration")
    return '"' + value + '"'


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def request(opener, base, path, *, body=None, method=None):
    headers = {"Content-Type": "application/octet-stream"} if body is not None else {}
    req = urllib.request.Request(base + path, data=body, headers=headers, method=method)
    try:
        response = opener.open(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read()


def create_probe(temporary: Path, server_root: Path, htaccess: Path, port: int):
    docroot = temporary / "htdocs"
    docroot.mkdir()
    shutil.copyfile(htaccess, docroot / ".htaccess")
    (docroot / "index.html").write_bytes(PUBLIC)
    (docroot / "assets").mkdir()
    (docroot / "assets" / "style.css").write_bytes(PUBLIC)
    blocked_directories = ("docs", "tests", "third_party", "runtime", "output", ".git")
    for name in blocked_directories:
        directory = docroot / name
        directory.mkdir()
        (directory / "sentinel.txt").write_bytes(PRIVATE)
    blocked_files = (
        "config.local.php", "config.local.php.bak", "config.php.old", "config.example.php", "config.php",
        "DB.sql", "dump.SQL", ".env.local", ".gitignore", "archive.zip", "backup.tar.gz", "server.log",
        "errors.php", "bootstrap.php", "db.php", "auth.php", "functions.php", "layout.php", "services.php",
        "mailer.php", "student_form.php", "teacher_form.php", "setup_admin.php", "setup_admin.ps1", "maintenance.php",
        "spec.md", "data.json", "config.ini", "dependency.lock", "source.phps", "swap.swp", "draft.tmp", "backup~",
    )
    for name in blocked_files:
        (docroot / name).write_bytes(PRIVATE)

    probe_directory = docroot / "body-probe"
    probe_directory.mkdir()
    interpreter = apache_path(Path(sys.executable))
    if " " in interpreter:
        # Apache's Windows shebang parser supports a quoted interpreter path.
        interpreter = '"' + interpreter + '"'
    script = "#!" + interpreter + "\n" + (
        "import os, sys\n"
        "size = int(os.environ.get('CONTENT_LENGTH', '0') or '0')\n"
        "payload = sys.stdin.buffer.read(size)\n"
        "body = ('BODY_READ:' + str(len(payload))).encode('ascii')\n"
        "sys.stdout.buffer.write(b'Content-Type: text/plain\\r\\nContent-Length: ' + str(len(body)).encode('ascii') + b'\\r\\n\\r\\n' + body)\n"
        "sys.stdout.buffer.flush()\n"
    )
    probe = probe_directory / "consume.cgi"
    probe.write_text(script, encoding="utf-8", newline="\n")
    if os.name != "nt":
        probe.chmod(0o700)

    modules = ("authz_core", "authz_host", "mime", "dir", "autoindex", "rewrite", "cgi")
    lines = [f"ServerRoot {quoted(server_root)}", f"Listen 127.0.0.1:{port}", "ServerName 127.0.0.1"]
    for name in modules:
        module = server_root / "modules" / ("mod_" + name + ".so")
        if not module.is_file():
            raise ProbeFailure("A required Apache probe module is unavailable: " + name)
        lines.append(f"LoadModule {name}_module {quoted(module)}")
    lines += [
        f"PidFile {quoted(temporary / 'httpd.pid')}", f"ErrorLog {quoted(temporary / 'error.log')}",
        "LogLevel warn", "ServerTokens Prod", "ServerSignature Off", "EnableSendfile Off", "EnableMMAP Off",
        f"TypesConfig {quoted(server_root / 'conf' / 'mime.types')}",
        f"DocumentRoot {quoted(docroot)}", "DirectoryIndex index.html", "AllowEncodedSlashes Off",
        '<Directory "/">', "AllowOverride None", "Require all denied", "</Directory>",
        f"<Directory {quoted(docroot)}>", "Options FollowSymLinks", "AllowOverride All", "Require all granted", "</Directory>",
        f"<Directory {quoted(probe_directory)}>", "Options +ExecCGI", "AddHandler cgi-script .cgi", "</Directory>",
    ]
    if os.name == "nt":
        lines += ["ThreadsPerChild 16", "AcceptFilter http none"]
    configuration = temporary / "httpd-probe.conf"
    configuration.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")
    return configuration, blocked_directories, blocked_files


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--httpd", default="C:/xampp/apache/bin/httpd.exe")
    parser.add_argument("--server-root", default="C:/xampp/apache")
    parser.add_argument("--temp-parent", help="Optional existing parent for the disposable probe folder")
    args = parser.parse_args()
    report = {"executed_at": dt.datetime.now(dt.timezone.utc).isoformat(), "cases": [], "configuration_valid": False,
              "php_loaded": False, "existing_services_modified": False, "temporary_files_removed": False}
    temporary = process = capture = None
    parent = Path(args.temp_parent).resolve() if args.temp_parent else Path(tempfile.gettempdir()).resolve()
    fatal = False

    def case(name, work):
        try:
            details = work()
            result = {"name": name, "passed": True}
            if details:
                result.update(details)
        except ProbeFailure as error:
            result = {"name": name, "passed": False, "failure": str(error)}
        except Exception as error:
            result = {"name": name, "passed": False, "failure": "Probe execution failed [" + type(error).__name__ + "]"}
        report["cases"].append(result)
        print(("PASS " if result["passed"] else "FAIL ") + name, flush=True)

    try:
        executable = Path(args.httpd).resolve()
        server_root = Path(args.server_root).resolve()
        htaccess = Path(__file__).resolve().parent.parent / ".htaccess"
        if not executable.is_file() or not server_root.is_dir() or not htaccess.is_file() or not parent.is_dir():
            raise ProbeFailure("The Apache binary, module root, .htaccess and temporary parent must exist")
        temporary = Path(tempfile.mkdtemp(prefix=PREFIX, dir=parent)).resolve()
        port = free_port()
        configuration, directories, files = create_probe(temporary, server_root, htaccess, port)
        flags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
        checked = subprocess.run([str(executable), "-t", "-f", apache_path(configuration)], capture_output=True, timeout=15, creationflags=flags)
        if checked.returncode:
            # This output concerns only our generated config and module paths.
            report["configuration_diagnostic"] = (checked.stdout + checked.stderr).decode("utf-8", "replace")[-2000:]
            raise ProbeFailure("The disposable Apache configuration failed its syntax check")
        report["configuration_valid"] = True
        capture = (temporary / "console.log").open("wb")
        process = subprocess.Popen([str(executable), "-X", "-f", apache_path(configuration)], stdout=capture, stderr=capture, creationflags=flags)
        base = f"http://127.0.0.1:{port}/"
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
        deadline = time.monotonic() + 12
        while True:
            if process.poll() is not None:
                raise ProbeFailure("The disposable Apache process exited during startup")
            try:
                status, body = request(opener, base, "index.html")
                if status == 200 and body == PUBLIC:
                    break
            except (OSError, urllib.error.URLError):
                pass
            if time.monotonic() >= deadline:
                raise ProbeFailure("The disposable loopback Apache did not become ready")
            time.sleep(0.1)

        def allowed(path):
            status, body = request(opener, base, path)
            if status != 200 or body != PUBLIC:
                raise ProbeFailure(f"Expected the public sentinel with HTTP 200; received {status}")
            return {"status": status}

        def blocked(path, statuses=(403,), method=None):
            status, body = request(opener, base, path, method=method)
            if status not in statuses or PRIVATE in body:
                raise ProbeFailure(f"Expected blocked response {statuses}; received {status}")
            return {"status": status}

        for path in ("index.html", "", "assets/style.css"):
            case("public static: /" + path, lambda p=path: allowed(p))
        for directory in directories:
            for path in (directory + "/sentinel.txt", directory.upper() + "/sentinel.txt"):
                case("private directory: /" + path, lambda p=path: blocked(p))
        for filename in files:
            case("private file: /" + filename, lambda p=filename: blocked(p))
        for path in ("d%6fcs/sentinel.txt", "%2egit/sentinel.txt", "%2Egit/sentinel.txt", "%63onfig.local.php", "config.local.php.%62ak", "dump.%53QL", "%2eenv.local", "archive.%7Aip", "ERRORS.PHP", "CONFIG.LOCAL.PHP.BAK"):
            case("encoded or mixed case: /" + path, lambda p=path: blocked(p))
        for path in ("docs%2fsentinel.txt", "docs%2Fsentinel.txt", "docs%5csentinel.txt"):
            case("encoded separator cannot expose private data: /" + path, lambda p=path: blocked(p, (400, 403, 404)))
        case("HEAD cannot expose a private file", lambda: blocked("config.local.php", method="HEAD"))
        case("dot access file cannot be downloaded", lambda: blocked(".htaccess"))
        case("directory listing is denied", lambda: blocked("assets/"))

        def small_body():
            status, body = request(opener, base, "body-probe/consume.cgi", body=b"a" * 128)
            if status != 200 or body != b"BODY_READ:128":
                raise ProbeFailure(f"The CGI must consume a small POST with HTTP 200; received {status}")
            return {"status": status, "bytes_consumed": 128}

        def boundary_body():
            status, body = request(opener, base, "body-probe/consume.cgi", body=b"a" * 65536)
            if status != 200 or body != b"BODY_READ:65536":
                raise ProbeFailure(f"The CGI must consume a 65536-byte POST with HTTP 200; received {status}")
            return {"status": status, "bytes_consumed": 65536}

        def oversized_body():
            status, body = request(opener, base, "body-probe/consume.cgi", body=b"a" * 65537)
            if status != 413 or b"BODY_READ:65537" in body:
                raise ProbeFailure(f"A 65537-byte POST must be rejected with HTTP 413; received {status}")
            return {"status": status, "bytes_rejected": 65537}

        case("request body probe consumes a small POST", small_body)
        case("request body boundary accepts 64 KiB", boundary_body)
        case("request body over 64 KiB returns 413", oversized_body)
    except ProbeFailure as error:
        fatal = True
        report["failure"] = str(error)
    except Exception as error:
        fatal = True
        report["failure"] = "Apache probe setup failed [" + type(error).__name__ + "]"
    finally:
        if process is not None and process.poll() is None:
            # Only terminate the exact subprocess created by this invocation.
            process.terminate()
            try:
                process.wait(timeout=8)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
        if capture is not None:
            capture.close()
        if temporary is not None:
            if fatal:
                diagnostics = temporary / "error.log"
                if not diagnostics.is_file():
                    diagnostics = temporary / "console.log"
                if diagnostics.is_file():
                    report["startup_diagnostic"] = diagnostics.read_text(encoding="utf-8", errors="replace")[-2000:]
            target = temporary.resolve()
            if target.parent != parent or not target.name.startswith(PREFIX) or target == parent:
                fatal = True
                report["failure"] = "Temporary cleanup target failed its safety guard"
            else:
                try:
                    shutil.rmtree(target)
                    report["temporary_files_removed"] = not target.exists()
                except OSError as error:
                    fatal = True
                    report["cleanup_failure"] = "Temporary cleanup failed [" + type(error).__name__ + "]"
        report["total"] = len(report["cases"])
        report["passed"] = sum(case["passed"] for case in report["cases"])
        report["failed"] = report["total"] - report["passed"]
        report["complete"] = not fatal
        output = Path(__file__).resolve().parent.parent / "output"
        output.mkdir(exist_ok=True)
        (output / "apache-security.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"apache-security: {report['passed']}/{report['total']} passed; temp removed={report['temporary_files_removed']}; output/apache-security.json")
    if report.get("failure"):
        print(report["failure"], file=sys.stderr)
    return 1 if fatal or report["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(run())
