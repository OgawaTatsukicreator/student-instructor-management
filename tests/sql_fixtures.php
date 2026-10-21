<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// 実在の連絡先や個人情報を使わない、SQL試験専用のデータ。
function fixture_literal(string|int|null $value): string
{
    if ($value === null) { return 'NULL'; }
    if (is_int($value)) { return (string)$value; }
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}

/** @return array<string,list<string>> */
function sql_fixture_statements(): array
{
    $result = array_fill_keys(['teachers', 'students', 'student_teacher', 'rate_limits', 'password_changes'], []);
    $hash = password_hash('SqlTest123!', PASSWORD_DEFAULT);
    $roles = ['admin', 'operator', 'general', 'general', 'operator', 'general', 'admin', 'general', 'operator', 'general'];
    for ($i = 1; $i <= 10; $i++) {
        $id = 10000 + $i;
        $role = fixture_literal($roles[$i - 1]);
        $values = array_map('fixture_literal', [$id, '試験講師', '番号' . $i, "teacher{$i}@example.invalid", $hash]);
        $result['teachers'][] = 'INSERT INTO teachers (id,last_name,first_name,email,password,role_id,notes,must_change_password) VALUES ('
            . implode(',', $values) . ",(SELECT id FROM roles WHERE name={$role}),'SQL試験専用',1)";
        $values = array_map('fixture_literal', [$id, '試験生徒', '番号' . $i, $i === 1 ? '2012-02-29' : sprintf('2013-%02d-15', $i), $i === 1 ? null : ($i === 2 ? '' : 'テスト用の特記事項' . $i)]);
        $result['students'][] = 'INSERT INTO students (id,last_name,first_name,birth_date,notes) VALUES (' . implode(',', $values) . ')';
        // 10人全員に1名を割り当て、同じ講師が複数の生徒を担当する例を含める。
        $teacher = $i === 2 ? 10001 : $id;
        $result['student_teacher'][] = "INSERT INTO student_teacher (student_id,teacher_id) VALUES ({$id},{$teacher})";
        $bucket = fixture_literal(hash('sha256', 'beta-sql-test-bucket-' . $i));
        $result['rate_limits'][] = "INSERT INTO rate_limits (bucket_key,hits,window_started) VALUES ({$bucket},{$i},946684800)";
        $values = array_map('fixture_literal', [$id, hash('sha256', 'beta-sql-test-token-' . $i), hash('sha256', 'beta-sql-test-session-' . $i), "teacher{$i}@example.invalid", hash('sha256', 'unusable-test-code-' . $i), $hash, 0, 946684800]);
        $result['password_changes'][] = 'INSERT INTO password_changes (teacher_id,token,session_binding,email,code_hash,pending_hash,credential_version,expires_at) VALUES (' . implode(',', $values) . ')';
    }
    return $result;
}
