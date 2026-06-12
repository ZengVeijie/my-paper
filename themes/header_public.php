<?php
$share = $share ?? null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($share['type'] ?? '分享') ?> - <?= h(SITE_NAME) ?></title>
    <link rel="stylesheet" href="/public/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
</head>
<body>
<main class="main-content" style="margin-left:0;max-width:720px;margin:0 auto;padding-top:40px;">
    <div class="share-header" style="text-align:center;margin-bottom:40px;padding-bottom:24px;border-bottom:1px solid var(--border);">
        <h1 style="font-size:1.4rem;"><?= h(SITE_NAME) ?></h1>
        <p style="color:var(--text-muted);font-family:var(--font-ui);font-size:0.85rem;">分享的<?= count($articles ?? []) ?>篇文章</p>
    </div>
