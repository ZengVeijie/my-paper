<?php
/**
 * My Paper - 备份导出 & 分享
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// ==================== 导出 ====================

/**
 * 解析 Markdown 中的图片引用，将本地图片 URL 改写为 images/xxx.ext，
 * 并收集对应的本地文件路径。$dedup 用于跨文章去重。
 * 返回 [改写后的内容, [本地文件路径数组]]
 */
function process_export_images(string $content, array &$dedup = []): array {
    $image_paths = [];
    preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $alt = $m[1];
        $url = $m[2];

        // 跳过外部 URL
        if (preg_match('#^https?://#', $url)) continue;

        $path_part = parse_url($url, PHP_URL_PATH);
        if (!$path_part) continue;
        $local_path = __DIR__ . '/..' . $path_part;
        if (!file_exists($local_path)) continue;

        $ext = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'])) continue;

        if (!isset($dedup[$url])) {
            $basename = basename($local_path);
            $unique = $basename;
            $n = 1;
            while (in_array('images/' . $unique, $dedup)) {
                $unique = pathinfo($basename, PATHINFO_FILENAME) . '_' . ($n++) . '.' . $ext;
            }
            $dedup[$url] = 'images/' . $unique;
            $image_paths[] = $local_path;
        }

        $content = str_replace($m[0], '![' . $alt . '](' . $dedup[$url] . ')', $content);
    }

    return [$content, $image_paths];
}

function handle_export_article(string $id): void {
    require_login();
    $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
    if (!$article) { http_response_code(404); exit; }

    $user = current_user();
    if ($article['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
        http_response_code(403); exit;
    }

    $md = article_to_markdown($article);
    $filename = slugify($article['title'] ?: 'untitled') . '.md';

    // 处理图片：将 URL 改写为本地路径并收集图片文件
    $dedup = [];
    list($md, $img_paths) = process_export_images($md, $dedup);

    if (empty($img_paths)) {
        // 无图片，直接输出纯 Markdown
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        echo $md;
        exit;
    }

    // 有图片，打包为 ZIP
    set_time_limit(60);
    if (!is_dir(EXPORT_DIR)) mkdir(EXPORT_DIR, 0755, true);
    $tmp = EXPORT_DIR . '/export_' . uuid() . '.zip';
    $files = [['name' => $filename, 'content' => $md, 'images' => $img_paths]];
    $zip_path = create_export_zip($files, 'article', $tmp);

    if (!$zip_path || !file_exists($zip_path)) { http_response_code(500); exit; }

    $zip_name = slugify($article['title'] ?: 'untitled') . '.zip';
    $final_path = EXPORT_DIR . '/' . $zip_name;
    rename($zip_path, $final_path);
    cleanup_old_exports();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($zip_name) . '"; filename*=UTF-8\'\'' . rawurlencode($zip_name));
    header('Content-Length: ' . filesize($final_path));
    readfile($final_path);
    exit;
}

function handle_export_batch(): void {
    require_login();
    $data = body_json();
    $ids = $data['article_ids'] ?? [];
    if (empty($ids)) json_response(['error' => '请选择文章'], 400);

    $user = current_user();
    $files = [];
    $idx = 0;

    $dedup = [];
    foreach ($ids as $id) {
        $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
        if (!$article) continue;
        if ($article['user_id'] !== $user['id'] && $user['role'] !== 'admin') continue;

        $md = article_to_markdown($article);
        $title_slug = slugify($article['title'] ?: 'untitled');
        $filename = sprintf('%02d-', ++$idx) . $title_slug . '.md';

        list($md, $image_paths) = process_export_images($md, $dedup);

        $files[] = ['name' => $filename, 'content' => $md, 'images' => $image_paths];
    }

    if (empty($files)) json_response(['error' => '没有可导出的文章'], 400);

    set_time_limit(120);
    if (!is_dir(EXPORT_DIR)) mkdir(EXPORT_DIR, 0755, true);
    $zip_path = create_export_zip($files, 'articles');

    if (!$zip_path || !file_exists($zip_path)) json_response(['error' => '创建 ZIP 失败'], 500);

    $zip_name = 'My_Paper_export_' . date('Ymd_His') . '.zip';
    $final_path = EXPORT_DIR . '/' . $zip_name;
    rename($zip_path, $final_path);
    cleanup_old_exports();

    json_response(['url' => SITE_URL . '/export/' . $zip_name], 201);
}

function handle_export_all(): void {
    require_login();
    $user = current_user();
    $is_admin = $user['role'] === 'admin';

    $all_users = isset($_GET['all']) && $is_admin;

    $articles = json_list(DATA_DIR . '/articles');
    if (!$all_users) {
        $articles = array_filter($articles, fn($a) => ($a['user_id'] ?? '') === $user['id']);
    }

    $files = [];
    $collections = json_list(DATA_DIR . '/collections');
    if (!$all_users) {
        $collections = array_filter($collections, fn($c) => ($c['user_id'] ?? '') === $user['id']);
    }

    $coll_map = [];
    foreach ($collections as $c) {
        $coll_map[$c['id']] = $c['name'];
    }

    $by_collection = [];
    $uncategorized = [];
    $idx = 0;
    $dedup = [];

    foreach ($articles as $a) {
        $md = article_to_markdown($a);
        list($md, $img_paths) = process_export_images($md, $dedup);
        $title_slug = slugify($a['title'] ?: 'untitled');
        $filename = sprintf('%02d-', ++$idx) . $title_slug . '.md';
        $has_coll = false;
        foreach ($a['collection_ids'] ?? [] as $cid) {
            if (isset($coll_map[$cid])) {
                $by_collection[$coll_map[$cid]][] = ['name' => $filename, 'content' => $md, 'images' => $img_paths];
                $has_coll = true;
            }
        }
        if (!$has_coll) {
            $uncategorized[] = ['name' => $filename, 'content' => $md, 'images' => $img_paths];
        }
    }

    foreach ($by_collection as $coll_name => $coll_files) {
        foreach ($coll_files as $f) {
            $files[] = array_merge($f, ['prefix' => slugify($coll_name) . '/']);
        }
    }
    foreach ($uncategorized as $f) {
        $files[] = $f;
    }

    set_time_limit(120);
    if (!is_dir(EXPORT_DIR)) mkdir(EXPORT_DIR, 0755, true);
    $zip_path = create_export_zip($files, 'all');

    if (!$zip_path || !file_exists($zip_path)) json_response(['error' => '创建 ZIP 失败'], 500);

    $zip_name = 'My_Paper_full_' . date('Ymd_His') . '.zip';
    $final_path = EXPORT_DIR . '/' . $zip_name;
    rename($zip_path, $final_path);
    cleanup_old_exports();

    json_response(['url' => SITE_URL . '/export/' . $zip_name], 201);
}

