<?php
/**
 * My Paper - 通用工具函数
 */

function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function short_code(int $length = 8): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function json_read(string $path): ?array {
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function json_write(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function json_delete(string $path): bool {
    if (file_exists($path)) return unlink($path);
    return true;
}

function json_list(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.json');
    if ($files === false) return [];
    $items = [];
    foreach ($files as $file) {
        $data = json_read($file);
        if ($data !== null) $items[] = $data;
    }
    return $items;
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function u(string $path): string {
    if (defined('BASE_PATH') && BASE_PATH) {
        return BASE_PATH . $path;
    }
    return $path;
}

function redirect(string $url): void {
    // Prepend BASE_PATH for internal relative URLs (those starting with /)
    if (defined('BASE_PATH') && BASE_PATH && strpos($url, '/') === 0 && strpos($url, BASE_PATH) !== 0) {
        $url = BASE_PATH . $url;
    }
    header('Location: ' . $url);
    exit;
}

function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_ajax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function body_json(): ?array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function excerpt(string $text, int $max = 200): string {
    $plain = strip_tags($text);
    $plain = preg_replace('/[#*`>\[\]()!\-\|~]/', '', $plain);
    $plain = preg_replace('/\s+/', ' ', trim($plain));
    $len = function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain);
    if ($len <= $max) return $plain;
    return (function_exists('mb_substr') ? mb_substr($plain, 0, $max) : substr($plain, 0, $max)) . '...';
}

function format_date(string $date): string {
    $ts = strtotime($date);
    $today = strtotime('today');
    $yesterday = strtotime('yesterday');
    if ($ts >= $today) return '今天 ' . date('H:i', $ts);
    if ($ts >= $yesterday) return '昨天 ' . date('H:i', $ts);
    if ($ts >= strtotime('-7 days')) {
        $weeks = ['日', '一', '二', '三', '四', '五', '六'];
        return '周' . $weeks[date('w', $ts)] . ' ' . date('H:i', $ts);
    }
    return date('Y-m-d H:i', $ts);
}

function slugify(string $text): string {
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
    $text = preg_replace('/[\s-]+/', '-', trim($text));
    if (function_exists('mb_strtolower')) return mb_strtolower($text);
    return strtolower($text);
}
