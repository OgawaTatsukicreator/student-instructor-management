<?php
declare(strict_types=1);

function ui_icon(string $name): string
{
    $paths = [
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'students'=>'<path d="m3 8 9-5 9 5-9 5-9-5Zm4 3v6c3 3 7 3 10 0v-6M21 8v8"/>',
        'teachers'=>'<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 4h5v12h-3M18 8h1M18 12h1"/>',
        'user'=>'<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>',
        'key'=>'<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-3-3 3-3m-6 0 3-3"/>',
        'logout'=>'<path d="M9 4H4v16h5M9 12h12m-5-5 5 5-5 5"/>',
        'search'=>'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
        'edit'=>'<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-5-5L4 14v6Z"/>',
        'plus'=>'<path d="M12 4v16M4 12h16"/>',
    ];
    return '<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}

function page_header(string $title, string $section = '', bool $anonymous = false): void
{
    $user = $GLOBALS['user'] ?? null;
    $authenticated = $user && !$anonymous;
    $password_page = in_array(basename($_SERVER['SCRIPT_NAME']), ['password_change.php','password_verify.php'], true);
    $return = return_target(basename($_SERVER['SCRIPT_NAME']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    ?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | 学習塾の生徒・講師管理</title><link rel="icon" href="data:,"><link rel="stylesheet" href="assets/app.css"><script src="assets/app.js" defer></script></head><body class="<?=$authenticated ? 'authenticated' : 'anonymous'?>">
    <a class="skip" href="#main">本文へ移動</a>
    <header class="app-header"><a class="brand" href="index.php">学習塾の生徒・講師管理</a>
    <?php if ($authenticated): ?><div class="topbar"><button class="menu-toggle" type="button" aria-controls="app-navigation" aria-expanded="true" aria-label="メニューを閉じる" data-toggle-navigation><?=ui_icon('menu')?></button><span class="topbar-title"><?=e($title)?></span></div><?php endif; ?></header>
    <div class="app-shell">
    <?php if ($authenticated): ?><aside class="sidebar" id="app-navigation" aria-label="メインメニュー">
        <div class="sidebar-user"><?=ui_icon('user')?><div><strong><?=e(full_name($user))?></strong><span><?=e(role_label($user['role']))?> <small><?=e($user['role'])?></small></span></div></div>
        <?php if (!(int)$user['must_change_password']): ?><nav class="sidebar-nav" aria-label="情報管理"><p class="nav-label">情報管理</p><a href="students.php" <?=$section==='students'?'aria-current="page"':''?>><?=ui_icon('students')?>生徒情報</a><?php if ($user['role'] !== 'general'): ?><a href="teachers.php" <?=$section==='teachers'?'aria-current="page"':''?>><?=ui_icon('teachers')?>講師情報</a><?php endif; ?></nav><?php endif; ?>
        <nav class="sidebar-nav account-nav" aria-label="アカウント"><p class="nav-label">アカウント</p><a href="password_change.php?return=<?=e(urlencode($return))?>" <?=$password_page?'aria-current="page"':''?>><?=ui_icon('key')?>パスワード変更</a><form method="post" action="logout.php"><?=csrf_input()?><button type="submit"><?=ui_icon('logout')?>ログアウト</button></form></nav>
    </aside><?php endif; ?>
    <div class="workspace"><main id="main" tabindex="-1" class="<?= $anonymous ? 'login-main' : 'container' ?>">
    <?php if (!empty($_SESSION['flash'])): ?><div class="notice success" role="status"><?=e($_SESSION['flash'])?></div><?php unset($_SESSION['flash']); endif;
}
function page_footer(): void { echo '</main></div></div></body></html>'; }
function page_error(int $status, string $message): never { error_page($status, $message); }
function page_heading(string $title, string $description, ?string $create = null): void
{
    echo '<div class="page-heading"><div><h1>' . e($title) . '</h1><p>' . e($description) . '</p></div>';
    if ($create) { echo '<a class="button" href="' . e($create) . '">' . ui_icon('plus') . '新規登録</a>'; }
    echo '</div>';
}
function list_toolbar(string $path, string $search, string $label, ?string $create): void
{
    ?><div class="list-toolbar"><form method="get" action="<?=e($path)?>" class="search-form" role="search"><label class="sr-only" for="list-search"><?=e($label)?></label><div class="search-field"><?=ui_icon('search')?><input type="search" id="list-search" name="q" value="<?=e($search)?>" maxlength="100" placeholder="<?=e($label)?>" autocomplete="off"></div><button type="submit" class="button secondary">検索</button><?php if ($search !== ''): ?><a class="search-clear" href="<?=e($path)?>">解除</a><?php endif; ?></form><?php if ($create): ?><a class="button" href="<?=e($create)?>"><?=ui_icon('plus')?>新規登録</a><?php endif; ?></div><?php
}
function pagination(int $page, int $pages, string $path, array $params = []): void
{
    if ($pages < 2) { return; }
    echo '<nav class="pagination" aria-label="ページ切り替え">';
    if ($page > 1) { echo '<a class="button secondary" href="' . e($path . '?' . http_build_query(array_merge($params, ['page'=>$page-1]))) . '">前へ</a>'; }
    echo '<span>' . $page . ' / ' . $pages . ' ページ</span>';
    if ($page < $pages) { echo '<a class="button secondary" href="' . e($path . '?' . http_build_query(array_merge($params, ['page'=>$page+1]))) . '">次へ</a>'; }
    echo '</nav>';
}
function deletion_dialog(string $action, array $row, string $kind): void
{
    ?><dialog id="delete-dialog" aria-labelledby="delete-title"><div class="dialog-content"><span class="eyebrow danger-text">削除の確認</span><h2 id="delete-title"><?=e(full_name($row))?>さんを削除しますか？</h2><p>この<?=e($kind)?>情報は元に戻せません。<?php if ($kind === '生徒'): ?>担当講師との関連も解除されます。<?php else: ?>担当生徒がいる講師は削除できません。<?php endif; ?></p><div class="actions"><button type="button" class="button secondary" data-close-dialog>キャンセル</button><form method="post" action="<?=e($action)?>?id=<?=(int)$row['id']?>"><?=csrf_input()?><input type="hidden" name="version" value="<?=(int)$row['version']?>"><button class="button danger" type="submit">削除する</button></form></div></div></dialog><?php
}