function article_to_markdown(array $article): string {
    $lines = [];
    $lines[] = '---';
    $lines[] = 'title: ' . ($article['title'] ?? '无标题');
    $lines[] = 'date: ' . ($article['created_at'] ?? '');
    if (!empty($article['updated_at']) && $article['updated_at'] !== $article['created_at']) {
        $lines[] = 'updated: ' . $article['updated_at'];
    }
    if (!empty($article['tags'])) {
        $lines[] = 'tags: [' . implode(', ', $article['tags']) . ']';
    }
    if (!empty($article['author_name'])) {
        $lines[] = 'author: ' . $article['author_name'];
    }
    $vis = $article['visibility'] ?? 'private';
    $vis_labels = ['private' => '仅自己', 'internal' => '站内可见', 'shared' => '已分享'];
    $lines[] = 'visibility: ' . ($vis_labels[$vis] ?? $vis);
    $lines[] = '---';
    $lines[] = '';
    $lines[] = $article['content'] ?? '';

    return implode("\n", $lines);
}

function cleanup_old_exports(): void {
    $files = glob(EXPORT_DIR . '/*.zip');
    $now = time();
    foreach ($files as $f) {
        if ($now - filemtime($f) > 3600) unlink($f);
    }
}

function create_export_zip(array $files, string $type, ?string $out_path = null): ?string {
    $tmp = $out_path ?? (EXPORT_DIR . '/export_' . uuid() . '.zip');
    if (!is_dir(dirname($tmp))) mkdir(dirname($tmp), 0755, true);

    // Encode filenames for Windows compatibility
    $encode = function(string $name): string {
        if (DIRECTORY_SEPARATOR === '\\' && function_exists('mb_convert_encoding')) {
            $gbk = @mb_convert_encoding($name, 'GBK', 'UTF-8');
            if ($gbk !== false) return $gbk;
        }
        return $name;
    };

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        foreach ($files as $f) {
            $name = ($f['prefix'] ?? '') . $f['name'];
            $zip->addFromString($encode($name), $f['content']);
            foreach ($f['images'] ?? [] as $img_path) {
                $img_name = 'images/' . basename($img_path);
                if (file_exists($img_path)) $zip->addFile($img_path, $encode($img_name));
            }
        }
        $zip->close();
    } else {
        create_zip_pure($tmp, $files, $encode);
    }

    return $tmp;
}

function create_zip_pure(string $out_path, array $files, ?callable $encode_name = null): void {
    $encode = $encode_name ?? function(string $s): string { return $s; };
    $data = '';
    $central = '';
    $offset = 0;

    foreach ($files as $f) {
        $name = $encode(($f['prefix'] ?? '') . $f['name']);
        $content = $f['content'];
        $crc = crc32($content);
        $size = strlen($content);
        $timestamp = time();

        // DOS date/time
        $d = getdate($timestamp);
        $dos_time = ($d['seconds'] >> 1) | ($d['minutes'] << 5) | ($d['hours'] << 11);
        $dos_date = $d['mday'] | ($d['mon'] << 5) | (($d['year'] - 1980) << 9);

        // Flags: bit 11 = UTF-8 language encoding
        $gp_flags = 0x0800;

        // Local file header
        $local = "\x50\x4b\x03\x04";
        $local .= "\x14\x00";           // version needed
        $local .= pack('v', $gp_flags); // flags (UTF-8)
        $local .= "\x00\x00";           // compression: store
        $local .= pack('v', $dos_time);
        $local .= pack('v', $dos_date);
        $local .= pack('V', $crc);
        $local .= pack('V', $size);
        $local .= pack('V', $size);     // uncompressed size
        $local .= pack('v', strlen($name));
        $local .= pack('v', 0);         // extra field length
        $local .= $name;
        $local .= $content;

        // Central directory entry
        $central .= "\x50\x4b\x01\x02";
        $central .= "\x14\x00";         // version made by
        $central .= "\x14\x00";         // version needed
        $central .= pack('v', $gp_flags); // flags (UTF-8)
        $central .= "\x00\x00";         // compression
        $central .= pack('v', $dos_time);
        $central .= pack('v', $dos_date);
        $central .= pack('V', $crc);
        $central .= pack('V', $size);
        $central .= pack('V', $size);
        $central .= pack('v', strlen($name));
        $central .= pack('v', 0);       // extra
        $central .= pack('v', 0);       // comment
        $central .= pack('v', 0);       // disk
        $central .= pack('v', 0);       // internal attrs
        $central .= pack('V', 32);      // external attrs
        $central .= pack('V', $offset);
        $central .= $name;

        $data .= $local;
        $offset += strlen($local);

        // Add images as additional files
        foreach ($f['images'] ?? [] as $img_path) {
            if (!file_exists($img_path)) continue;
            $img_content = file_get_contents($img_path);
            $img_name = $encode('images/' . basename($img_path));
            $img_crc = crc32($img_content);
            $img_size = strlen($img_content);

            $img_local = "\x50\x4b\x03\x04";
            $img_local .= "\x14\x00";
            $img_local .= pack('v', $gp_flags);
            $img_local .= "\x00\x00";
            $img_local .= pack('v', $dos_time);
            $img_local .= pack('v', $dos_date);
            $img_local .= pack('V', $img_crc);
            $img_local .= pack('V', $img_size);
            $img_local .= pack('V', $img_size);
            $img_local .= pack('v', strlen($img_name));
            $img_local .= pack('v', 0);
            $img_local .= $img_name;
            $img_local .= $img_content;

            $central .= "\x50\x4b\x01\x02";
            $central .= "\x14\x00";
            $central .= "\x14\x00";
            $central .= pack('v', $gp_flags);
            $central .= "\x00\x00";
            $central .= pack('v', $dos_time);
            $central .= pack('v', $dos_date);
            $central .= pack('V', $img_crc);
            $central .= pack('V', $img_size);
            $central .= pack('V', $img_size);
            $central .= pack('v', strlen($img_name));
            $central .= pack('v', 0);
            $central .= pack('v', 0);
            $central .= pack('v', 0);
            $central .= pack('v', 0);
            $central .= pack('V', 32);
            $central .= pack('V', $offset);
            $central .= $img_name;

            $data .= $img_local;
            $offset += strlen($img_local);
        }
    }

    // End of central directory
    $eocd = "\x50\x4b\x05\x06";
    $eocd .= "\x00\x00";               // disk number
    $eocd .= "\x00\x00";               // disk with central dir
    $eocd .= pack('v', substr_count($central, "\x50\x4b\x01\x02")); // entry count
    $eocd .= pack('v', substr_count($central, "\x50\x4b\x01\x02")); // total entries
    $eocd .= pack('V', strlen($central));
    $eocd .= pack('V', $offset);
    $eocd .= pack('v', 0);             // comment length

    file_put_contents($out_path, $data . $central . $eocd);
}

