<?php $error = $_GET['error'] ?? ''; ?>
<div class="auth-page">
    <div class="auth-card">
        <h1 class="auth-title"><?= h(SITE_NAME) ?></h1>
        <p class="auth-subtitle">使用邀请码创建账号</p>
        <?php if ($error === 'invite'): ?>
            <div class="alert alert-error">邀请码无效或已过期</div>
        <?php elseif ($error === 'duplicate'): ?>
            <div class="alert alert-error">用户名已被使用</div>
        <?php elseif ($error === 'password'): ?>
            <div class="alert alert-error">密码至少6位</div>
        <?php elseif ($error === 'empty'): ?>
            <div class="alert alert-error">请填写完整信息</div>
        <?php endif; ?>
        <form method="POST" action="<?= u('/api/auth/register') ?>" class="auth-form">
            <label class="field">
                <span>邀请码</span>
                <input type="text" name="invite_code" required autofocus placeholder="输入管理员提供的邀请码">
            </label>
            <label class="field">
                <span>用户名</span>
                <input type="text" name="username" required autocomplete="username">
            </label>
            <label class="field">
                <span>显示名称</span>
                <input type="text" name="display_name" placeholder="可选，留空则使用用户名">
            </label>
            <label class="field">
                <span>密码</span>
                <input type="password" name="password" required autocomplete="new-password" minlength="6">
            </label>
            <button type="submit" class="btn btn-primary btn-full">注册</button>
        </form>
        <p class="auth-footer">
            <a href="<?= u('/login') ?>">返回登录</a>
        </p>
    </div>
</div>
