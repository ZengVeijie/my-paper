<?php
/**
 * 平静之心 - 认证与用户管理
 */

require_once __DIR__ . '/helpers.php';

// ==================== Session ====================

function session_start_once(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        ini_set('session.cookie_lifetime', SESSION_LIFETIME);
        session_start();
    }
}

function require_login(): void {
    session_start_once();
    if (empty($_SESSION['user_id'])) {
        if (is_ajax()) json_response(['error' => '请先登录'], 401);
        redirect('/login');
    }
}

function current_user(): ?array {
    session_start_once();
    if (empty($_SESSION['user_id'])) return null;
    $user = json_read(DATA_DIR . '/users/' . $_SESSION['user_id'] . '.json');
    if (!$user || empty($user['enabled'])) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function is_admin(): bool {
    $user = current_user();
    return $user && ($user['role'] ?? '') === 'admin';
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        if (is_ajax()) json_response(['error' => '需要管理员权限'], 403);
        redirect('/');
    }
}

// ==================== 初始化 ====================

function init_admin_user(): void {
    $user_dir = DATA_DIR . '/users';
    if (!is_dir($user_dir)) mkdir($user_dir, 0755, true);

    $files = glob($user_dir . '/*.json');
    if (!empty($files)) return; // 已有用户，跳过

    $id = uuid();
    $user = [
        'id' => $id,
        'username' => DEFAULT_ADMIN_USERNAME,
        'password_hash' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_BCRYPT),
        'display_name' => DEFAULT_ADMIN_DISPLAY,
        'role' => 'admin',
        'enabled' => true,
        'deepseek_api_key' => '',
        'unread_notifications' => 0,
        'created_at' => date('c'),
    ];
    json_write($user_dir . '/' . $id . '.json', $user);
}

// ==================== 登录/登出 ====================

function handle_login(): void {
    session_start_once();
    $data = body_json() ?: $_POST;

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if ($username === '' || $password === '') {
        if (is_ajax()) json_response(['error' => '用户名和密码不能为空'], 400);
        redirect('/login?error=empty');
    }

    // 查找用户
    $users = json_list(DATA_DIR . '/users');
    $user = null;
    foreach ($users as $u) {
        if ($u['username'] === $username) { $user = $u; break; }
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        if (is_ajax()) json_response(['error' => '用户名或密码错误'], 401);
        redirect('/login?error=invalid');
    }

    if (empty($user['enabled'])) {
        if (is_ajax()) json_response(['error' => '账号已被禁用'], 403);
        redirect('/login?error=disabled');
    }

    $_SESSION['user_id'] = $user['id'];
    session_regenerate_id(true);

    if (is_ajax()) {
        json_response(['ok' => true, 'user' => sanitize_user($user)]);
    }
    redirect('/');
}

function handle_logout(): void {
    session_start_once();
    unset($_SESSION['user_id']);
    session_destroy();
    if (is_ajax()) json_response(['ok' => true]);
    redirect('/login');
}

// ==================== 注册（邀请码制） ====================

function handle_register(): void {
    session_start_once();
    $data = body_json() ?: $_POST;

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $display = trim($data['display_name'] ?? $username);
    $invite_code = trim($data['invite_code'] ?? '');

    if ($username === '' || $password === '' || $invite_code === '') {
        if (is_ajax()) json_response(['error' => '请填写完整信息'], 400);
        redirect('/register?error=empty');
    }

    if (strlen($password) < 6) {
        if (is_ajax()) json_response(['error' => '密码至少6位'], 400);
        redirect('/register?error=password');
    }

    // 验证邀请码
    $invite = validate_invite_code($invite_code);
    if (!$invite) {
        if (is_ajax()) json_response(['error' => '邀请码无效或已过期'], 400);
        redirect('/register?error=invite');
    }

    // 检查用户名唯一
    $users = json_list(DATA_DIR . '/users');
    foreach ($users as $u) {
        if ($u['username'] === $username) {
            if (is_ajax()) json_response(['error' => '用户名已被使用'], 400);
            redirect('/register?error=duplicate');
        }
    }

    $id = uuid();
    $user = [
        'id' => $id,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'display_name' => $display,
        'role' => 'user',
        'enabled' => true,
        'deepseek_api_key' => '',
        'unread_notifications' => 0,
        'created_at' => date('c'),
    ];
    json_write(DATA_DIR . '/users/' . $id . '.json', $user);

    // 消耗邀请码
    $invite['used_count'] = ($invite['used_count'] ?? 0) + 1;
    json_write(DATA_DIR . '/invites/' . $invite_code . '.json', $invite);

    // 自动登录
    $_SESSION['user_id'] = $id;
    session_regenerate_id(true);

    if (is_ajax()) json_response(['ok' => true, 'user' => sanitize_user($user)]);
    redirect('/');
}

