<?php
require __DIR__ . '/bootstrap.php';
$user = require_roles(['admin','operator']);
$filter = $user['role'] === 'operator' ? " WHERE r.name IN ('operator','general')" : '';
$search = text_value($_GET,'q');
if ((isset($_GET['q']) && !is_string($_GET['q'])) || !valid_text($search,100,false)) { page_error(400,'検索語は100文字以内で入力してください。'); }
$search = trim($search); $params = [];
if ($search !== '') {
    $filter .= ($filter === '' ? ' WHERE ' : ' AND ') . "LOCATE(?,CONCAT_WS(' ',t.last_name,t.first_name,CONCAT(t.last_name,t.first_name),t.email))>0";
    $params = [$search];
}
$total = (int)query('SELECT COUNT(*) FROM teachers t JOIN roles r ON r.id=t.role_id' . $filter,$params)->fetchColumn();
$pages = max(1,(int)ceil($total/20)); $page = min($pages,isset($_GET['page'])?positive_id($_GET['page']):1); $offset=($page-1)*20;
$rows = query('SELECT t.id,t.last_name,t.first_name,t.email,t.notes,r.name AS role FROM teachers t JOIN roles r ON r.id=t.role_id' . $filter . ' ORDER BY t.id LIMIT 20 OFFSET ' . $offset,$params)->fetchAll();
page_header('講師情報','teachers');
page_heading('講師情報',$user['role']==='admin'?'講師の連絡先・権限・特記事項を管理できます。':'運営担当・一般講師の情報を閲覧できます。');
list_toolbar('teachers.php',$search,'氏名・メールアドレスで検索',$user['role']==='admin'?'teacher_create.php':null);
?><section class="panel table-panel"><div class="table-meta"><span><strong><?=$total?></strong> 名の講師</span><?php if ($total): ?><span>／ <?=($offset+1)?>〜<?=min($offset+20,$total)?>名を表示</span><?php endif; ?><?php if ($search !== ''): ?><span class="filter-summary">「<?=e($search)?>」の検索結果</span><?php endif; ?><span class="table-hint">一覧は左右にスクロールできます</span></div><?php if (!$rows): ?><div class="empty"><h2><?=$search !== '' ? '検索条件に一致する講師はいません' : '閲覧できる講師はいません'?></h2><p><?=$search !== '' ? '氏名やメールアドレスを変えて検索してください。' : '登録が完了すると、ここに表示されます。'?></p><?php if ($search !== ''): ?><a href="teachers.php">すべての講師を表示</a><?php endif; ?></div><?php else: ?><div class="table-scroll" role="region" aria-label="講師一覧。狭い画面では横にスクロールできます" tabindex="0"><table><caption class="sr-only">講師一覧</caption><thead><tr><th scope="col">氏名</th><th scope="col">メールアドレス</th><th scope="col">権限</th><th scope="col">特記事項</th><th scope="col">操作</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><a class="name-link" href="teacher_detail.php?id=<?=(int)$row['id']?>"><?=e(full_name($row))?></a></td><td class="email-cell"><?=e($row['email'])?></td><td><span class="role <?=e($row['role'])?>"><?=e($row['role'])?></span></td><td><div class="cell-note"><?=e($row['notes']?:'—')?></div></td><td><div class="row-actions"><a class="row-detail" href="teacher_detail.php?id=<?=(int)$row['id']?>" aria-label="<?=e(full_name($row))?>さんの詳細">詳細</a><?php if ($user['role']==='admin'): ?><a class="button small secondary" href="teacher_edit.php?id=<?=(int)$row['id']?>" aria-label="<?=e(full_name($row))?>さんの講師情報を編集"><?=ui_icon('edit')?>編集</a><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php pagination($page,$pages,'teachers.php',$search !== '' ? ['q'=>$search] : []); page_footer(); ?>