// ==================== 分享 ====================

function handle_create_share(): void {
    require_login();
    $data = body_json();
    $user = current_user();

    $type = $data['type'] ?? 'article';
    $target_ids = $data['target_ids'] ?? [];

    if (empty($target_ids)) json_response(['error' => '请选择要分享的文章'], 400);

    // Verify ownership
    foreach ($target_ids as $id) {
        $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
        if (!$article || ($article['user_id'] !== $user['id'] && $user['role'] !== 'admin')) {
            json_response(['error' => '文章不存在或无权分享'], 403);
        }
    }

    $code = short_code(8);
    $share = [
        'code' => $code,
        'user_id' => $user['id'],
        'type' => $type,
        'target_ids' => $target_ids,
        'password_hash' => !empty($data['password']) ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
        'show_comments' => (bool)($data['show_comments'] ?? false),
        'expires_at' => $data['expires_at'] ?? null,
        'created_at' => date('c'),
    ];
    json_write(DATA_DIR . '/shares/' . $code . '.json', $share);

    // 更新文章可见性为 shared
    foreach ($target_ids as $id) {
        $a = json_read(DATA_DIR . '/articles/' . $id . '.json');
        if ($a) {
            $a['visibility'] = 'shared';
            json_write(DATA_DIR . '/articles/' . $id . '.json', $a);
        }
    }

    json_response(['code' => $code, 'url' => SITE_URL . '/share/' . $code], 201);
}

