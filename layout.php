<?php
declare(strict_types=1);

function page_header(string $title, string $section = '', bool $anonymous = false): void
{
    $user = $GLOBALS['user'] ?? null;
    ?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | βteam</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/app.css"><script src="assets/app.js" defer></script></head><body>
    <a class="skip" href="#main">本文へ移動</a>
    <header class="app-header"><a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">β</span><span>βteam<small>学習塾 生徒・講師管理</small></span></a>
    <?php if ($user && !$anonymous): ?><div class="header-user"><span><?=e(full_name($user))?><small><?=e(role_label($user['role']))?> / <?=e($user['role'])?></small></span><a class="quiet" href="password_change.php?return=<?=e(urlencode(return_target(basename($_SERVER['SCRIPT_NAME']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''))))?>">パスワード変更</a><form method="post" action="logout.php"><?=csrf_input()?><button class="quiet" type="submit">ログアウト</button></form></div><?php endif; ?></header>
    <?php if ($user && !$anonymous && !(int)$user['must_change_password']): ?><nav class="tabs" aria-label="情報の切り替え"><a href="students.php" <?=$section==='students'?'aria-current="page"':''?>>生徒情報</a><?php if ($user['role'] !== 'general'): ?><a href="teachers.php" <?=$section==='teachers'?'aria-current="page"':''?>>講師情報</a><?php endif; ?></nav><?php endif; ?>
    <main id="main" tabindex="-1" class="<?= $anonymous ? 'login-main' : 'container' ?>">
    <?php if (!empty($_SESSION['flash'])): ?><div class="notice success" role="status"><?=e($_SESSION['flash'])?></div><?php unset($_SESSION['flash']); endif;
}
function page_footer(): void { echo '</main><footer class="app-footer">βteam <span>生徒と講師のつながりを、一つの名簿に。</span></footer></body></html>'; }
function page_error(int $status, string $message): never
{
    error_page($status, $message);
}
function page_heading(string $title, string $description, ?string $create = null): void
{
    echo '<div class="page-heading"><div><span class="eyebrow">学習塾の名簿</span><h1>' . e($title) . '</h1><p>' . e($description) . '</p></div>';
    if ($create) { echo '<a class="button" href="' . e($create) . '">＋ 新規登録</a>'; }
    echo '</div>';
}
function pagination(int $page, int $pages, string $path): void
{
    if ($pages < 2) { return; }
    echo '<nav class="pagination" aria-label="ページ切り替え">';
    if ($page > 1) { echo '<a class="button secondary" href="' . e($path) . '?page=' . ($page-1) . '">前へ</a>'; }
    echo '<span>' . $page . ' / ' . $pages . ' ページ</span>';
    if ($page < $pages) { echo '<a class="button secondary" href="' . e($path) . '?page=' . ($page+1) . '">次へ</a>'; }
    echo '</nav>';
}
function deletion_dialog(string $action, array $row, string $kind): void
{
    ?><dialog id="delete-dialog" aria-labelledby="delete-title"><div class="dialog-content"><span class="eyebrow danger-text">削除の確認</span><h2 id="delete-title"><?=e(full_name($row))?>さんを削除しますか？</h2><p>この<?=e($kind)?>情報は元に戻せません。<?php if ($kind === '生徒'): ?>担当講師との関連も解除されます。<?php else: ?>担当生徒がいる講師は削除できません。<?php endif; ?></p><div class="actions"><button type="button" class="button secondary" data-close-dialog>キャンセル</button><form method="post" action="<?=e($action)?>?id=<?=(int)$row['id']?>"><?=csrf_input()?><input type="hidden" name="version" value="<?=(int)$row['version']?>"><button class="button danger" type="submit">削除する</button></form></div></div></dialog><?php
}
