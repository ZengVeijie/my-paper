<?php
/**
 * My Paper - 站点配置
 * 首次部署时修改此文件中的管理员账号
 */

define('SITE_NAME', 'My Paper');
define('DATA_DIR', __DIR__ . '/data');
define('EXPORT_DIR', __DIR__ . '/export');
define('UPLOAD_DIR', DATA_DIR . '/uploads');
define('SESSION_LIFETIME', 7 * 24 * 3600); // 7天
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// 预设管理员账号 - 首次运行时自动创建，之后可删除或修改
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', 'admin123'); // 首次登录后请立即修改
define('DEFAULT_ADMIN_DISPLAY', '管理员');

// DeepSeek API 全局配置（用户可覆盖）
define('DEEPSEEK_API_KEY', '');
define('DEEPSEEK_MODEL', 'deepseek-chat');
define('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1');

// 时区
date_default_timezone_set('Asia/Shanghai');