function handle_delete_share(string $code): void {
    require_login();
    $share = json_read(DATA_DIR . '/shares/' . $code . '.json');
    if (!$share) json_response(['error' => '分享不存在'], 404);

    $user = current_user();
    if ($share['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
        json_response(['error' => '无权撤销'], 403);
    }

    // 恢复文章可见性
    foreach ($share['target_ids'] as $id) {
        $a = json_read(DATA_DIR . '/articles/' . $id . '.json');
        if ($a && ($a['visibility'] ?? '') === 'shared') {
            // 检查是否还有其他分享链接引用此文
            $all_shares = json_list(DATA_DIR . '/shares');
            $still_shared = false;
            foreach ($all_shares as $s) {
                if ($s['code'] !== $code && in_array($id, $s['target_ids'] ?? [])) {
                    $still_shared = true;
                    break;
                }
            }
            if (!$still_shared) {
                $a['visibility'] = 'private';
                json_write(DATA_DIR . '/articles/' . $id . '.json', $a);
            }
        }
    }

    json_delete(DATA_DIR . '/shares/' . $code . '.json');
    json_response(['ok' => true]);
}

function handle_list_shares(): void {
    require_login();
    $user = current_user();
    $shares = json_list(DATA_DIR . '/shares');
    if ($user['role'] !== 'admin') {
        $shares = array_filter($shares, fn($s) => ($s['user_id'] ?? '') === $user['id']);
    }
    usort($shares, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $shares = array_values($shares);
    foreach ($shares as &$s) {
        $titles = [];
        foreach ($s['target_ids'] as $aid) {
            $a = json_read(DATA_DIR . '/articles/' . $aid . '.json');
            $titles[] = $a ? ($a['title'] ?: '无标题') : '(已删除)';
        }
        $s['target_titles'] = $titles;
    }
    json_response($shares);
}

function handle_share_comment(string $code): void {
    $share = json_read(DATA_DIR . '/shares/' . $code . '.json');
    if (!$share) { json_response(['error' => '分享不存在'], 404); }
    if (empty($share['show_comments'])) { json_response(['error' => '此分享不允许留言'], 403); }
    if (!empty($share['expires_at']) && strtotime($share['expires_at']) < time()) {
        json_response(['error' => '分享链接已过期'], 410);
    }

    $data = body_json();
    $article_id = $data['article_id'] ?? '';
    $content = trim($data['content'] ?? '');
    $guest_name = trim($data['guest_name'] ?? '');
    $parent_id = $data['parent_id'] ?? null;

    if (!in_array($article_id, $share['target_ids'])) {
        json_response(['error' => '文章不属于此分享'], 400);
    }
    if ($content === '') { json_response(['error' => '留言内容不能为空'], 400); }
    if (mb_strlen($content) > 2000) { json_response(['error' => '留言不能超过 2000 字'], 400); }
    $name = $guest_name !== '' ? $guest_name : '访客';
    if (mb_strlen($name) > 30) { json_response(['error' => '昵称不能超过 30 字'], 400); }

    if ($parent_id) {
        $parent = json_read(DATA_DIR . '/comments/' . $parent_id . '.json');
        if (!$parent || $parent['article_id'] !== $article_id) {
            json_response(['error' => '回复的留言不存在'], 400);
        }
        if (!empty($parent['parent_id'])) {
            json_response(['error' => '仅支持两层回复'], 400);
        }
    }

    $id = uuid();
    $comment = [
        'id' => $id,
        'article_id' => $article_id,
        'user_id' => 'guest',
        'user_name' => $name,
        'content' => $content,
        'parent_id' => $parent_id,
        'created_at' => date('c'),
    ];
    json_write(DATA_DIR . '/comments/' . $id . '.json', $comment);

    $article = json_read(DATA_DIR . '/articles/' . $article_id . '.json');
    if ($article) {
        $article['comment_count'] = ($article['comment_count'] ?? 0) + 1;
        json_write(DATA_DIR . '/articles/' . $article_id . '.json', $article);
    }

    json_response($comment, 201);
}

function handle_view_share(string $code): void {
    $share = json_read(DATA_DIR . '/shares/' . $code . '.json');
    if (!$share) {
        http_response_code(404);
        require __DIR__ . '/../themes/404.php';
        return;
    }

    // 检查过期
    if (!empty($share['expires_at']) && strtotime($share['expires_at']) < time()) {
        http_response_code(410);
        ?><!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>链接已过期</title>
        <style>*{margin:0;padding:0;box-sizing:border-box}body{background:#fafaf8;font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh}.card{background:#fff;padding:40px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}h1{font-size:1.2rem;margin-bottom:8px}p{color:#888;font-size:.85rem}</style>
        </head><body><div class="card"><h1>链接已过期</h1><p>此分享链接已超过有效期</p></div></body></html>
        <?php return;
    }

    // 密码保护
    $need_password = !empty($share['password_hash']);
    if ($need_password) {
        $submitted = $_POST['share_password'] ?? '';
        if (!$submitted || !password_verify($submitted, $share['password_hash'])) {
            ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>需要密码 - <?= h(SITE_NAME) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#fafaf8; font-family:system-ui,sans-serif; display:flex; justify-content:center; align-items:center; min-height:100vh; }
        .card { background:#fff; padding:40px 36px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.08); width:360px; max-width:90vw; text-align:center; }
        h1 { font-size:1.2rem; font-weight:600; margin-bottom:8px; }
        .sub { font-size:0.85rem; color:#888; margin-bottom:20px; }
        .err { background:#fef2f2; color:#c00; padding:8px 12px; border-radius:4px; font-size:0.8rem; margin-bottom:16px; }
        input { width:100%; padding:10px 12px; border:1px solid #e0e0e0; border-radius:4px; font-size:0.95rem; margin-bottom:16px; }
        input:focus { outline:none; border-color:#5b7b6f; }
        button { width:100%; padding:10px 0; background:#5b7b6f; color:#fff; border:none; border-radius:4px; font-size:0.95rem; cursor:pointer; }
        button:hover { background:#486458; }
    </style>
</head>
<body>
<div class="card">
    <h1>需要密码</h1>
    <p class="sub">此内容已被加密分享</p>
    <?php if ($submitted): ?><div class="err">密码不正确</div><?php endif; ?>
    <form method="POST">
        <input type="password" name="share_password" placeholder="输入访问密码" required autofocus>
        <button type="submit">确认</button>
    </form>
</div>
</body></html>
<?php return;
        }
    }

    // 获取文章
    $articles = [];
    foreach ($share['target_ids'] as $id) {
        $a = json_read(DATA_DIR . '/articles/' . $id . '.json');
        if ($a) $articles[] = $a;
    }

    // 获取留言
    $comments = [];
    if ($share['show_comments']) {
        foreach ($share['target_ids'] as $id) {
            $article_comments = get_share_comments($id);
            $comments = array_merge($comments, $article_comments);
        }
    }

    // 作者信息
    $owner = json_read(DATA_DIR . '/users/' . ($share['user_id'] ?? '') . '.json');
    $author_name = $owner ? ($owner['display_name'] ?? $owner['username']) : SITE_NAME;

    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(count($articles) === 1 ? $articles[0]['title'] : '分享文章') ?> - <?= h(SITE_NAME) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root {
            --bg: #fafaf8;
            --text: #2c2c2c;
            --text-muted: #888;
            --border: #e8e4df;
            --accent: #5b7b6f;
            --font-body: "Noto Serif SC", Georgia, "Times New Roman", "SimSun", serif;
            --font-ui: system-ui, -apple-system, sans-serif;
            --max-w: 680px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-body);
            line-height: 1.9;
            font-size: 16px;
            padding: 48px 24px 80px;
        }
        .wrap { max-width: var(--max-w); margin: 0 auto; }
        .top {
            text-align: center;
            margin-bottom: 48px;
            padding-bottom: 32px;
            border-bottom: 1px solid var(--border);
        }
        .top h1 {
            font-size: 1.2rem;
            font-weight: 400;
            color: var(--text-muted);
            font-family: var(--font-ui);
        }
        .top p {
            font-size: 0.85rem;
            color: #bbb;
            font-family: var(--font-ui);
            margin-top: 6px;
        }
        .article { margin-bottom: 56px; }
        .article h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 6px;
            line-height: 1.4;
        }
        .article .meta {
            font-family: var(--font-ui);
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 24px;
        }
        .article .tags {
            display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px;
        }
        .article .tags span {
            font-family: var(--font-ui);
            font-size: 0.72rem;
            padding: 1px 8px;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-muted);
        }
        .content {
            font-size: 1.05rem;
            line-height: 1.95;
        }
        .content h1 { font-size: 1.4rem; margin: 1em 0 0.4em; }
        .content h2 { font-size: 1.2rem; margin: 1em 0 0.4em; }
        .content h3 { font-size: 1.05rem; margin: 1em 0 0.4em; }
        .content p { margin: 0.5em 0; }
        .content blockquote {
            border-left: 3px solid var(--accent);
            margin: 0.8em 0;
            padding: 0.3em 1em;
            color: #666;
            font-style: italic;
        }
        .content pre {
            background: #f5f5f0;
            padding: 12px 16px;
            border-radius: 5px;
            font-size: 0.85em;
            line-height: 1.5;
            overflow-x: auto;
        }
        .content code { font-family: "Fira Code", "Consolas", monospace; font-size: 0.88em; }
        .content img { max-width: 100%; border-radius: 4px; }
        .content table { border-collapse: collapse; width: 100%; margin: 0.8em 0; }
        .content th, .content td { border: 1px solid var(--border); padding: 6px 12px; text-align: left; }
        .share-comments {
            margin-top: 48px;
            padding-top: 28px;
            border-top: 1px solid var(--border);
        }
        .share-comments h3 {
            font-size: 1rem;
            font-family: var(--font-ui);
            margin-bottom: 16px;
            color: var(--text-muted);
        }
        .comments-empty { font-family: var(--font-ui); font-size: 0.85rem; color: #bbb; text-align: center; padding: 16px 0; }
        .comment {
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        .comment .c-meta {
            font-family: var(--font-ui);
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .comment .c-body { font-size: 0.9rem; line-height: 1.7; }
        .comment-form {
            margin-top: 20px;
            background: #f9f9f6;
            border-radius: 6px;
            padding: 16px 20px;
        }
        .comment-form h4 {
            font-family: var(--font-ui);
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 10px;
            font-weight: 500;
        }
        .comment-name {
            width: 100%; padding: 8px 12px;
            border: 1px solid var(--border); border-radius: 4px;
            font-family: var(--font-ui); font-size: 0.85rem;
            margin-bottom: 10px; outline: none;
        }
        .comment-name:focus { border-color: var(--accent); }
        .comment-textarea {
            width: 100%; padding: 8px 12px;
            border: 1px solid var(--border); border-radius: 4px;
            font-family: var(--font-body); font-size: 0.9rem;
            resize: vertical; min-height: 80px; outline: none;
        }
        .comment-textarea:focus { border-color: var(--accent); }
        .comment-submit {
            margin-top: 10px; padding: 8px 20px;
            background: var(--accent); color: #fff; border: none;
            border-radius: 4px; font-family: var(--font-ui); font-size: 0.85rem;
            cursor: pointer;
        }
        .comment-submit:hover { background: #486458; }
        .comment-submit:disabled { opacity: 0.6; cursor: not-allowed; }
        .comment-msg { margin-top: 8px; font-family: var(--font-ui); font-size: 0.8rem; padding: 6px 10px; border-radius: 4px; }
        .comment-msg-ok { background: #e8f5e9; color: #2e7d32; }
        .comment-msg-error { background: #fef2f2; color: #c00; }
        .bottom {
            text-align: center;
            margin-top: 56px;
            padding-top: 28px;
            border-top: 1px solid var(--border);
            font-family: var(--font-ui);
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .bottom a { color: var(--accent); text-decoration: none; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <h1><?= h($author_name) ?> 的分享</h1>
        <p><?= count($articles) ?> 篇文章</p>
    </div>

    <?php foreach ($articles as $article): ?>
    <div class="article">
        <h2><?= h($article['title'] ?: '无标题') ?></h2>
        <div class="meta">
            <?= h(format_date($article['created_at'])) ?>
            <?php if (!empty($article['tags'])): ?>
            <div class="tags">
                <?php foreach ($article['tags'] as $tag): ?>
                    <span><?= h($tag) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="content rendered"><?= h($article['content'] ?? '') ?></div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($share['show_comments'])): ?>
    <div class="share-comments">
        <h3>留言 (<?= count($comments) ?>)</h3>
        <div class="comments-list" id="comments-list">
        <?php if (empty($comments)): ?>
            <p class="comments-empty">暂无留言，成为第一个留言的人吧</p>
        <?php else: ?>
            <?php foreach ($comments as $c): ?>
            <div class="comment" id="comment-<?= h($c['id']) ?>">
                <div class="c-meta">
                    <strong><?= h($c['user_name'] ?? '访客') ?></strong>
                    &middot; <?= h(format_date($c['created_at'])) ?>
                </div>
                <div class="c-body"><?= nl2br(h($c['content'] ?? '')) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <div class="comment-form">
            <h4>发表留言</h4>
            <input type="text" id="comment-name" class="comment-name" placeholder="你的昵称（选填）" maxlength="30">
            <textarea id="comment-content" class="comment-textarea" rows="4" placeholder="写下你的想法..." maxlength="2000"></textarea>
            <button class="comment-submit" id="comment-submit-btn" onclick="submitShareComment()">发表留言</button>
            <p class="comment-msg" id="comment-msg" style="display:none;"></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="bottom">
        由 <a href="<?= h(SITE_URL) ?>"><?= h(SITE_NAME) ?></a> 生成
    </div>
</div>

<script>
var shareCode = <?= json_encode($code) ?>;

function submitShareComment() {
    var name = document.getElementById('comment-name').value.trim();
    var content = document.getElementById('comment-content').value.trim();
    var btn = document.getElementById('comment-submit-btn');
    var msg = document.getElementById('comment-msg');

    if (!content) { showCommentMsg('请输入留言内容', 'error'); return; }
    btn.disabled = true; btn.textContent = '发送中...';

    fetch('/api/share/' + shareCode + '/comment', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({ article_id: <?= json_encode($articles[0]['id'] ?? '') ?>, guest_name: name, content: content })
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.error) { showCommentMsg(data.error, 'error'); btn.disabled = false; btn.textContent = '发表留言'; return; }
        showCommentMsg('留言发表成功！', 'ok');
        // Add comment to list
        var list = document.getElementById('comments-list');
        var empty = list.querySelector('.comments-empty'); if (empty) empty.remove();
        var div = document.createElement('div'); div.className = 'comment'; div.id = 'comment-' + data.id;
        div.innerHTML = '<div class="c-meta"><strong>' + esc(data.user_name) + '</strong> &middot; 刚刚</div><div class="c-body">' + esc(content).replace(/\\n/g,'<br>') + '</div>';
        list.insertBefore(div, list.firstChild);
        // Reset
        document.getElementById('comment-content').value = '';
        btn.disabled = false; btn.textContent = '发表留言';
        setTimeout(function() { msg.style.display = 'none'; }, 3000);
    }).catch(function(e) {
        showCommentMsg('网络错误，请重试', 'error');
        btn.disabled = false; btn.textContent = '发表留言';
    });
}

function showCommentMsg(text, type) {
    var msg = document.getElementById('comment-msg');
    msg.textContent = text; msg.className = 'comment-msg comment-msg-' + type;
    msg.style.display = 'block';
}

function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

document.addEventListener('DOMContentLoaded', function() {
    if (typeof marked === 'undefined') return;
    marked.setOptions({ breaks: true, gfm: true });
    document.querySelectorAll('.rendered').forEach(function(el) {
        var raw = el.textContent;
        if (raw.trim()) el.innerHTML = marked.parse(raw);
    });
});
</script>
</body>
</html>
<?php
}

function get_share_comments(string $article_id): array {
    $all = json_list(DATA_DIR . '/comments');
    $comments = array_filter($all, fn($c) => ($c['article_id'] ?? '') === $article_id);
    return array_values($comments);
}

// ==================== 合辑 PDF 书导出 ====================

function handle_export_collection_pdf(string $id): void {
    require_login();
    $coll = json_read(DATA_DIR . '/collections/' . $id . '.json');
    if (!$coll) { http_response_code(404); exit; }

    $articles = [];
    foreach ($coll['article_ids'] ?? [] as $aid) {
        $a = json_read(DATA_DIR . '/articles/' . $aid . '.json');
        if ($a) $articles[] = $a;
    }

    // PDF 书顺序与合辑页面展示顺序相反
    $articles = array_reverse($articles);

    $user = json_read(DATA_DIR . '/users/' . ($coll['user_id'] ?? '') . '.json');
    $author_name = $user ? ($user['display_name'] ?? $user['username']) : '';

    // Generate HTML for print
    header('Content-Type: text/html; charset=utf-8');
    render_book_html($coll, $articles, $author_name);
    exit;
}

function handle_preview_collection_pdf(string $id): void {
    require_login();
    $coll = json_read(DATA_DIR . '/collections/' . $id . '.json');
    if (!$coll) { http_response_code(404); exit; }

    $articles = [];
    foreach ($coll['article_ids'] ?? [] as $aid) {
        $a = json_read(DATA_DIR . '/articles/' . $aid . '.json');
        if ($a) $articles[] = $a;
    }

    // PDF 书顺序与合辑页面展示顺序相反
    $articles = array_reverse($articles);

    $user = json_read(DATA_DIR . '/users/' . ($coll['user_id'] ?? '') . '.json');
    $author_name = $user ? ($user['display_name'] ?? $user['username']) : '';

    header('Content-Type: text/html; charset=utf-8');
    render_book_html($coll, $articles, $author_name);
    exit;
}

function render_book_html(array $coll, array $articles, string $author): void {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= h($coll['name']) ?> - <?= h(SITE_NAME) ?></title>
        <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
        <style>
            :root {
                --base-font-size: 11pt;
                --body-line-height: 2;
                --page-margin-top: 20mm;
                --page-margin-bottom: 20mm;
                --page-margin-inner: 18mm;
                --page-width: 148mm;
                --page-height: 210mm;
            }

            * { margin: 0; padding: 0; box-sizing: border-box; }

            body {
                font-family: "Noto Serif SC", Georgia, "Times New Roman", "SimSun", serif;
                font-size: var(--base-font-size);
                line-height: var(--body-line-height);
                color: #2c2c2c;
                background: #f0f0f0;
                padding: 24px;
            }

            .toolbar {
                position: sticky;
                top: 0;
                z-index: 100;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 10px 20px;
                margin-bottom: 24px;
                display: flex;
                gap: 14px;
                align-items: center;
                flex-wrap: wrap;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                font-family: system-ui, sans-serif;
                font-size: 0.85rem;
            }

            .toolbar button {
                padding: 6px 16px;
                border: 1px solid #5b7b6f;
                background: #5b7b6f;
                color: #fff;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.85rem;
            }

            .toolbar button:hover { background: #486458; }
            .toolbar label { color: #555; white-space: nowrap; }
            .toolbar input[type="range"] { width: 100px; accent-color: #5b7b6f; }
            .toolbar .size-val { color: #888; min-width: 36px; }
            .toolbar select { padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.85rem; }

            /* Book wrapper */
            .book {
                width: var(--page-width);
                margin: 0 auto;
            }

            .page {
                background: #fff;
                padding: var(--page-margin-top) var(--page-margin-inner) var(--page-margin-bottom);
                margin-bottom: 12px;
                box-shadow: 0 1px 6px rgba(0,0,0,0.12);
                page-break-after: always;
                position: relative;
            }

            .page:last-child { page-break-after: auto; }

            /* Page number */
            .page-num {
                position: absolute;
                bottom: 5mm;
                left: 0;
                right: 0;
                text-align: center;
                font-size: calc(var(--base-font-size) * 0.65);
                color: #b0b0b0;
                font-family: system-ui, sans-serif;
            }

            /* Cover page */
            .cover-page {
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                text-align: center;
            }

            .cover-page .book-cover-img {
                max-width: 70%;
                max-height: 50mm;
                margin-bottom: 16mm;
                border-radius: 4px;
            }

            .cover-page .book-title {
                font-size: calc(var(--base-font-size) * 2);
                font-weight: 700;
                letter-spacing: 4px;
                margin-bottom: 8mm;
                line-height: 1.3;
            }

            .cover-page .book-author {
                font-size: var(--base-font-size);
                color: #666;
                margin-bottom: 4mm;
            }

            .cover-page .book-date {
                font-size: calc(var(--base-font-size) * 0.85);
                color: #999;
                margin-bottom: 12mm;
            }

            .cover-page .book-desc {
                font-size: calc(var(--base-font-size) * 0.85);
                color: #888;
                max-width: 85%;
                line-height: 1.8;
            }

            /* TOC */
            .toc-title {
                font-size: calc(var(--base-font-size) * 1.4);
                font-weight: 700;
                text-align: center;
                margin-bottom: 10mm;
                letter-spacing: 3px;
                padding-bottom: 4mm;
                border-bottom: 1px solid #ddd;
            }

            .toc-list { list-style: none; padding: 0; }

            .toc-item {
                display: flex;
                justify-content: space-between;
                align-items: baseline;
                padding: 2.5mm 0;
                border-bottom: 1px dotted #ddd;
                font-size: var(--base-font-size);
            }

            .toc-item .toc-chapter { flex: 1; }
            .toc-item .toc-dots { flex: 1; border-bottom: 1px dotted #ccc; margin: 0 4px; height: 1em; }
            .toc-item .toc-page { min-width: 20px; text-align: right; color: #888; }

            /* Chapter */
            .chapter-title {
                font-size: calc(var(--base-font-size) * 1.4);
                font-weight: 700;
                margin-bottom: 5mm;
                padding-bottom: 3mm;
                border-bottom: 2px solid #5b7b6f;
                text-align: left;
            }

            .chapter-meta {
                font-size: calc(var(--base-font-size) * 0.75);
                color: #999;
                margin-bottom: 8mm;
                font-family: system-ui, sans-serif;
            }

            .chapter-body {
                font-size: var(--base-font-size);
                line-height: var(--body-line-height);
                text-align: justify;
            }

            .chapter-body h1 { font-size: calc(var(--base-font-size) * 1.4); margin: 0.5em 0; }
            .chapter-body h2 { font-size: calc(var(--base-font-size) * 1.25); margin: 0.5em 0; }
            .chapter-body h3 { font-size: calc(var(--base-font-size) * 1.1); margin: 0.5em 0; }
            .chapter-body p { margin: 0.4em 0; }
            .chapter-body blockquote {
                border-left: 3px solid #5b7b6f;
                padding: 2mm 6mm;
                margin: 3mm 0;
                color: #666;
                font-style: italic;
            }
            .chapter-body code { font-family: "Fira Code", monospace; font-size: 0.82em; }
            .chapter-body pre {
                background: #f8f8f8;
                padding: 3mm 5mm;
                font-size: 0.82em;
                line-height: 1.5;
                overflow-x: auto;
                border-radius: 3px;
            }
            .chapter-body img { max-width: 100%; border-radius: 3px; }

            /* Print styles */
            @media print {
                body { background: #fff; padding: 0; }
                .toolbar { display: none !important; }
                .book { width: auto; margin: 0; }
                .page {
                    box-shadow: none;
                    margin: 0;
                    width: auto;
                    padding: 0;
                    page-break-after: always;
                    position: relative;
                    overflow: visible;
                }
                .page:last-child { page-break-after: auto; }

                .page-num { bottom: -18mm; color: #999; }

                @page {
                    size: var(--page-width) var(--page-height);
                    margin-top: var(--page-margin-top);
                    margin-bottom: var(--page-margin-bottom);
                    margin-left: var(--page-margin-inner);
                    margin-right: var(--page-margin-inner);
                }
            }

            @media screen and (max-width: 500px) {
                .book { width: 100%; }
                .page { padding: 10mm; }
                .cover-page .book-title { font-size: 16pt; }
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <button onclick="window.print()">打印 / 导出为 PDF</button>
            <label>纸张
                <select id="paper-size" onchange="changePaperSize(this.value)">
                    <option value="A5" selected>A5</option>
                    <option value="A4">A4</option>
                    <option value="B5">B5</option>
                    <option value="Letter">Letter</option>
                </select>
            </label>
            <label>字号 <input type="range" id="font-size-slider" min="8" max="18" value="11" step="1" oninput="adjustFontSize(this.value)"> <span class="size-val" id="size-val">11pt</span></label>
            <span style="color:#888;font-size:0.8rem;">提示：打印对话框中选择"另存为 PDF"即可导出</span>
        </div>

        <div class="book">
            <!-- Cover -->
            <div class="page cover-page">
                <?php if (!empty($coll['cover'])): ?>
                    <img src="<?= h($coll['cover']) ?>" class="book-cover-img" alt="">
                <?php endif; ?>
                <div class="book-title"><?= h($coll['name']) ?></div>
                <?php if ($author): ?>
                    <div class="book-author"><?= h($author) ?></div>
                <?php endif; ?>
                <div class="book-date"><?= h(date('Y年m月d日', strtotime($coll['created_at']))) ?></div>
                <?php if (!empty($coll['description'])): ?>
                    <div class="book-desc"><?= h($coll['description']) ?></div>
                <?php endif; ?>
            </div>

            <!-- TOC -->
            <div class="page toc-page">
                <div class="toc-title">目 录</div>
                <ul class="toc-list">
                    <?php foreach ($articles as $i => $a): ?>
                        <li class="toc-item">
                            <span class="toc-chapter"><?= h(($a['title'] ?: '无标题')) ?></span>
                            <span class="toc-dots"></span>
                            <span class="toc-page"><?= $i + 3 ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Chapters -->
            <?php foreach ($articles as $a): ?>
            <div class="page">
                <div class="chapter-title"><?= h($a['title'] ?: '无标题') ?></div>
                <div class="chapter-meta">
                    <?= h(format_date($a['created_at'])) ?>
                    <?php if (!empty($a['tags'])): ?>
                        &middot; <?= h(implode(', ', $a['tags'])) ?>
                    <?php endif; ?>
                </div>
                <div class="chapter-body rendered-book-content"><?= h($a['content'] ?? '') ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
            const PAPER_SIZES = {
                'A5':     { w: '148mm', h: '210mm' },
                'A4':     { w: '210mm', h: '297mm' },
                'B5':     { w: '176mm', h: '250mm' },
                'Letter': { w: '215.9mm', h: '279.4mm' }
            };

            function mmToPx(mm) { return mm * 96 / 25.4; }

            // Measure the actual on-screen content area height of one page.
            // Creates a temporary .page element with the current CSS page-height,
            // then subtracts its rendered padding to get the usable content height.
            let _cachedContentH = 0;
            let _cachedContentHKey = '';
            function getPageContentHeight() {
                const rootStyle = getComputedStyle(document.documentElement);
                const fs = rootStyle.getPropertyValue('--base-font-size');
                const pageH = rootStyle.getPropertyValue('--page-height');
                const key = fs + '|' + pageH;
                if (_cachedContentH && _cachedContentHKey === key) return _cachedContentH;

                const test = document.createElement('div');
                test.className = 'page';
                test.style.position = 'absolute';
                test.style.visibility = 'hidden';
                test.style.left = '-9999px';
                test.style.top = '0';
                test.style.height = mmToPx(parseFloat(pageH)) + 'px';
                test.style.boxSizing = 'border-box';
                document.body.appendChild(test);
                const ts = getComputedStyle(test);
                const h = test.clientHeight - parseFloat(ts.paddingTop) - parseFloat(ts.paddingBottom);
                test.remove();

                _cachedContentH = h;
                _cachedContentHKey = key;
                return h;
            }

            function getPageContentWidth() {
                const page = document.querySelector('.page:not(.page-extra)');
                if (!page) return 400;
                const style = getComputedStyle(page);
                return page.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            }

            function adjustFontSize(val) {
                document.documentElement.style.setProperty('--base-font-size', val + 'pt');
                document.getElementById('size-val').textContent = val + 'pt';
                _cachedContentHKey = '';
                document.body.offsetHeight; // force reflow
                requestAnimationFrame(() => { requestAnimationFrame(paginateBook); });
            }

            function changePaperSize(size) {
                const s = PAPER_SIZES[size];
                if (!s) return;
                document.documentElement.style.setProperty('--page-width', s.w);
                document.documentElement.style.setProperty('--page-height', s.h);
                _cachedContentHKey = '';
                document.body.offsetHeight;
                requestAnimationFrame(() => { requestAnimationFrame(paginateBook); });
            }

            // === Pagination engine ===

            // Measure the actual rendered height of an element when constrained
            // to page content width.
            function measureRenderedHeight(el) {
                const clone = el.cloneNode(true);
                clone.style.position = 'absolute';
                clone.style.visibility = 'hidden';
                clone.style.width = getPageContentWidth() + 'px';
                clone.style.height = 'auto';
                clone.style.left = '-9999px';
                clone.style.top = '0';
                document.body.appendChild(clone);
                const h = clone.scrollHeight;
                clone.remove();
                return h;
            }

            // Split element's children across pages of maxH height.
            // Returns array of div.chapter-body elements.
            function splitContentAtHeight(sourceEl, maxH) {
                const measure = sourceEl.cloneNode(true);
                measure.style.position = 'absolute';
                measure.style.visibility = 'hidden';
                measure.style.width = getPageContentWidth() + 'px';
                measure.style.height = 'auto';
                measure.style.left = '-9999px';
                measure.style.top = '0';
                document.body.appendChild(measure);

                const pages = [];

                while (measure.children.length > 0) {
                    if (measure.scrollHeight <= maxH) {
                        const pageDiv = document.createElement('div');
                        pageDiv.className = 'chapter-body rendered-book-content';
                        pageDiv.innerHTML = measure.innerHTML;
                        pages.push(pageDiv);
                        break;
                    }

                    const children = Array.from(measure.children);
                    let splitIdx = children.length;
                    for (let i = children.length - 1; i >= 0; i--) {
                        children[i].remove();
                        if (measure.scrollHeight <= maxH) {
                            splitIdx = i;
                            break;
                        }
                    }
                    if (splitIdx === children.length) {
                        // No split found — even removing all children didn't fit.
                        // This shouldn't happen (empty element has height 0).
                        // Force-take the first child.
                        splitIdx = 0;
                        measure.innerHTML = '';
                    }

                    if (splitIdx === 0) {
                        // First child alone exceeds maxH — take just it as one page
                        const pageDiv = document.createElement('div');
                        pageDiv.className = 'chapter-body rendered-book-content';
                        pageDiv.appendChild(children[0].cloneNode(true));
                        pages.push(pageDiv);
                        measure.innerHTML = children.slice(1).map(c => c.outerHTML).join('');
                    } else {
                        const pageDiv = document.createElement('div');
                        pageDiv.className = 'chapter-body rendered-book-content';
                        pageDiv.innerHTML = children.slice(0, splitIdx).map(c => c.outerHTML).join('');
                        pages.push(pageDiv);
                        measure.innerHTML = children.slice(splitIdx).map(c => c.outerHTML).join('');
                    }
                }

                measure.remove();
                return pages;
            }

            function paginateBook() {
                const contentH = getPageContentHeight();
                const book = document.querySelector('.book');
                if (!book) return;
                const coverPage = book.querySelector('.cover-page');
                const tocPage = book.querySelector('.toc-page');
                const articlePages = Array.from(book.querySelectorAll('.page:not(.cover-page):not(.toc-page):not(.page-extra)'));

                // Restore original content from saved backups
                for (const page of articlePages) {
                    const saved = page.dataset.originalContent;
                    const bodyEl = page.querySelector('.chapter-body');
                    if (saved && bodyEl) {
                        bodyEl.innerHTML = saved;
                    }
                }

                // Remove old page numbers and extra pages
                book.querySelectorAll('.page-num').forEach(el => el.remove());
                book.querySelectorAll('.page-extra').forEach(el => el.remove());

                const physicalPages = [];

                // Cover (no number)
                coverPage.querySelector('.page-num')?.remove();
                physicalPages.push({ el: coverPage });

                // TOC (no number)
                tocPage.querySelector('.page-num')?.remove();
                physicalPages.push({ el: tocPage });

                // Process each article
                for (const origPage of articlePages) {
                    let insertAfter = origPage;
                    const bodyEl = origPage.querySelector('.chapter-body');
                    const titleEl = origPage.querySelector('.chapter-title');
                    const metaEl = origPage.querySelector('.chapter-meta');

                    if (!bodyEl) {
                        physicalPages.push({ el: origPage });
                        continue;
                    }

                    // Save original content before any modification
                    if (!origPage.dataset.originalContent) {
                        origPage.dataset.originalContent = bodyEl.innerHTML;
                    }

                    // overheadH = distance from page content top to body element top.
                    // offsetTop includes all previous siblings' heights + margins
                    // (title margin-bottom:5mm, meta margin-bottom:8mm etc.)
                    const overheadH = bodyEl.offsetTop;

                    const bodyContentH = measureRenderedHeight(bodyEl);
                    const totalH = overheadH + bodyContentH;

                    if (totalH <= contentH) {
                        // Fits on one page
                        physicalPages.push({ el: origPage });
                    } else {
                        // Split body across pages.
                        // First page: available = contentH - overheadH (title + meta present)
                        // Continuation pages: available = contentH (no title/meta)
                        const firstMaxH = contentH - overheadH;
                        const bodyPages = splitContentAtHeight(bodyEl, firstMaxH);

                        // Place first body page on original page (keeps title/meta)
                        bodyEl.innerHTML = bodyPages[0].innerHTML;
                        physicalPages.push({ el: origPage });

                        // If more body pages remain, re-split with full page height
                        if (bodyPages.length > 1) {
                            const restHTML = bodyPages.slice(1).map(p => p.innerHTML).join('');

                            const restMeasure = document.createElement('div');
                            restMeasure.className = 'chapter-body rendered-book-content';
                            restMeasure.innerHTML = restHTML;
                            restMeasure.style.position = 'absolute';
                            restMeasure.style.visibility = 'hidden';
                            restMeasure.style.width = getPageContentWidth() + 'px';
                            restMeasure.style.left = '-9999px';
                            restMeasure.style.top = '0';
                            document.body.appendChild(restMeasure);

                            const restPages = splitContentAtHeight(restMeasure, contentH);
                            restMeasure.remove();

                            for (const rp of restPages) {
                                const extraPage = document.createElement('div');
                                extraPage.className = 'page page-extra';
                                extraPage.innerHTML = rp.outerHTML;
                                insertAfter.after(extraPage);
                                physicalPages.push({ el: extraPage });
                                insertAfter = extraPage;
                            }
                        }
                    }
                }

                // Number content pages starting from 1
                let pg = 1;
                for (const pp of physicalPages) {
                    if (pp.el.classList.contains('cover-page')) continue;
                    if (pp.el.classList.contains('toc-page')) continue;
                    const numSpan = document.createElement('span');
                    numSpan.className = 'page-num';
                    numSpan.textContent = '- ' + pg + ' -';
                    pp.el.appendChild(numSpan);
                    pp.el.dataset.pageNum = pg;
                    pg++;
                }

                // Update TOC page numbers
                const tocItems = tocPage.querySelectorAll('.toc-item');
                const contentPages = physicalPages.filter(
                    p => !p.el.classList.contains('cover-page') && !p.el.classList.contains('toc-page')
                );
                let articleIdx = 0;
                const seenIds = new Set();
                for (let i = 0; i < contentPages.length; i++) {
                    const cp = contentPages[i];
                    const num = cp.el.dataset.pageNum;
                    if (cp.el.querySelector('.chapter-title') && !seenIds.has(cp.el)) {
                        seenIds.add(cp.el);
                        if (tocItems[articleIdx]) {
                            tocItems[articleIdx].querySelector('.toc-page').textContent = num;
                        }
                        articleIdx++;
                    }
                }
            }

            if (typeof marked !== 'undefined') {
                marked.setOptions({ breaks: true, gfm: true });
                document.querySelectorAll('.rendered-book-content').forEach(el => {
                    const raw = el.textContent;
                    if (raw.trim()) el.innerHTML = marked.parse(raw);
                });
            }

            // Initial pagination
            setTimeout(paginateBook, 150);

            const url = new URL(window.location.href);
            if (url.pathname.endsWith('/pdf')) {
                setTimeout(() => { paginateBook(); setTimeout(() => window.print(), 200); }, 300);
            }
        </script>
    </body>
    <?php
}
