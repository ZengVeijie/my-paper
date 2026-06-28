<?php
$current_user = current_user();
$current_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (defined('BASE_PATH') && BASE_PATH && strpos($current_uri, BASE_PATH) === 0) {
    $current_uri = substr($current_uri, strlen(BASE_PATH));
}
$current_uri = rtrim($current_uri, '/') ?: '/';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(!empty($title) ? $title . ' - ' . SITE_NAME : SITE_NAME) ?></title>
    <base href="<?= h(BASE_PATH . '/') ?>">
    <link rel="stylesheet" href="<?= u('/public/css/style.css') ?>">
    <link rel="stylesheet" href="<?= u('/public/css/editor.css') ?>">
    <script>window.basePath = <?= json_encode(BASE_PATH) ?>;window.currentUserId = <?= json_encode($current_user['id'] ?? null) ?>;</script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>
</head>
<body>
<?php if ($current_user): ?>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <a href="<?= u('/') ?>" class="brand-link"><?= h(SITE_NAME) ?></a>
    </div>
    <div class="sidebar-nav">
        <a href="<?= u('/') ?>" class="nav-item<?= $current_uri === '/' ? ' active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>首页</span>
        </a>
        <a href="<?= u('/write') ?>" class="nav-item<?= $current_uri === '/write' ? ' active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <span>写文章</span>
        </a>
        <a href="<?= u('/collections') ?>" class="nav-item<?= $current_uri === '/collections' ? ' active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
            <span>合辑</span>
        </a>
        <a href="<?= u('/insights') ?>" class="nav-item<?= $current_uri === '/insights' ? ' active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span>洞见</span>
        </a>
        <a href="<?= u('/internal') ?>" class="nav-item<?= $current_uri === '/internal' ? ' active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            <span>站内</span>
        </a>
        <a href="<?= u('/favorites') ?>" class="nav-item<?= $current_uri === '/favorites' ? ' active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span>收藏</span>
        </a>
        <?php if (is_admin()): ?>
        <a href="<?= u('/admin/users') ?>" class="nav-item<?= (strpos($current_uri, '/admin') === 0) ? ' active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            <span>用户管理</span>
        </a>
        <?php endif; ?>
    </div>
    <div class="sidebar-user">
        <div class="sidebar-notify" style="position:relative;">
            <button id="notify-bell" class="nav-item" style="border:none;background:none;cursor:pointer;padding:4px 8px;" title="通知">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                <span id="notify-badge" class="notify-badge" style="display:none;">0</span>
            </button>
        </div>
        <a href="<?= u('/settings') ?>" class="user-name"><?= h($current_user['display_name'] ?? $current_user['username']) ?></a>
        <a href="<?= u('/api/auth/logout') ?>" class="logout-link" id="logout-btn">退出</a>
    </div>
</nav>
<?php endif; ?>
<main class="main-content<?= in_array($current_uri, ['/write'], true) || (strpos($current_uri, '/edit/') === 0) ? ' editor-view' : '' ?>" id="main-content">
<?php if ($current_user): ?>
<button class="menu-toggle" id="menu-toggle" aria-label="菜单">
    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>
<?php endif; ?>
