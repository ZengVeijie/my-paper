<?php
/**
 * 平静之心 - 入口文件
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/articles.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/notifications.php';
require_once __DIR__ . '/lib/ai.php';
require_once __DIR__ . '/lib/insights_apps.php';

// Auto-detect subdirectory path from SCRIPT_NAME
// e.g. /mypaper/index.php => BASE_PATH = /mypaper
// e.g. /index.php => BASE_PATH = '' (root deployment)
// Note: PHP built-in server may set SCRIPT_NAME to the requested URI (not index.php)
// when it can't find static files. We only derive BASE_PATH from .php scripts.
$script_name = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
if (!preg_match('#\.php$#i', $script_name)) {
    $script_name = '/index.php'; // fallback: treat as root deployment
}
$script_dir = rtrim(dirname($script_name), '/\\');
define('BASE_PATH', $script_dir === '/' || $script_dir === '' ? '' : $script_dir);

// Auto-detect site URL
if (!defined('SITE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_URL', $scheme . '://' . $host . BASE_PATH);
}

// 初始化
init_admin_user();

$router = new Router();

// ==================== 页面路由 ====================

$router->get('/', function() {
    require_login();
    handle_list_articles();

    $user = current_user();
    $mode = $user['homepage_mode'] ?? 'both';

    // 加载合辑数据（当模式不为 articles_only 时）
    $collections = [];
    $search = trim($_GET['search'] ?? '');
    if ($mode !== 'articles_only') {
        $collections = json_list(DATA_DIR . '/collections');
        // 仅展示自己的合辑 + 协作的合辑
        $collections = array_filter($collections, function($c) use ($user) {
            return ($c['user_id'] ?? '') === $user['id']
                || in_array($user['id'], $c['collaborator_ids'] ?? []);
        });
        // 合辑搜索过滤
        if ($search !== '') {
            $collections = array_filter($collections, function($c) use ($search) {
                $haystack = ($c['name'] ?? '') . ' ' . ($c['description'] ?? '');
                return (function_exists('mb_stripos') ? mb_stripos($haystack, $search) : stripos($haystack, $search)) !== false;
            });
        }
        // 按创建时间倒序
        usort($collections, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        $collections = array_values($collections);
    }

    $GLOBALS['page_data']['homepage_mode'] = $mode;
    $GLOBALS['page_data']['collections'] = $collections;
    render_page('home');
});

$router->get('/login', function() {
    if (current_user()) redirect('/');
    render_page('login');
});

$router->get('/register', function() {
    if (current_user()) redirect('/');
    render_page('register');
});

$router->get('/internal', function() {
    require_login();
    handle_list_internal();
    render_page('internal');
});

$router->get('/favorites', function() {
    require_login();
    handle_list_favorites();
    render_page('favorites');
});

$router->get('/write', function() {
    require_login();
    render_page('write');
});

$router->get('/edit/{id}', function($id) {
    require_login();
    $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
    if (!$article || ($article['user_id'] !== current_user()['id'] && !is_admin())) {
        redirect('/');
    }
    $GLOBALS['page_data'] = ['edit_article' => $article, 'title' => '编辑 - ' . ($article['title'] ?: '无标题')];
    render_page('write');
});

$router->get('/article/{id}', function($id) {
    require_login();
    handle_article_detail($id);
    render_page('article');
});

$router->get('/collections', function() {
    require_login();
    render_page('collections');
});

$router->get('/collection/{id}', function($id) {
    require_login();
    $coll = json_read(DATA_DIR . '/collections/' . $id . '.json');
    if (!$coll) { http_response_code(404); render_page('404'); return; }
    $GLOBALS['page_data'] = ['collection' => $coll, 'title' => $coll['name']];
    render_page('collection');
});

$router->get('/insights', function() {
    require_login();
    $user = current_user();
    if (!isset($user['insights_apps'])) {
        $user['insights_apps'] = ['sentiment', 'related', 'summary', 'stats'];
        json_write(DATA_DIR . '/users/' . $user['id'] . '.json', $user);
    }
    $GLOBALS['page_data']['insights_apps'] = get_enabled_insights_apps();
    $GLOBALS['page_data']['all_insights_apps'] = get_all_insights_apps();
    $GLOBALS['insights_js'] = '';
    render_page('insights');
});

$router->get('/settings', function() {
    require_login();
    $user = current_user();
    if (!isset($user['insights_apps'])) {
        $user['insights_apps'] = ['sentiment', 'related', 'summary', 'stats'];
        json_write(DATA_DIR . '/users/' . $user['id'] . '.json', $user);
    }
    $GLOBALS['page_data']['all_insights_apps'] = get_all_insights_apps();
    $GLOBALS['page_data']['user_insights_apps'] = get_user_insights_apps();
    render_page('settings');
});

$router->get('/admin/users', function() {
    require_admin();
    render_page('admin');
});

$router->get('/share/{code}', 'handle_view_share');
$router->post('/share/{code}', 'handle_view_share');

// ==================== API: 认证 ====================

$router->post('/api/auth/login', 'handle_login');
$router->post('/api/auth/logout', 'handle_logout');
$router->post('/api/auth/register', 'handle_register');

$router->get('/api/auth/me', function() {
    $user = current_user();
    if (!$user) json_response(['error' => '未登录'], 401);
    json_response(sanitize_user($user));
});

$router->put('/api/auth/password', 'handle_update_profile');
$router->put('/api/auth/profile', 'handle_update_profile');

// ==================== API: 用户管理 ====================

$router->get('/api/admin/users', 'handle_list_users');
$router->put('/api/admin/users/{id}', 'handle_update_user');
$router->delete('/api/admin/users/{id}', 'handle_delete_user');

// ==================== API: 邀请码 ====================

$router->get('/api/admin/invites', 'handle_list_invites');
$router->post('/api/admin/invites', 'handle_create_invite');
$router->delete('/api/admin/invites/{code}', 'handle_delete_invite');

// ==================== API: 文章 ====================

$router->get('/api/articles', 'handle_list_articles');
$router->get('/api/articles/{id}', 'handle_article_detail');
$router->post('/api/articles', 'handle_create_article');
$router->put('/api/articles/{id}', 'handle_update_article');
$router->delete('/api/articles/{id}', 'handle_delete_article');
$router->post('/api/articles/{id}/favorite', 'handle_toggle_favorite');

// 清单任务切换（仅作者）
$router->post('/api/articles/{id}/toggle-task', function($id) {
    require_login();
    $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
    if (!$article) json_response(['error' => '文章不存在'], 404);

    $user = current_user();
    if ($article['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
        json_response(['error' => '无权修改'], 403);
    }

    $data = body_json();
    $lineIdx = (int)($data['line_index'] ?? -1);
    $checked = (bool)($data['checked'] ?? false);

    $lines = explode("\n", $article['content'] ?? '');
    if (!isset($lines[$lineIdx])) json_response(['error' => '行号无效'], 400);

    if ($checked) {
        if (preg_match('/^(\s*- )\[ \]/', $lines[$lineIdx])) {
            $lines[$lineIdx] = preg_replace('/^(\s*- )\[ \]/', '$1[x]', $lines[$lineIdx], 1);
            if (strpos($lines[$lineIdx], '(完成于') === false) {
                $lines[$lineIdx] .= ' (完成于 ' . date('Y-m-d H:i') . ')';
            }
        }
    } else {
        $lines[$lineIdx] = preg_replace('/^(\s*- )\[x\]/', '$1[ ]', $lines[$lineIdx]);
        $lines[$lineIdx] = preg_replace('/\s*\(完成于.*?\)$/', '', $lines[$lineIdx]);
    }

    $article['content'] = implode("\n", $lines);
    $article['updated_at'] = date('c');
    json_write(DATA_DIR . '/articles/' . $id . '.json', $article);

    json_response(['ok' => true, 'content' => $article['content']]);
});

// ==================== API: 草稿 ====================

$router->post('/api/drafts', 'handle_save_draft');
$router->get('/api/drafts', 'handle_get_draft');
$router->delete('/api/drafts', 'handle_delete_draft');

// ==================== API: 留言 ====================

$router->get('/api/articles/{id}/comments', function($id) {
    json_response(get_article_comments($id));
});

$router->post('/api/articles/{id}/comments', 'handle_create_comment');
$router->delete('/api/comments/{id}', 'handle_delete_comment');

// ==================== API: 通知 ====================

$router->get('/api/notifications', 'handle_get_notifications');
$router->post('/api/notifications/read', 'handle_mark_read');

// ==================== API: 合辑 ====================

$router->get('/api/collections', function() {
    require_login();
    $user = current_user();
    $collections = json_list(DATA_DIR . '/collections');
    // 我的合辑 + 我协作的合辑
    $collections = array_filter($collections, function($c) use ($user) {
        $owner = ($c['user_id'] ?? '') === $user['id'];
        $collab = in_array($user['id'], $c['collaborator_ids'] ?? []);
        return $owner || $collab;
    });
    json_response(array_values($collections));
});

$router->post('/api/collections', function() {
    require_login();
    $data = body_json();
    $id = uuid();
    $coll = [
        'id' => $id,
        'user_id' => current_user()['id'],
        'name' => trim($data['name'] ?? '未命名合辑'),
        'description' => $data['description'] ?? '',
        'cover' => $data['cover'] ?? '',
        'article_ids' => $data['article_ids'] ?? [],
        'sort_order' => [],
        'collaborator_ids' => [],
        'created_at' => date('c'),
    ];
    json_write(DATA_DIR . '/collections/' . $id . '.json', $coll);
    json_response($coll, 201);
});

$router->put('/api/collections/{id}', function($id) {
    require_login();
    $coll = json_read(DATA_DIR . '/collections/' . $id . '.json');
    if (!$coll) json_response(['error' => '合辑不存在'], 404);
    $user = current_user();
    $is_owner = ($coll['user_id'] ?? '') === $user['id'];
    $is_collab = in_array($user['id'], $coll['collaborator_ids'] ?? []);
    if (!$is_owner && !$is_collab) json_response(['error' => '无权修改'], 403);

    $data = body_json();

    // 检测文章变更以发送通知
    $old_ids = $coll['article_ids'] ?? [];
    $allowed = ['name', 'description', 'cover', 'article_ids', 'sort_order'];
    foreach ($allowed as $key) {
        if (isset($data[$key])) $coll[$key] = $data[$key];
    }
    json_write(DATA_DIR . '/collections/' . $id . '.json', $coll);

    // 文章列表有变动时通知其他协作者
    $new_ids = $coll['article_ids'] ?? [];
    $added = count(array_diff($new_ids, $old_ids));
    $removed = count(array_diff($old_ids, $new_ids));
    if ($added > 0 || $removed > 0) {
        $uname = $user['display_name'] ?? $user['username'];
        notify_collection_article_change($coll, $user['id'], $uname, $added, $removed);
    }

    json_response($coll);
});

$router->delete('/api/collections/{id}', function($id) {
    require_login();
    $coll = json_read(DATA_DIR . '/collections/' . $id . '.json');
    if (!$coll) json_response(['error' => '合辑不存在'], 404);
    if (($coll['user_id'] ?? '') !== current_user()['id'] && !is_admin()) {
        json_response(['error' => '无权删除'], 403);
    }
    json_delete(DATA_DIR . '/collections/' . $id . '.json');
    json_response(['ok' => true]);
});

$router->post('/api/collections/{id}/collaborators', function($id) {
    require_login();
    $coll = json_read(DATA_DIR . '/collections/' . $id . '.json');
    if (!$coll) json_response(['error' => '合辑不存在'], 404);
    if (($coll['user_id'] ?? '') !== current_user()['id']) json_response(['error' => '仅所有者可邀请'], 403);

    $data = body_json();
    $target_user = json_read(DATA_DIR . '/users/' . ($data['user_id'] ?? '') . '.json');
    if (!$target_user) json_response(['error' => '用户不存在'], 404);

    $coll['collaborator_ids'][] = $target_user['id'];
    $coll['collaborator_ids'] = array_unique($coll['collaborator_ids']);
    json_write(DATA_DIR . '/collections/' . $id . '.json', $coll);

    notify_collaborator_add($coll, $target_user['id']);

    json_response($coll);
});

$router->delete('/api/collections/{id}/collaborators/{user_id}', function($id, $uid) {
    require_login();
    $coll = json_read(DATA_DIR . '/collections/' . $id . '.json');
    if (!$coll) json_response(['error' => '合辑不存在'], 404);
    if (($coll['user_id'] ?? '') !== current_user()['id']) json_response(['error' => '仅所有者可移除'], 403);

    $coll['collaborator_ids'] = array_values(array_filter($coll['collaborator_ids'] ?? [], fn($c) => $c !== $uid));
    json_write(DATA_DIR . '/collections/' . $id . '.json', $coll);
    json_response($coll);
});

// ==================== API: 上传 ====================

$router->post('/api/upload', function() {
    require_login();
    if (empty($_FILES['file'])) json_response(['error' => '没有文件'], 400);

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) json_response(['error' => '上传失败'], 400);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = uuid() . '.' . $ext;
    $dest = UPLOAD_DIR . '/' . $name;

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    move_uploaded_file($file['tmp_name'], $dest);

    $url = '/data/uploads/' . $name;
    json_response(['url' => $url, 'name' => $file['name'], 'size' => $file['size']], 201);
});

// ==================== API: 导出 ====================
// Note: /api/export/all must come before /api/export/{article_id} to avoid route collision

$router->post('/api/export/batch', 'handle_export_batch');
$router->get('/api/export/all', 'handle_export_all');
$router->get('/api/export/templates', 'handle_export_templates');
$router->get('/api/export/collection/{id}/preview', 'handle_preview_collection_pdf');
$router->get('/api/export/collection/{id}/pdf', 'handle_export_collection_pdf');
$router->get('/api/export/{article_id}', 'handle_export_article');

// ==================== API: 分享 ====================

$router->post('/api/share', 'handle_create_share');
$router->delete('/api/share/{code}', 'handle_delete_share');
$router->post('/api/share/{code}/comment', 'handle_share_comment');
$router->get('/api/shares', 'handle_list_shares');

// ==================== API: 标签 ====================

$router->get('/api/tags', 'handle_list_tags');

// ==================== API: AI ====================

$router->post('/api/ai/polish', 'handle_ai_polish');
$router->post('/api/ai/translate', 'handle_ai_translate');
$router->post('/api/ai/explain', 'handle_ai_explain');
$router->post('/api/ai/style', 'handle_ai_style');
$router->post('/api/ai/format', 'handle_ai_format');
$router->post('/api/ai/summary', 'handle_ai_summary');
$router->post('/api/ai/chat', 'handle_ai_chat');
$router->post('/api/ai/search', 'handle_ai_search');
$router->post('/api/ai/query-articles', 'handle_ai_query_articles');
$router->post('/api/ai/sentiment', 'handle_ai_sentiment');
$router->post('/api/ai/related', 'handle_ai_related');
$router->post('/api/ai/period-summary', 'handle_ai_period_summary');
$router->post('/api/ai/writing-insights', 'handle_ai_writing_insights');
$router->post('/api/ai/continue', 'handle_ai_continue');
$router->post('/api/ai/suggest-tags', 'handle_ai_suggest_tags');
$router->post('/api/ai/suggest-title', 'handle_ai_suggest_title');
$router->post('/api/ai/highlights', 'handle_ai_highlights');
$router->post('/api/ai/generate-template', 'handle_ai_generate_template');
$router->get('/api/ai/templates', 'handle_ai_list_templates');
$router->post('/api/ai/templates/reorder', 'handle_ai_reorder_templates');
$router->post('/api/ai/templates', 'handle_ai_create_template');
$router->put('/api/ai/templates/{id}', 'handle_ai_update_template');
$router->delete('/api/ai/templates/{id}', 'handle_ai_delete_template');
$router->post('/api/ai/template/{id}', 'handle_ai_use_template');

// ==================== API: 洞见应用系统 ====================

$router->get('/api/insights/apps', function() {
    require_login();
    json_response(get_all_insights_apps());
});

$router->put('/api/insights/apps/reorder', function() {
    require_login();
    $data = body_json();
    $ids = $data['ids'] ?? [];
    if (!is_array($ids)) json_response(['error' => '参数无效'], 400);
    save_user_insights_apps($ids);
    json_response(['ok' => true]);
});

$router->post('/api/insights/apps/generate', function() {
    require_login();
    $data = body_json();
    $description = trim($data['description'] ?? '');
    if (empty($description)) json_response(['error' => '请描述你想要的应用功能'], 400);

    $result = call_deepseek(
        "你是一个应用分析专家。用户描述了一个洞见分析需求，你需要生成应用元数据和给 AI 的分析提示词。\n\n你的任务不是写前端代码，而是编写高质量的 AI 分析提示词（analysis_prompt），它将被传给 DeepSeek 分析用户的日记内容。\n\n返回严格的 JSON：\n{\n  \"name\": \"应用名称（2-6字）\",\n  \"description\": \"20-50字的功能说明\",\n  \"analysis_prompt\": \"给 DeepSeek 的系统提示词，要求：\\n    - 明确分析视角和方法\\n    - 指定输出 JSON 结构（字段名用英文，内容用中文）\\n    - 引用原文证据\\n    - 温和、有洞察力的语气\\n    - 末尾加上「只输出JSON，不要其他文字」\",\n  \"result_layout\": \"cards|list|mixed|timeline|mindmap|wordcloud|line|calendar\"\n}\n\nresult_layout 说明：\n- cards: 结果是多项并列的数组（如多个发现、多条建议）\n- list: 结果是简单列表\n- mixed: 结果包含一个主体总结 + 可选的子项数组，可附加 chart_data（如盲区分析、CBT分析）\n- timeline: 结果是按时间排列的事件时间线，输出需包含 timeline 数组\n- mindmap: 结果是层级思维导图，输出需包含 mindmap: {topic, children:[{topic, children:[]}]}\n- wordcloud: 结果是词频统计词云，输出需包含 wordcloud: [{text, weight}]\n- line: 结果是时间序列折线图，输出需包含 chart_data: {type:\"line\", labels, datasets:[{label, values}]}\n- calendar: 结果是日历热力图，输出需包含 calendar: {title, months:[{month, days:[{day, date, value, label}]}]}\n\n只输出 JSON，不要其他文字。",
        $description,
        0.7,
        2048
    );

    if (isset($result['error'])) json_response($result, 500);

    $ai_text = $result['text'] ?? '{}';
    $generated = parse_ai_json($ai_text);
    if (!is_array($generated) || empty($generated['name']) || empty($generated['analysis_prompt'])) {
        json_response(['error' => '生成失败，请尝试更具体地描述需求。例如："分析我的情绪波动周期并给出作息建议"'], 500);
    }

    $id = uuid();
    $app_def = [
        'id' => $id,
        'name' => $generated['name'],
        'description' => $generated['description'] ?? '',
        'icon' => '',
        'source' => 'ai',
        'render_type' => 'js',
        'template' => '',
        'analysis_config' => [
            'prompt' => $generated['analysis_prompt'],
            'result_layout' => $generated['result_layout'] ?? 'mixed',
        ],
        'user_id' => current_user()['id'],
        'created_at' => date('c'),
    ];

    // 用组件库组装 HTML 模板
    $app_def['template'] = build_ai_app_template($app_def);

    $dir = DATA_DIR . '/insights_apps';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    json_write($dir . '/' . $id . '.json', $app_def);

    json_response($app_def, 201);
});

$router->post('/api/insights/run/{id}', function($id) {
    require_login();
    $app = json_read(DATA_DIR . '/insights_apps/' . $id . '.json');
    if (!$app) json_response(['error' => '应用不存在'], 404);

    $config = $app['analysis_config'] ?? [];
    $prompt = $config['prompt'] ?? '';
    if (empty($prompt)) json_response(['error' => '该应用缺少分析配置'], 400);

    $data = body_json();
    $scope = $data['scope'] ?? 'all';
    $articles = resolve_insights_articles($scope);
    if (empty($articles)) json_response(['error' => '没有可分析的文章'], 400);
    $catalog = build_article_catalog($articles, 400);

    $result = call_deepseek($prompt, "请分析以下日记：\n\n{$catalog}", 0.7, 2048);

    if (isset($result['error'])) json_response($result, 500);
    $analysis = parse_ai_json($result['text'] ?? '');
    if (!$analysis) {
        json_response(['error' => '分析失败，AI 返回了无法解析的结果，请稍后重试'], 500);
    }

    $analysis['_layout'] = $config['result_layout'] ?? 'mixed';
    json_response($analysis);
});

$router->delete('/api/insights/apps/{id}', function($id) {
    require_login();
    $user = current_user();
    $file = DATA_DIR . '/insights_apps/' . $id . '.json';
    if (!file_exists($file)) json_response(['error' => '应用不存在'], 404);
    $app = json_read($file);
    if (!$app) json_response(['error' => '应用不存在'], 404);
    if (($app['user_id'] ?? '') !== $user['id'] && $user['role'] !== 'admin') {
        json_response(['error' => '无权删除'], 403);
    }
    json_delete($file);
    // 也从用户的启用列表中移除
    $enabled = get_user_insights_apps();
    $enabled = array_values(array_filter($enabled, fn($aid) => $aid !== $id));
    save_user_insights_apps($enabled);
    json_response(['ok' => true]);
});

// ==================== API: 洞见分析（内置应用） ====================

$router->post('/api/insights/mbti', function() {
    require_login();
    $data = body_json();
    $scope = $data['scope'] ?? 'all';
    $articles = resolve_insights_articles($scope);
    if (empty($articles)) json_response(['error' => '没有可分析的文章'], 400);
    $catalog = build_article_catalog($articles, 400);

    $result = call_deepseek(
        '你是一个MBTI心理分析专家。基于用户提供的日记内容，推断用户的MBTI人格类型（如INFJ、ENTP等），给出详细推理。从E/I、S/N、T/F、J/P四个维度分析，引用日记中的具体例证。\n\n每个维度给出0-1之间的分数，表示倾向第二个字母的程度。例如E/I=0.2表示明显偏E，E/I=0.8表示明显偏I，0.5表示平衡。\n\n返回严格的JSON：{"type":"INFJ","dimensions":[{"axis":"E/I","score":0.3,"label":"I"},{"axis":"S/N","score":0.85,"label":"N"},{"axis":"T/F","score":0.7,"label":"F"},{"axis":"J/P","score":0.75,"label":"J"}],"reasoning":"详细推理（含日记例证）...","confidence":"高/中/低"}。只输出JSON。',
        "请分析以下日记：\n\n{$catalog}",
        0.7,
        2048
    );

    if (isset($result['error'])) json_response($result, 500);
    $analysis = parse_ai_json($result['text'] ?? '');
    json_response($analysis ?: ['error' => '分析失败，请稍后重试'], $analysis ? 200 : 500);
});

$router->post('/api/insights/cbt', function() {
    require_login();
    $data = body_json();
    $scope = $data['scope'] ?? 'all';
    $articles = resolve_insights_articles($scope);
    if (empty($articles)) json_response(['error' => '没有可分析的文章'], 400);
    $catalog = build_article_catalog($articles, 400);

    $result = call_deepseek(
        '你是一个CBT（认知行为疗法）治疗师。请分析用户日记中可能存在的3-5处认知扭曲，为每种提供具体的CBT干预建议。认知扭曲类型包括：非黑即白思维、过度概括、心理过滤、灾难化、情绪推理、应该/必须陈述、贴标签、个人化。引用原文句子，给出温和的建议。返回严格的JSON：{"distortions":[{"type":"类型","quote":"原文句子","intervention":"干预建议"}],"summary":"总体建议一段话"}。只输出JSON。',
        "请分析以下日记：\n\n{$catalog}",
        0.7,
        2048
    );

    if (isset($result['error'])) json_response($result, 500);
    $analysis = parse_ai_json($result['text'] ?? '');
    json_response($analysis ?: ['error' => '分析失败，请稍后重试'], $analysis ? 200 : 500);
});

$router->post('/api/insights/blindspot', function() {
    require_login();
    $data = body_json();
    $scope = $data['scope'] ?? 'all';
    $articles = resolve_insights_articles($scope);
    if (empty($articles)) json_response(['error' => '没有可分析的文章'], 400);
    $catalog = build_article_catalog($articles, 400);

    $result = call_deepseek(
        '你是一个深邃的自我认知引导者。请通读用户的所有日记，找出3个用户自己可能没有意识到的关于自己的隐藏真相（盲区）。可以是：反复出现的模式、自相矛盾的信念、未被承认的情感需求、逃避的话题、自我设限的行为等。每个盲区引用具体的日记内容作为证据。语气坦诚但有温度。返回严格的JSON：{"blindspots":[{"title":"盲区标题（10字以内）","insight":"详细洞察","evidence":"引用原文证据","suggestion":"温和的改变建议"}],"summary":"一段总结"}。只输出JSON。',
        "请通读并分析以下日记：\n\n{$catalog}",
        0.7,
        2048
    );

    if (isset($result['error'])) json_response($result, 500);
    $analysis = parse_ai_json($result['text'] ?? '');
    json_response($analysis ?: ['error' => '分析失败，请稍后重试'], $analysis ? 200 : 500);
});

// ==================== 启动 ====================

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// 静态文件
if (preg_match('#\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|pdf|md|zip|docx?|xlsx?|pptx?|txt|webp|bmp|mp4|mp3|wav|ogg|flac)$#i', $uri)) {
    $uriPath = parse_url($uri, PHP_URL_PATH);
    if (BASE_PATH && strpos($uriPath, BASE_PATH) === 0) {
        $uriPath = substr($uriPath, strlen(BASE_PATH));
    }
    $path = __DIR__ . $uriPath;
    if (file_exists($path)) {
        serve_file($path);
        exit;
    }
}

$router->dispatch($method, $uri);

function serve_file(string $path): void {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = [
        'css' => 'text/css', 'js' => 'application/javascript',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        'pdf' => 'application/pdf', 'md' => 'text/markdown', 'zip' => 'application/zip',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac',
    ];
    if (isset($mimes[$ext])) {
        header_remove('Content-Type');
        header('Content-Type: ' . $mimes[$ext] . '; charset=utf-8', true);
    }
    header('Content-Length: ' . filesize($path));
    $inline = ['png','jpg','jpeg','gif','svg','ico','webp','bmp','mp4','mp3','wav','ogg','flac','woff','woff2','ttf','css','js'];
    $name = basename($path);
    if (in_array($ext, $inline, true)) {
        header('Content-Disposition: inline; filename="' . $name . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $name . '"');
    }
    readfile($path);
}

// ==================== 页面渲染 ====================

function render_page(string $view): void {
    $data = $GLOBALS['page_data'] ?? [];
    $user = current_user();

    // 默认页面标题映射
    if (!isset($data['title'])) {
        $titles = [
            'home'        => '首页',
            'write'       => '写文章',
            'edit'        => '编辑文章',
            'article'     => null,
            'internal'    => '站内',
            'favorites'   => '收藏',
            'collections' => '合辑',
            'collection'  => null,
            'insights'    => '洞见',
            'settings'    => '设置',
            'admin'       => '用户管理',
            'login'       => '登录',
            'register'    => '注册',
        ];
        $data['title'] = $titles[$view] ?? null;
    }

    extract($data);
    ob_start();
    require __DIR__ . '/themes/header.php';
    require __DIR__ . '/themes/' . $view . '.php';
    require __DIR__ . '/themes/footer.php';
    $html = ob_get_clean();
    // Rewrite absolute paths for subdirectory deployment
    // Skip URLs already prefixed with BASE_PATH (no leading / since the regex consumed it)
    if (defined('BASE_PATH') && BASE_PATH) {
        $base_q = preg_quote(ltrim(BASE_PATH, '/'), '/');
        $html = preg_replace('/(\b(?:href|src|action|url)\s*=\s*")\/(?!\/|' . $base_q . '\/)/', '${1}' . BASE_PATH . '/', $html);
    }
    echo $html;
}
