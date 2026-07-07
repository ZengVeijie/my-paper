<?php
/**
 * 平静之心 - 文章管理
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ai.php';

// ==================== 文章 CRUD ====================

function handle_list_articles(): void {
    require_login();
    $user = current_user();

    $articles = json_list(DATA_DIR . '/articles');

    // 获取用户作为协作者可以访问的文章 ID
    $collab_article_ids = [];
    $collections = json_list(DATA_DIR . '/collections');
    foreach ($collections as $c) {
        if (in_array($user['id'], $c['collaborator_ids'] ?? [])) {
            foreach ($c['article_ids'] ?? [] as $aid) {
                $collab_article_ids[] = $aid;
            }
        }
    }
    $collab_article_ids = array_unique($collab_article_ids);

    // 普通用户看自己的文章 + 协作文集中的文章，管理员看全部
    $scope = $_GET['scope'] ?? '';
    if ($user['role'] !== 'admin') {
        if ($scope === 'reference') {
            // 自引模式：自己的文章 + 站内可见的文章 + 协作文集文章
            $articles = array_filter($articles, fn($a) =>
                ($a['user_id'] ?? '') === $user['id']
                || ($a['visibility'] ?? 'private') === 'internal'
                || in_array($a['id'], $collab_article_ids)
            );
        } else {
            $articles = array_filter($articles, fn($a) =>
                ($a['user_id'] ?? '') === $user['id'] || in_array($a['id'], $collab_article_ids)
            );
        }
    }

    // 搜索
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $articles = array_filter($articles, function($a) use ($search) {
            $haystack = ($a['title'] ?? '') . ' ' . ($a['content'] ?? '') . ' ' . implode(' ', $a['tags'] ?? []);
            return (function_exists('mb_stripos') ? mb_stripos($haystack, $search) : stripos($haystack, $search)) !== false;
        });
    }

    // 合辑筛选
    $collection = $_GET['collection'] ?? '';
    if ($collection !== '') {
        $articles = array_filter($articles, fn($a) => in_array($collection, $a['collection_ids'] ?? []));
    }

    // 标签筛选
    $tag = $_GET['tag'] ?? '';
    if ($tag !== '') {
        $articles = array_filter($articles, fn($a) => in_array($tag, $a['tags'] ?? []));
    }

    // 可见性筛选
    $visibility = $_GET['visibility'] ?? '';
    if ($visibility !== '') {
        $articles = array_filter($articles, fn($a) => ($a['visibility'] ?? 'private') === $visibility);
    }

    // 按 ID 列表筛选
    $ids = $_GET['ids'] ?? [];
    if (!empty($ids) && is_array($ids)) {
        $articles = array_filter($articles, fn($a) => in_array($a['id'], $ids, true));
    }

    // 重新索引
    $articles = array_values($articles);

    // 排序：置顶优先，然后按更新时间倒序
    usort($articles, function($a, $b) {
        $ap = $a['pinned'] ?? false;
        $bp = $b['pinned'] ?? false;
        if ($ap && !$bp) return -1;
        if (!$ap && $bp) return 1;
        return strtotime($b['updated_at'] ?? $b['created_at']) - strtotime($a['updated_at'] ?? $a['created_at']);
    });

    // 分页
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $total = count($articles);
    $total_pages = max(1, ceil($total / $per_page));
    $slice = array_slice($articles, ($page - 1) * $per_page, $per_page);

    // 附加作者信息 + 任务统计
    $slice = array_map('attach_author', $slice);
    $slice = array_map('attach_task_stats', $slice);

    if (is_ajax()) {
        json_response([
            'articles' => $slice,
            'page' => $page,
            'total_pages' => $total_pages,
            'total' => $total,
        ]);
    }

    // 页面渲染由 index.php 处理
    $GLOBALS['page_data'] = [
        'articles' => $slice,
        'page' => $page,
        'total_pages' => $total_pages,
        'total' => $total,
        'search' => $search,
        'collection_filter' => $collection,
        'tag_filter' => $tag,
    ];
}

function handle_list_internal(): void {
    require_login();
    $user = current_user();
    $articles = json_list(DATA_DIR . '/articles');

    // 只保留站内可见的文章
    $articles = array_filter($articles, fn($a) => ($a['visibility'] ?? 'private') === 'internal');

    // 搜索
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $articles = array_filter($articles, function($a) use ($search) {
            $haystack = ($a['title'] ?? '') . ' ' . ($a['content'] ?? '') . ' ' . implode(' ', $a['tags'] ?? []);
            return (function_exists('mb_stripos') ? mb_stripos($haystack, $search) : stripos($haystack, $search)) !== false;
        });
    }

    $articles = array_values($articles);

    // 排序：按更新时间倒序
    usort($articles, function($a, $b) {
        return strtotime($b['updated_at'] ?? $b['created_at']) - strtotime($a['updated_at'] ?? $a['created_at']);
    });

    // 分页
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 20;
    $total = count($articles);
    $total_pages = max(1, ceil($total / $per_page));
    $slice = array_slice($articles, ($page - 1) * $per_page, $per_page);

    $slice = array_map('attach_author', $slice);
    $slice = array_map('attach_task_stats', $slice);

    if (is_ajax()) {
        json_response([
            'articles' => $slice,
            'page' => $page,
            'total_pages' => $total_pages,
            'total' => $total,
        ]);
    }

    $GLOBALS['page_data'] = [
        'articles' => $slice,
        'page' => $page,
        'total_pages' => $total_pages,
        'total' => $total,
        'search' => $search,
    ];
}

function handle_article_detail(string $id): void {
    require_login();
    $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
    if (!$article) {
        if (is_ajax()) json_response(['error' => '文章不存在'], 404);
        http_response_code(404);
        require __DIR__ . '/../themes/404.php';
        return;
    }

    // 可见性检查
    $user = current_user();
    $vis = $article['visibility'] ?? 'private';

    // 检查当前用户是否为包含此文章的合辑的协作者
    $is_collab = false;
    if ($vis !== 'public' && $article['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
        $collections = json_list(DATA_DIR . '/collections');
        foreach ($collections as $c) {
            if (in_array($id, $c['article_ids'] ?? []) && in_array($user['id'], $c['collaborator_ids'] ?? [])) {
                $is_collab = true;
                break;
            }
        }
    }

    if ($vis === 'private' && $article['user_id'] !== $user['id'] && $user['role'] !== 'admin' && !$is_collab) {
        if (is_ajax()) json_response(['error' => '无权访问'], 403);
        redirect('/');
    }

    $article = attach_author($article);

    // 获取留言
    $comments = get_article_comments($id);

    if (is_ajax()) {
        json_response(['article' => $article, 'comments' => $comments]);
    }

    $GLOBALS['page_data'] = ['article' => $article, 'comments' => $comments, 'title' => $article['title'] ?: '无标题'];
}

function auto_enrich_article(array &$article): void {
    $content = $article['content'] ?? '';
    $content_len = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
    // 内容太短不自动生成
    if ($content_len < 100) return;

    $need_summary = empty(trim($article['summary'] ?? ''));
    $need_tags = empty($article['tags']) || (is_array($article['tags']) && count($article['tags']) === 0);
    if (!$need_summary && !$need_tags) return;

    $preview = $content_len > 2000
        ? ((function_exists('mb_substr') ? mb_substr($content, 0, 2000) : substr($content, 0, 2000)) . '...(内容过长已截断)')
        : $content;

    $prompt_parts = [];
    if ($need_summary) $prompt_parts[] = '"summary": "50-150字的内容摘要"';
    if ($need_tags) $prompt_parts[] = '"tags": ["标签1", "标签2", "标签3"]（2-4个中文标签，使用统一命名风格）';
    $prompt_fields = implode(",\n    ", $prompt_parts);

    $result = call_deepseek(
        "你是一个日记编辑助手。请根据文章内容生成元数据。\n\n规则：\n- 摘要应提炼核心事件、情绪或思考，而非简单复述标题\n- 标签应简洁、有区分度，方便日后检索（如：工作、情感、成长、阅读、旅行、反思、家庭、健康）\n\n返回严格JSON（不要注释）：\n{\n    {$prompt_fields}\n}\n\n只输出JSON。",
        "请为以下文章生成元数据：\n\n标题：{$article['title']}\n\n内容：\n{$preview}",
        0.4,
        512
    );

    if (isset($result['error'])) return;

    // 兼容 JSON 提取
    $data = json_decode($result['text'] ?? '', true);
    if (!is_array($data) && preg_match('/\{[\s\S]*\}/', $result['text'] ?? '', $m)) {
        $data = json_decode($m[0], true);
    }
    if (!is_array($data)) return;

    if ($need_summary && !empty($data['summary'])) {
        $article['summary'] = trim($data['summary']);
    }
    if ($need_tags && !empty($data['tags']) && is_array($data['tags'])) {
        $article['tags'] = $data['tags'];
    }
}

function handle_create_article(): void {
    require_login();
    $data = body_json();
    $user = current_user();

    $id = uuid();
    $now = date('c');
    $article = [
        'id' => $id,
        'user_id' => $user['id'],
        'title' => trim($data['title'] ?? '无标题'),
        'content' => $data['content'] ?? '',
        'summary' => trim($data['summary'] ?? ''),
        'tags' => $data['tags'] ?? [],
        'collection_ids' => $data['collection_ids'] ?? [],
        'visibility' => $data['visibility'] ?? 'private',
        'pinned' => false,
        'comment_count' => 0,
        'sentiment' => $data['sentiment'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    try { auto_enrich_article($article); } catch (\Throwable $e) {}
    json_write(DATA_DIR . '/articles/' . $id . '.json', $article);
    json_response(attach_author($article), 201);
}

function handle_update_article(string $id): void {
    require_login();
    $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
    if (!$article) json_response(['error' => '文章不存在'], 404);

    $user = current_user();
    if ($article['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
        json_response(['error' => '无权修改'], 403);
    }

    $data = body_json();
    $allowed = ['title', 'content', 'summary', 'tags', 'collection_ids', 'visibility', 'pinned', 'sentiment'];
    foreach ($allowed as $key) {
        if (isset($data[$key])) $article[$key] = $data[$key];
    }
    $article['updated_at'] = date('c');
    try { auto_enrich_article($article); } catch (\Throwable $e) {}

    json_write(DATA_DIR . '/articles/' . $id . '.json', $article);
    json_response(attach_author($article));
}

function handle_list_favorites(): void {
    require_login();
    $user = current_user();
    $fav_ids = $user['favorite_article_ids'] ?? [];

    $articles = [];
    foreach ($fav_ids as $id) {
        $a = json_read(DATA_DIR . '/articles/' . $id . '.json');
        if ($a && ($a['visibility'] ?? 'private') !== 'private') {
            $articles[] = $a;
        }
    }

    // 排序：按收藏ID顺序（最近收藏的在前）
    $articles = array_reverse($articles);

    // 分页
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 20;
    $total = count($articles);
    $total_pages = max(1, ceil($total / $per_page));
    $slice = array_slice($articles, ($page - 1) * $per_page, $per_page);

    $slice = array_map('attach_author', $slice);
    $slice = array_map('attach_task_stats', $slice);

    if (is_ajax()) {
        json_response([
            'articles' => $slice,
            'page' => $page,
            'total_pages' => $total_pages,
            'total' => $total,
        ]);
    }

    $GLOBALS['page_data'] = [
        'articles' => $slice,
        'page' => $page,
        'total_pages' => $total_pages,
        'total' => $total,
    ];
}

function handle_toggle_favorite(string $id): void {
    require_login();
    $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
    if (!$article) json_response(['error' => '文章不存在'], 404);

    $user = current_user();
    $favorites = $user['favorite_article_ids'] ?? [];
    $idx = array_search($id, $favorites);

    if ($idx === false) {
        $favorites[] = $id;
        $favorited = true;
    } else {
        array_splice($favorites, $idx, 1);
        $favorited = false;
    }

    $user['favorite_article_ids'] = array_values($favorites);
    json_write(DATA_DIR . '/users/' . $user['id'] . '.json', $user);

    json_response(['favorited' => $favorited]);
}

function handle_delete_article(string $id): void {
    require_login();
    $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
    if (!$article) json_response(['error' => '文章不存在'], 404);

    $user = current_user();
    if ($article['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
        json_response(['error' => '无权删除'], 403);
    }

    json_delete(DATA_DIR . '/articles/' . $id . '.json');
    json_response(['ok' => true]);
}

// ==================== 留言 ====================

function get_article_comments(string $article_id): array {
    $all = json_list(DATA_DIR . '/comments');
    $comments = array_filter($all, fn($c) => ($c['article_id'] ?? '') === $article_id);
    usort($comments, fn($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));
    return build_comment_tree(array_values($comments));
}

function build_comment_tree(array $comments, ?string $parent_id = null, int $depth = 0): array {
    $tree = [];
    foreach ($comments as $c) {
        if (($c['parent_id'] ?? null) === $parent_id) {
            $c['depth'] = $depth;
            $c['children'] = build_comment_tree($comments, $c['id'], $depth + 1);
            $tree[] = $c;
        }
    }
    return $tree;
}

function handle_create_comment(string $article_id): void {
    require_login();
    $article = json_read(DATA_DIR . '/articles/' . $article_id . '.json');
    if (!$article) json_response(['error' => '文章不存在'], 404);

    $user = current_user();
    $data = body_json();

    $id = uuid();
    $comment = [
        'id' => $id,
        'article_id' => $article_id,
        'parent_id' => $data['parent_id'] ?? null,
        'user_id' => $user['id'],
        'user_name' => $user['display_name'] ?? $user['username'],
        'content' => trim($data['content'] ?? ''),
        'quoted_text' => trim($data['quoted_text'] ?? ''),
        'created_at' => date('c'),
    ];

    if ($comment['content'] === '') {
        json_response(['error' => '留言内容不能为空'], 400);
    }

    // 验证 parent_id，限制只允许两层回复
    if ($comment['parent_id']) {
        $parent = json_read(DATA_DIR . '/comments/' . $comment['parent_id'] . '.json');
        if (!$parent || $parent['article_id'] !== $article_id) {
            json_response(['error' => '回复的留言不存在'], 400);
        }
        if (!empty($parent['parent_id'])) {
            json_response(['error' => '不允许对回复再进行回复'], 400);
        }
    }

    json_write(DATA_DIR . '/comments/' . $id . '.json', $comment);

    // 更新文章留言计数
    $article['comment_count'] = ($article['comment_count'] ?? 0) + 1;
    json_write(DATA_DIR . '/articles/' . $article_id . '.json', $article);

    // 发送通知
    require_once __DIR__ . '/notifications.php';
    $commenter_name = $user['display_name'] ?? $user['username'];
    notify_comment($article_id, $user['id'], $commenter_name);

    // 如果是回复，同时通知被回复的留言作者
    if ($comment['parent_id']) {
        notify_comment_reply($parent, $article, $user['id'], $commenter_name);
    }

    json_response($comment, 201);
}

function handle_delete_comment(string $id): void {
    require_login();
    $comment = json_read(DATA_DIR . '/comments/' . $id . '.json');
    if (!$comment) json_response(['error' => '留言不存在'], 404);

    $user = current_user();
    $article = json_read(DATA_DIR . '/articles/' . $comment['article_id'] . '.json');

    // 文章作者或留言者本人可删除
    $can_delete = ($comment['user_id'] === $user['id']) ||
                  ($article && $article['user_id'] === $user['id']) ||
                  ($user['role'] === 'admin');
    if (!$can_delete) json_response(['error' => '无权删除'], 403);

    // 级联删除子回复
    $all = json_list(DATA_DIR . '/comments');
    $children = array_filter($all, fn($c) => ($c['parent_id'] ?? '') === $id);
    foreach ($children as $child) {
        json_delete(DATA_DIR . '/comments/' . $child['id'] . '.json');
    }
    json_delete(DATA_DIR . '/comments/' . $id . '.json');

    // 更新计数
    if ($article) {
        $article['comment_count'] = max(0, ($article['comment_count'] ?? 1) - 1);
        json_write(DATA_DIR . '/articles/' . $comment['article_id'] . '.json', $article);
    }

    json_response(['ok' => true]);
}

// ==================== 标签 ====================

function handle_list_tags(): void {
    require_login();
    $user = current_user();
    $articles = json_list(DATA_DIR . '/articles');

    if ($user['role'] !== 'admin') {
        $articles = array_filter($articles, fn($a) => ($a['user_id'] ?? '') === $user['id']);
    }

    $tag_counts = [];
    foreach ($articles as $a) {
        foreach ($a['tags'] ?? [] as $tag) {
            $tag_counts[$tag] = ($tag_counts[$tag] ?? 0) + 1;
        }
    }
    arsort($tag_counts);

    $tags = [];
    foreach ($tag_counts as $name => $count) {
        $tags[] = ['name' => $name, 'count' => $count];
    }

    json_response($tags);
}

// ==================== 草稿 ====================

function handle_save_draft(): void {
    require_login();
    $user = current_user();
    $data = body_json();

    $draft = [
        'user_id' => $user['id'],
        'article_id' => $data['article_id'] ?? '',
        'title' => $data['title'] ?? '',
        'content' => $data['content'] ?? '',
        'summary' => $data['summary'] ?? '',
        'tags' => $data['tags'] ?? [],
        'visibility' => $data['visibility'] ?? 'private',
        'updated_at' => date('c'),
    ];

    json_write(DATA_DIR . '/drafts/' . $user['id'] . '.json', $draft);
    json_response(['ok' => true]);
}

function handle_get_draft(): void {
    require_login();
    $user = current_user();
    $draft = json_read(DATA_DIR . '/drafts/' . $user['id'] . '.json');
    json_response($draft ?: ['content' => '', 'title' => '']);
}

function handle_delete_draft(): void {
    require_login();
    $user = current_user();
    json_delete(DATA_DIR . '/drafts/' . $user['id'] . '.json');
    json_response(['ok' => true]);
}

// ==================== 工具函数 ====================

function attach_author(array $article): array {
    $user = json_read(DATA_DIR . '/users/' . ($article['user_id'] ?? '') . '.json');
    $article['author_name'] = $user ? ($user['display_name'] ?? $user['username']) : '未知用户';
    return $article;
}

function get_user_articles_all(string $user_id): array {
    $articles = json_list(DATA_DIR . '/articles');
    $articles = array_filter($articles, fn($a) => ($a['user_id'] ?? '') === $user_id);
    return array_values($articles);
}

function attach_task_stats(array $article): array {
    $content = $article['content'] ?? '';
    preg_match_all('/^\s*- \[([ x])\]/m', $content, $matches);
    $article['task_total'] = count($matches[0]);
    $article['task_done'] = count(array_filter($matches[1], fn($m) => $m === 'x'));
    return $article;
}