// ==================== 邀请码 ====================

function validate_invite_code(string $code): ?array {
    $invite = json_read(DATA_DIR . '/invites/' . $code . '.json');
    if (!$invite) return null;
    if (empty($invite['enabled'])) return null;
    if ($invite['used_count'] >= $invite['max_uses']) return null;
    if (!empty($invite['expires_at']) && strtotime($invite['expires_at']) < time()) return null;
    return $invite;
}

function handle_create_invite(): void {
    require_admin();
    $data = body_json();

    $code = short_code(12);
    $invite = [
        'code' => $code,
        'created_by' => $_SESSION['user_id'],
        'max_uses' => (int)($data['max_uses'] ?? 1),
        'used_count' => 0,
        'expires_at' => $data['expires_at'] ?? null,
        'note' => $data['note'] ?? '',
        'enabled' => true,
        'created_at' => date('c'),
    ];
    json_write(DATA_DIR . '/invites/' . $code . '.json', $invite);
    json_response($invite, 201);
}

function handle_list_invites(): void {
    require_admin();
    $invites = json_list(DATA_DIR . '/invites');
    usort($invites, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    json_response($invites);
}

function handle_delete_invite(string $code): void {
    require_admin();
    json_delete(DATA_DIR . '/invites/' . $code . '.json');
    json_response(['ok' => true]);
}

// ==================== 用户管理 ====================

function handle_list_users(): void {
    require_admin();
    $users = json_list(DATA_DIR . '/users');
    $users = array_map('sanitize_user', $users);
    usort($users, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    json_response($users);
}

function handle_update_user(string $id): void {
    require_admin();
    $user = json_read(DATA_DIR . '/users/' . $id . '.json');
    if (!$user) json_response(['error' => '用户不存在'], 404);

    $data = body_json();
    if (isset($data['enabled'])) $user['enabled'] = (bool)$data['enabled'];
    if (isset($data['role'])) $user['role'] = $data['role'];
    if (isset($data['display_name'])) $user['display_name'] = trim($data['display_name']);
    if (isset($data['password']) && $data['password'] !== '') {
        $user['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
    }

    json_write(DATA_DIR . '/users/' . $id . '.json', $user);
    json_response(sanitize_user($user));
}

function handle_delete_user(string $id): void {
    require_admin();
    if ($id === $_SESSION['user_id']) {
        json_response(['error' => '不能删除自己'], 400);
    }
    json_delete(DATA_DIR . '/users/' . $id . '.json');
    json_response(['ok' => true]);
}

function handle_update_profile(): void {
    require_login();
    $user = current_user();
    $data = body_json();

    if (isset($data['display_name'])) $user['display_name'] = trim($data['display_name']);
    if (isset($data['deepseek_api_key'])) $user['deepseek_api_key'] = trim($data['deepseek_api_key']);
    if (isset($data['ai_max_tokens'])) $user['ai_max_tokens'] = (int)$data['ai_max_tokens'] ?: null;
    if (isset($data['homepage_mode']) && in_array($data['homepage_mode'], ['both', 'articles_only', 'collections_only'])) {
        $user['homepage_mode'] = $data['homepage_mode'];
    }
    if (isset($data['editor_mode']) && in_array($data['editor_mode'], ['default', 'minimal'])) {
        $user['editor_mode'] = $data['editor_mode'];
    }
    if (isset($data['homepage_calendar'])) {
        $user['homepage_calendar'] = (bool)$data['homepage_calendar'];
    }
    if (!empty($data['new_password'])) {
        if (!password_verify($data['current_password'] ?? '', $user['password_hash'])) {
            json_response(['error' => '当前密码不正确'], 403);
        }
        $user['password_hash'] = password_hash($data['new_password'], PASSWORD_BCRYPT);
    }

    json_write(DATA_DIR . '/users/' . $user['id'] . '.json', $user);
    json_response(sanitize_user($user));
}

// ==================== 工具函数 ====================

function sanitize_user(array $user): array {
    unset($user['password_hash']);
    return $user;
}
