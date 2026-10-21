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
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['birth_date']);
    if (!$date || $date->format('Y-m-d') !== $data['birth_date'] || $date > new DateTimeImmutable('today')) { $errors['birth_date'] = '生年月日は今日以前の実在する日付を入力してください。'; }
    return $errors;
}
function save_student(?int $id, array $data, array $teacher_ids, ?int $version, bool $notes_only = false): int {
    return transaction(function () use ($id,$data,$teacher_ids,$version,$notes_only): int {
        management_lock();
        $actor = fresh_actor($notes_only ? ['admin','operator','general'] : ['admin','operator']);
        if ($id !== null) {
            $row = query('SELECT * FROM students WHERE id=? FOR UPDATE', [$id])->fetch();
            if (!$row) { throw new FormError('この生徒情報は削除されています。一覧へ戻ってください。'); }
            if ($notes_only && $actor['role'] === 'general' && !assigned_to($id,(int)$actor['id'])) { throw new FormError('担当が変更されたため、この生徒の特記事項は編集できません。'); }
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
