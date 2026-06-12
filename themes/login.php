<?php $error = $_GET['error'] ?? ''; ?>
<div class="auth-page">
    <div class="auth-card">
        <h1 class="auth-title"><?= h(SITE_NAME) ?></h1>
        <p class="auth-subtitle">登录你的日记空间</p>
        <?php if ($error === 'invalid'): ?>
            <div class="alert alert-error">用户名或密码错误</div>
        <?php elseif ($error === 'disabled'): ?>
            <div class="alert alert-error">账号已被禁用</div>
        <?php elseif ($error === 'empty'): ?>
            <div class="alert alert-error">请输入用户名和密码</div>
        <?php endif; ?>
        <form method="POST" action="<?= u('/api/auth/login') ?>" class="auth-form">
            <label class="field">
                <span>用户名</span>
                <input type="text" name="username" required autofocus autocomplete="username">
            </label>
            <label class="field">
                <span>密码</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit" class="btn btn-primary btn-full">登录</button>
        </form>
        <p class="auth-footer">
            <a href="<?= u('/register') ?>">使用邀请码注册</a>
        </p>
    </div>
</div>
