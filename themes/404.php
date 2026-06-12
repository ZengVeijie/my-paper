<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - <?= h(SITE_NAME) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#fafaf8; font-family:system-ui,sans-serif; display:flex; justify-content:center; align-items:center; min-height:100vh; }
        .card { background:#fff; padding:48px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); text-align:center; }
        h1 { font-size:2.5rem; color:#5b7b6f; margin-bottom:8px; }
        p { color:#888; margin-bottom:20px; }
        a { color:#5b7b6f; text-decoration:none; padding:8px 24px; border:1px solid #5b7b6f; border-radius:4px; }
        a:hover { background:#5b7b6f; color:#fff; }
    </style>
</head>
<body>
<div class="card">
    <h1>404</h1>
    <p>页面不存在</p>
    <a href="/">返回首页</a>
</div>
</body>
</html>
