<?php
require __DIR__ . '/bootstrap.php';
$user = require_roles(['admin','operator']);
$filter = $user['role'] === 'operator' ? " WHERE r.name IN ('operator','general')" : '';
$total = (int)query('SELECT COUNT(*) FROM teachers t JOIN roles r ON r.id=t.role_id' . $filter)->fetchColumn();
$pages = max(1,(int)ceil($total/20)); $page = min($pages,isset($_GET['page'])?positive_id($_GET['page']):1); $offset=($page-1)*20;
$rows = query('SELECT t.id,t.last_name,t.first_name,t.email,t.notes,r.name AS role FROM teachers t JOIN roles r ON r.id=t.role_id' . $filter . ' ORDER BY t.id LIMIT 20 OFFSET ' . $offset)->fetchAll();
page_header('講師情報','teachers');
page_heading('講師情報',$user['role']==='admin'?'講師の連絡先と権限を管理できます。':'運営担当・一般講師の情報を閲覧できます。',$user['role']==='admin'?'teacher_create.php':null);
?><section class="panel table-panel"><div class="table-meta"><strong><?=$total?></strong>名の講師</div><?php if (!$rows): ?><div class="empty"><h2>閲覧できる講師はいません</h2><p>登録が完了すると、ここに表示されます。</p></div><?php else: ?><div class="table-scroll" role="region" aria-label="講師一覧。狭い画面では横にスクロールできます" tabindex="0"><table><thead><tr><th scope="col">氏名</th><th scope="col">メールアドレス</th><th scope="col">権限</th><th scope="col">特記事項</th><th scope="col">操作</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><a class="name-link" href="teacher_detail.php?id=<?=(int)$row['id']?>"><?=e(full_name($row))?></a></td><td><?=e($row['email'])?></td><td><span class="role <?=e($row['role'])?>"><?=e($row['role'])?></span></td><td><div class="cell-note"><?=e($row['notes']?:'—')?></div></td><td><div class="row-actions"><a class="button small secondary" href="teacher_detail.php?id=<?=(int)$row['id']?>">詳細</a><?php if ($user['role']==='admin'): ?><a class="button small secondary" href="teacher_edit.php?id=<?=(int)$row['id']?>">編集</a><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php pagination($page,$pages,'teachers.php'); page_footer(); ?>
