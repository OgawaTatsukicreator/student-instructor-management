#!/usr/bin/env python3
"""Offline URL-guard regressions: no HTTP requests or database connections."""
from __future__ import annotations

import unittest
from unittest.mock import patch

from students_security import Client, MAX_REQUESTS, ProbeClient, TestFailure, validate_target


REJECTED_TARGETS = (
    ("real_localhost_site", "http://localhost:8080/%ce%b2team/students.php"),
    ("real_loopback_port", "http://127.0.0.1:8080/%ce%b2team/"),
    ("implicit_http_port", "http://127.0.0.1/"),
    ("implicit_https_port", "https://127.0.0.1/"),
    ("explicit_http_service_port", "http://127.0.0.1:80/"),
    ("explicit_https_service_port", "https://127.0.0.1:443/"),
    ("mysql_service_port", "http://127.0.0.1:3306/"),
    ("xampp_database_port", "http://127.0.0.1:3307/"),
    ("external_hostname", "http://example.invalid:33620/"),
    ("synthetic_credentials", "http://synthetic:synthetic@127.0.0.1:33620/"),
    ("empty_username", "http://@127.0.0.1:33620/"),
    ("query", "http://127.0.0.1:33620/?q=one"),
    ("fragment", "http://127.0.0.1:33620/#main"),
    ("literal_parent_segment", "http://127.0.0.1:33620/a/../"),
    ("encoded_parent_segment", "http://127.0.0.1:33620/%2e%2e/"),
    ("double_encoded_parent_segment", "http://127.0.0.1:33620/%252e%252e/"),
    ("encoded_windows_parent_segment", "http://127.0.0.1:33620/a%5c..%5cb/"),
    ("out_of_range_port", "http://127.0.0.1:99999/"),
    ("nonnumeric_port", "http://127.0.0.1:invalid/"),
    ("leading_whitespace", " http://127.0.0.1:33620/"),
    ("control_character", "http://127.0.0.1:33620/\n"),
    ("missing_target", None),
    ("zero_port", "http://127.0.0.1:0/"),
    ("deep_encoded_parent_segment", "http://127.0.0.1:33620/%2525252e%2525252e/"),
)


class TargetGuardTests(unittest.TestCase):
    def setUp(self):
        # Future guard changes must not silently turn these unit tests into
        # network or database probes.
        self.stack = []
        for name in ("students_security.Database", "students_security.Client.request", "socket.create_connection"):
            guard = patch(name, side_effect=AssertionError("Offline URL test attempted I/O"))
            self.stack.append(guard)
            guard.start()
            self.addCleanup(guard.stop)

    def test_allow_explicit_private_server_with_lowercase_escaped_folder(self):
        target = "http://127.0.0.1:33620/%ce%b2team/"
        self.assertEqual(validate_target(target), target)


class RequestBudgetTests(unittest.TestCase):
    def test_binding_probe_and_following_requests_share_the_limit(self):
        previous = ProbeClient.requests
        self.addCleanup(setattr, ProbeClient, "requests", previous)
        # Reserve the first binding GET just as main() does. The transport is
        # mocked, so reaching the boundary cannot send any network traffic.
        ProbeClient.requests = 1
        with patch.object(Client, "request", return_value=(200, {}, "")) as transport:
            client = ProbeClient("http://127.0.0.1:33620/")
            for _ in range(MAX_REQUESTS - 1):
                client.request("students.php")
            with self.assertRaises(TestFailure):
                client.request("students.php")
            self.assertEqual(transport.call_count, MAX_REQUESTS - 1)
            self.assertEqual(ProbeClient.requests, MAX_REQUESTS)


def rejection_test(target):
    def test(self):
        with self.assertRaises(TestFailure):
            validate_target(target)
    return test


# Separate named URL cases keep the boundary inputs readable in one list.
for case_name, case_target in REJECTED_TARGETS:
    setattr(TargetGuardTests, "test_reject_" + case_name, rejection_test(case_target))


if __name__ == "__main__":
    unittest.main(verbosity=2)
