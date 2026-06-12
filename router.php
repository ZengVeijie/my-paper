<?php
/**
 * PHP 内置服务器路由脚本
 * 用法: php -S localhost:8000 -t public/empty_docroot router.php
 */

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

$mimes = [
    'css' => 'text/css', 'js' => 'application/javascript',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'webp' => 'image/webp', 'bmp' => 'image/bmp',
    'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    'pdf' => 'application/pdf', 'md' => 'text/markdown', 'zip' => 'application/zip',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt' => 'text/plain',
    'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac',
];

// Files that display inline in the browser; everything else triggers download
$inline = ['png','jpg','jpeg','gif','svg','ico','webp','bmp','mp4','mp3','wav','ogg','flac','woff','woff2','ttf','css','js'];

$staticPattern = '/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|pdf|md|zip|docx?|xlsx?|pptx?|txt|webp|bmp|mp4|mp3|wav|ogg|flac)$/i';

if (preg_match($staticPattern, $path)) {
    // Try public/ first
    $publicFile = __DIR__ . '/public' . $path;
    if (file_exists($publicFile)) {
        serve_static($publicFile, $mimes, $inline);
        return true;
    }
    // Then try data/ (uploads, etc.)
    $dataFile = __DIR__ . $path;
    if (file_exists($dataFile) && !is_dir($dataFile)) {
        serve_static($dataFile, $mimes, $inline);
        return true;
    }
    return false;
}

// All other requests go to index.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
return true;

function serve_static(string $filePath, array $mimes, array $inline): void {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }
    header('Content-Length: ' . filesize($filePath));
    header('Accept-Ranges: bytes');

    $name = basename($filePath);
    if (in_array($ext, $inline, true)) {
        header('Content-Disposition: inline; filename="' . $name . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $name . '"');
    }

    readfile($filePath);
}
