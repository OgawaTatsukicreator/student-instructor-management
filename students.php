<?php
require __DIR__ . '/bootstrap.php';
$user = require_login();
$search = text_value($_GET, 'q');
if ((isset($_GET['q']) && !is_string($_GET['q'])) || !valid_text($search,100,false)) { page_error(400,'検索語は100文字以内で入力してください。'); }
$search = trim($search);
$filter = ''; $params = [];
if ($search !== '') {
    $filter = " WHERE (LOCATE(?,CONCAT_WS(' ',s.last_name,s.first_name,CONCAT(s.last_name,s.first_name)))>0 OR EXISTS (SELECT 1 FROM student_teacher st JOIN teachers t ON t.id=st.teacher_id WHERE st.student_id=s.id AND LOCATE(?,CONCAT_WS(' ',t.last_name,t.first_name,CONCAT(t.last_name,t.first_name)))>0))";
    $params = [$search,$search];
}
$total = (int)query('SELECT COUNT(*) FROM students s' . $filter,$params)->fetchColumn();
$pages = max(1, (int)ceil($total / 20));
$page = min($pages, isset($_GET['page']) ? positive_id($_GET['page']) : 1);
$offset = ($page-1)*20;
$rows = query('SELECT s.* FROM students s' . $filter . ' ORDER BY s.id LIMIT 20 OFFSET ' . $offset,$params)->fetchAll();
$assignments = [];
if ($rows) {
    $ids = array_column($rows,'id'); $marks = implode(',',array_fill(0,count($ids),'?'));
    foreach (query("SELECT st.student_id,t.id,t.last_name,t.first_name FROM student_teacher st JOIN teachers t ON t.id=st.teacher_id WHERE st.student_id IN ($marks) ORDER BY t.id", $ids)->fetchAll() as $teacher) { $assignments[$teacher['student_id']][] = $teacher; }
}
page_header('生徒情報','students');
page_heading('生徒情報','担当講師・生年月日・特記事項を一覧で確認できます。');
list_toolbar('students.php',$search,'氏名・担当講師名で検索', $user['role'] !== 'general' ? 'student_create.php' : null);
?><section class="panel table-panel"><div class="table-meta"><span><strong><?=$total?></strong> 名の生徒</span><?php if ($total): ?><span>／ <?=($offset+1)?>〜<?=min($offset+20,$total)?>名を表示</span><?php endif; ?><?php if ($search !== ''): ?><span class="filter-summary">「<?=e($search)?>」の検索結果</span><?php endif; ?><span class="table-hint">一覧は左右にスクロールできます</span></div>
<?php if (!$rows): ?><div class="empty"><h2><?=$search !== '' ? '検索条件に一致する生徒はいません' : '登録された生徒はいません'?></h2><p><?=$search !== '' ? '氏名や担当講師名を変えて検索してください。' : ($user['role'] !== 'general' ? '新規登録から、生徒と担当講師を登録してください。' : '生徒の登録が完了すると、ここに表示されます。')?></p><?php if ($search !== ''): ?><a href="students.php">すべての生徒を表示</a><?php endif; ?></div><?php else: ?>
<div class="table-scroll" role="region" aria-label="生徒一覧。狭い画面では横にスクロールできます" tabindex="0"><table><caption class="sr-only">生徒一覧</caption><thead><tr><th scope="col">氏名</th><th scope="col">担当講師</th><th scope="col">生年月日</th><th scope="col">特記事項</th><th scope="col">操作</th></tr></thead><tbody>
<?php foreach ($rows as $row): $teachers = $assignments[$row['id']] ?? []; $mine = in_array((int)$user['id'],array_map('intval',array_column($teachers,'id')),true); ?>
<tr><td><a class="name-link" href="student_detail.php?id=<?=(int)$row['id']?>"><?=e(full_name($row))?></a></td><td><div class="assignment-list"><?php foreach ($teachers as $teacher): ?><span class="tag"><?=e(full_name($teacher))?></span><?php endforeach; ?><?php if (!$teachers): ?><span class="subtle">未割当</span><?php endif; ?></div></td><td><?=e(str_replace('-','/',$row['birth_date']))?></td><td><div class="cell-note"><?=e($row['notes'] ?: '—')?></div></td><td><div class="row-actions"><a class="row-detail" href="student_detail.php?id=<?=(int)$row['id']?>" aria-label="<?=e(full_name($row))?>さんの詳細">詳細</a><?php if ($user['role'] !== 'general'): ?><a class="button small secondary" href="student_edit.php?id=<?=(int)$row['id']?>" aria-label="<?=e(full_name($row))?>さんの生徒情報を編集"><?=ui_icon('edit')?>編集</a><?php elseif ($mine): ?><a class="button small secondary" href="student_notes.php?id=<?=(int)$row['id']?>&return=<?=e(urlencode('students.php?' . http_build_query(['page'=>$page,'q'=>$search])))?>"><?=ui_icon('edit')?>特記事項を編集</a><?php endif; ?></div></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php pagination($page,$pages,'students.php',$search !== '' ? ['q'=>$search] : []); page_footer(); ?>
