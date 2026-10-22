<?php
declare(strict_types=1);
function student_row(int $id): array {
    $row = query('SELECT * FROM students WHERE id=?', [$id])->fetch();
    if (!$row) { page_error(404, '指定された生徒情報は見つかりません。'); }
    return $row;
}
function student_teachers(int $id): array {
    return query('SELECT t.id,t.last_name,t.first_name FROM teachers t JOIN student_teacher st ON st.teacher_id=t.id WHERE st.student_id=? ORDER BY t.id', [$id])->fetchAll();
}
function teacher_options(): array {
    // 担当選択に必要なIDと氏名だけを取得。operatorへadminの詳細を渡さない。
    return query('SELECT id,last_name,first_name FROM teachers ORDER BY id')->fetchAll();
}
function student_data(): array {
    return ['last_name'=>trim(post_text('last_name')), 'first_name'=>trim(post_text('first_name')), 'birth_date'=>post_text('birth_date'), 'notes'=>post_text('notes')];
}
function selected_teacher_ids(array &$errors): array {
    $raw = $_POST['teacher_ids'] ?? [];
    if (!is_array($raw) || count($raw) > 1000) { $errors['teacher_ids'] = '担当講師を正しく選択してください。'; return []; }
    $ids = [];
    foreach ($raw as $value) {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int)$value > 4294967295) { $errors['teacher_ids'] = '担当講師を正しく選択してください。'; return []; }
        $ids[] = (int)$value;
    }
    $ids = array_values(array_unique($ids)); sort($ids);
    if (!$ids) { $errors['teacher_ids'] = '担当講師を1名以上選択してください。'; }
    return $ids;
}
function student_errors(array $data): array {
    $errors = common_fields($data, false);
    $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$data['birth_date']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $data['birth_date']) : false;
    if (!$date || $date->format('Y-m-d') !== $data['birth_date'] || $date->format('Y') < '1000' || $date > new DateTimeImmutable('today')) { $errors['birth_date'] = '生年月日は1000年以降、今日以前の実在する日付を入力してください。'; }
    return $errors;
}
function save_student(?int $id, array $data, array $teacher_ids, ?int $version, bool $notes_only = false): int {
    return transaction(function () use ($id,$data,$teacher_ids,$version,$notes_only): int {
        management_lock();
        $actor = fresh_actor($notes_only ? ['admin','operator','general'] : ['admin','operator']);
        if ($id !== null) {
            $row = query('SELECT * FROM students WHERE id=? FOR UPDATE', [$id])->fetch();
            if (!$row) { throw new FormError('この生徒情報は削除されています。一覧へ戻ってください。', 404); }
            if ($notes_only && $actor['role'] === 'general' && !assigned_to($id,(int)$actor['id'])) { throw new FormError('担当が変更されたため、この生徒の特記事項は編集できません。', 403); }
            if ((int)$row['version'] !== $version) { throw new FormError('他のユーザーが更新しました。画面を開き直して内容を確認してください。'); }
        }
        if ($notes_only) { query('UPDATE students SET notes=?,version=version+1 WHERE id=?', [$data['notes'],$id]); return $id; }
        $marks = implode(',', array_fill(0,count($teacher_ids),'?'));
        if (!$teacher_ids || (int)query("SELECT COUNT(*) FROM teachers WHERE id IN ($marks)", $teacher_ids)->fetchColumn() !== count($teacher_ids)) { throw new FormError('選択した担当講師が見つかりません。画面を開き直してください。'); }
        if ($id === null) {
            query('INSERT INTO students (last_name,first_name,birth_date,notes) VALUES (?,?,?,?)', [$data['last_name'],$data['first_name'],$data['birth_date'],$data['notes']]);
            $id = (int)db()->lastInsertId();
        } else {
            query('UPDATE students SET last_name=?,first_name=?,birth_date=?,notes=?,version=version+1 WHERE id=?', [$data['last_name'],$data['first_name'],$data['birth_date'],$data['notes'],$id]);
            query('DELETE FROM student_teacher WHERE student_id=?', [$id]);
        }
        foreach ($teacher_ids as $teacher_id) { query('INSERT INTO student_teacher (student_id,teacher_id) VALUES (?,?)', [$id,$teacher_id]); }
        return $id;
    });
}

function teacher_row(int $id, array $viewer): array {
    $filter = $viewer['role'] === 'operator' ? " AND r.name IN ('operator','general')" : '';
    $row = query('SELECT t.id,t.last_name,t.first_name,t.email,t.notes,t.role_id,t.version,r.name AS role FROM teachers t JOIN roles r ON r.id=t.role_id WHERE t.id=?' . $filter,[$id])->fetch();
    if (!$row) { page_error(404,'指定された講師情報は見つかりません。'); }
    return $row;
}
function teacher_data(): array {
    return ['last_name'=>trim(post_text('last_name')), 'first_name'=>trim(post_text('first_name')),
        'email'=>strtolower(trim(post_text('email'))), 'role'=>post_text('role'), 'notes'=>post_text('notes')];
}
function save_teacher(?int $id, array $data, ?int $version, ?string $initial_hash): int {
    try {
        $own_credential = null;
        $saved_id = transaction(function () use ($id,$data,$version,$initial_hash,&$own_credential): int {
            management_lock(); $actor = fresh_actor(['admin']);
            $role_id = query('SELECT id FROM roles WHERE name=?',[$data['role']])->fetchColumn();
            if (!$role_id) { throw new FormError('権限を正しく選択してください。'); }
            if ($id === null) {
                query('INSERT INTO teachers (last_name,first_name,email,role_id,notes,password) VALUES (?,?,?,?,?,?)',[$data['last_name'],$data['first_name'],$data['email'],$role_id,$data['notes'],$initial_hash]);
                return (int)db()->lastInsertId();
            }
            $row = query('SELECT t.*,r.name AS role FROM teachers t JOIN roles r ON r.id=t.role_id WHERE t.id=? FOR UPDATE',[$id])->fetch();
            if (!$row) { throw new FormError('この講師情報は削除されています。', 404); }
            if ((int)$row['version'] !== $version) { throw new FormError('他のユーザーが更新しました。画面を開き直してください。'); }
            if ((int)$actor['id'] === $id && $data['role'] !== $row['role']) { throw new FormError('ご自身の権限は変更できません。'); }
            if ($row['role'] === 'admin' && $data['role'] !== 'admin' && (int)query("SELECT COUNT(*) FROM teachers t JOIN roles r ON r.id=t.role_id WHERE r.name='admin'")->fetchColumn() <= 1) { throw new FormError('最後の管理者の権限は変更できません。'); }
            $credentials_changed = $row['email'] !== $data['email'] || $row['role'] !== $data['role'];
            query('UPDATE teachers SET last_name=?,first_name=?,email=?,role_id=?,notes=?,version=version+1,credential_version=credential_version+? WHERE id=?',[$data['last_name'],$data['first_name'],$data['email'],$role_id,$data['notes'],(int)$credentials_changed,$id]);
            if ($credentials_changed) {
                query('DELETE FROM password_changes WHERE teacher_id=?',[$id]);
                if ((int)$actor['id'] === $id) { $own_credential = (int)$row['credential_version'] + 1; }
            }
            return $id;
        });
        if ($own_credential !== null) { $_SESSION['credential_version'] = $own_credential; }
        return $saved_id;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) { throw new FormError('このメールアドレスは既に登録されています。', 422, 'email'); }
        throw $e;
    }
}
