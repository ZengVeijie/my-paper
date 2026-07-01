<?php
/**
 * 平静之心 - 入口文件
 */

// PHP built-in server: serve static files directly
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;
    if (is_file($file)) return false;
}

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
    $apps = get_all_insights_apps();
    // 为自定义应用附加创建者昵称
    $user_cache = [];
    foreach ($apps as &$app) {
        if (($app['source'] ?? '') !== 'builtin' && !empty($app['user_id'])) {
            if (!isset($user_cache[$app['user_id']])) {
                $u = json_read(DATA_DIR . '/users/' . $app['user_id'] . '.json');
                $user_cache[$app['user_id']] = $u['nickname'] ?? $u['username'] ?? '未知';
            }
            $app['user_name'] = $user_cache[$app['user_id']];
        }
    }
    unset($app);
    json_response($apps);
});

$router->put('/api/insights/apps/{id}/publish', function($id) {
    require_login();
    $file = DATA_DIR . '/insights_apps/' . $id . '.json';
    $app = json_read($file);
    if (!$app) json_response(['error' => '应用不存在'], 404);
    $user = current_user();
    if (($app['user_id'] ?? '') !== $user['id'] && $user['role'] !== 'admin') {
        json_response(['error' => '只能操作自己创建的应用'], 403);
    }
    $current = $app['visibility'] ?? 'private';
    $app['visibility'] = ($current === 'public') ? 'private' : 'public';
    json_write($file, $app);
    json_response(['ok' => true, 'visibility' => $app['visibility']]);
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

    $tool_specs = [
        'cards' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"items\": [\n    {\"title\": \"标题(10字内)\", \"type\": \"类型标签\", \"insight\": \"核心分析(100-300字)\", \"evidence\": \"引用原文1-2句\", \"suggestion\": \"具体建议\"}\n  ],\n  \"_layout\": \"cards\"\n}",
        'list' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"items\": [\n    {\"title\": \"标题\", \"content\": \"内容描述\"}\n  ],\n  \"_layout\": \"list\"\n}",
        'mixed' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"title\": \"分析标题\",\n  \"summary\": \"200-400字总体总结\",\n  \"items\": [\n    {\"title\": \"子项标题\", \"type\": \"类型标签\", \"insight\": \"分析内容\", \"evidence\": \"引用原文\", \"suggestion\": \"建议\"}\n  ],\n  \"chart_data\": {\"type\": \"bar|donut|line|radar\", \"title\": \"图表标题\", \"labels\": [\"标签\"], \"values\": [数值], \"datasets\": [{\"label\": \"系列\", \"values\": [数值]}]},\n  \"_layout\": \"mixed\"\n}\n注：chart_data 可选。bar 需 labels+values，donut 需 labels+values，line 需 labels+datasets，radar 需 labels+values（4轴雷达图）。",
        'timeline' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"timeline\": [\n    {\"date\": \"YYYY-MM-DD\", \"title\": \"事件标题\", \"description\": \"事件简要描述\", \"sentiment\": \"positive/neutral/negative\", \"tags\": [\"标签\"]}\n  ],\n  \"summary\": \"100-200字总述\",\n  \"_layout\": \"timeline\"\n}",
        'mindmap' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"mindmap\": {\n    \"topic\": \"核心主题\",\n    \"children\": [\n      {\"topic\": \"一级分支\", \"children\": [{\"topic\": \"二级节点\", \"children\": []}]}\n    ]\n  },\n  \"_layout\": \"mindmap\"\n}",
        'wordcloud' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"wordcloud\": [\n    {\"text\": \"词语\", \"weight\": 15}\n  ],\n  \"_layout\": \"wordcloud\"\n}\n注：weight 越大字号越大，最高频词建议 weight=20。",
        'line' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"chart_data\": {\n    \"type\": \"line\",\n    \"title\": \"趋势图标题\",\n    \"labels\": [\"1月\", \"2月\", \"3月\"],\n    \"datasets\": [{\"label\": \"系列A\", \"values\": [3, 5, 2]}]\n  },\n  \"_layout\": \"line\"\n}\n注：可多个数据集，每个数据集自动分配颜色。",
        'calendar' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"calendar\": {\n    \"title\": \"日历标题\",\n    \"months\": [\n      {\"month\": \"1月\", \"days\": [{\"day\": \"1\", \"date\": \"1月1日\", \"value\": 3, \"label\": \"3篇\"}]}\n    ]\n  },\n  \"_layout\": \"calendar\"\n}\n注：value 越大颜色越深。",
        'report' => "输出 JSON 格式（严格使用以下字段名）：\n{\n  \"report\": {\n    \"title\": \"报告标题\",\n    \"summary\": \"100-200字报告摘要\",\n    \"sections\": [\n      {\n        \"heading\": \"章节标题\",\n        \"content\": \"章节正文内容(150-300字)\",\n        \"chart_data\": {\"type\": \"bar|donut|line|radar\", \"title\": \"图表标题\", \"labels\": [\"标签\"], \"values\": [数值], \"datasets\": [{\"label\": \"系列\", \"values\": [数值]}]}\n      }\n    ]\n  },\n  \"_layout\": \"report\"\n}\n注：report 适用于综合报告生成。sections 至少2个，每个可附带可选 chart_data。四种图表类型：bar(柱状图)、donut(环形图)、line(折线图)、radar(雷达图)。",
    ];
    $specs_text = '';
    foreach ($tool_specs as $layout => $spec) {
        $specs_text .= "=== {$layout} ===\n{$spec}\n\n";
    }

    $template_opts_spec = <<<'EOS'
## 模板配置（template_opts）

你需要为应用选择输入控件和交互风格，让每个应用有独特的体验：

### input_type（输入控件类型）
- scope: 文章范围选择器（下拉选"所有文章"或某个合辑）。适用于大多数分析类应用。
- keyword: 关键词输入框。适用于主题搜索、关键词分析等需要用户输入主题的应用。
- question: 开放式问题输入框。适用于问答式探索、自省引导等对话式应用。
- date_range: 起止日期选择器。适用于时间范围分析、周期性回顾等时间敏感应用。
- none: 无额外输入，直接点击按钮即可。适用于全局分析、自动洞察等无需筛选的应用。

### features（可选交互组件，0-3个）
- surprise: 随机探索按钮，随机选一篇文章分析。适用于探索发现类应用。
- depth_slider: 分析深度滑块（简→深 三档）。适用于需要调节分析详细程度的应用。
- compare: 双范围对比选择器（A vs B）。适用于对比分析类应用。
- count_badge: 在标题旁显示已分析文章数量徽章。适用于统计类应用。
- auto_run: 进入页面即自动开始分析，无需用户任何操作。必须搭配 input_type: "none" 使用。适用于无需用户筛选、默认分析全文的全局洞察类应用（如"今日心情快照""本周回顾"等）。
- select_first: 分析前先让 AI 从文章列表中挑选出与问题/主题相关的文章，再对精选后的文章进行深度分析。必须搭配 input_type: "keyword" 或 "question" 使用。适用于问答式、主题分析等需要精准匹配的应用。文章数<=3时不触发挑选。

### style（视觉风格）
- default: 标准卡片风格，整洁专业
- minimal: 极简风格，少装饰、轻量级
- explorer: 探索风格，更大间距、更有趣味的色调

EOS;

    $result = call_deepseek(
        "你是一个应用分析专家。用户描述了一个洞见分析需求，你需要生成应用元数据和给 AI 的分析提示词。\n\n## 核心规则（极其重要）\n\n你的 analysis_prompt 将被直接传给 DeepSeek 执行分析。因此**analysis_prompt 必须是自包含的完整提示词**：\n1. 在 analysis_prompt 开头写清楚分析视角和方法\n2. **在 analysis_prompt 末尾，必须把下面「对应布局的输出 JSON 格式规范」原样粘贴进去**\n3. 末尾务必加上「只输出JSON，不要其他文字」\n\n## 可用布局及输出规范（选择其一，将其规范嵌入 analysis_prompt）\n\n{$specs_text}\n\n{$template_opts_spec}\n\n## 常用分析领域参考\n- 自我剖析：识别个人模式、盲区、价值观、成长轨迹（推荐 cards/mixed/mindmap）\n- 趋势分析：发现情绪、行为、关注点的变化趋势（推荐 line/mixed/calendar）\n- 节点总结：提炼关键转折点、里程碑事件和重要决定（推荐 timeline/report/mixed）\n- 报告生成：综合多维度分析和可视化，生成结构化报告（推荐 report/mixed）\n\n根据用户需求「{$description}」：\n1. 参考上述分析领域，从布局中选择最合适的一个\n2. 编写 analysis_prompt = 分析方法说明 + 你选中的那个布局的输出规范原文 + 「只输出JSON，不要其他文字」\n3. 根据应用特点选择 template_opts（input_type + features + style），让交互体验贴合功能\n\n返回严格的 JSON（不要带注释）：\n{\n  \"name\": \"应用名称（2-6字）\",\n  \"description\": \"20-50字的功能说明\",\n  \"analysis_prompt\": \"自包含的完整提示词\",\n  \"result_layout\": \"cards|list|mixed|timeline|mindmap|wordcloud|line|calendar|report\",\n  \"template_opts\": {\n    \"input_type\": \"scope|keyword|question|date_range|none\",\n    \"features\": [\"surprise\"],\n    \"style\": \"default|minimal|explorer\"\n  }\n}\n\n只输出 JSON，不要其他文字。",
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
        'visibility' => 'private',
        'analysis_config' => [
            'prompt' => $generated['analysis_prompt'],
            'result_layout' => $generated['result_layout'] ?? 'mixed',
            'template_opts' => $generated['template_opts'] ?? ['input_type' => 'scope', 'features' => [], 'style' => 'default'],
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

    // 无 API key 时提前返回友好提示，避免无意义的后续处理
    $api_key = get_ai_api_key();
    if (empty($api_key)) {
        json_response(['error' => '请先在设置中配置 AI API Key，然后即可使用洞见分析功能'], 400);
    }

    $opts = $config['template_opts'] ?? [];
    $features = $opts['features'] ?? [];
    $select_first = in_array('select_first', $features);

    $data = body_json();
    $scope = $data['scope'] ?? 'all';
    $mode = $data['mode'] ?? '';
    $keyword = trim($data['keyword'] ?? '');
    $question = trim($data['question'] ?? '');
    $date_start = $data['date_start'] ?? '';
    $date_end = $data['date_end'] ?? '';
    $depth = intval($data['depth'] ?? 2);

    // 惊喜模式：随机选一篇文章
    if ($mode === 'surprise') {
        $articles = resolve_insights_articles($scope);
        if (empty($articles)) json_response(['error' => '没有可分析的文章'], 400);
        $article = $articles[array_rand($articles)];
        $articles = [$article];
    } else {
        $articles = resolve_insights_articles($scope);
        if (empty($articles)) json_response(['error' => '没有可分析的文章'], 400);
    }

    // 日期范围过滤
    if ($date_start || $date_end) {
        $articles = array_filter($articles, function($a) use ($date_start, $date_end) {
            $d = substr($a['created_at'] ?? '', 0, 10);
            if ($date_start && $d < $date_start) return false;
            if ($date_end && $d > $date_end) return false;
            return true;
        });
        $articles = array_values($articles);
        if (empty($articles)) json_response(['error' => '所选日期范围内没有文章'], 400);
    }

    // 对比模式：合并两个范围的文章
    $scope_b = $data['scope_b'] ?? '';
    if ($scope_b && $scope_b !== $scope) {
        $articles_b = resolve_insights_articles($scope_b);
        if (!empty($articles_b)) {
            $articles = array_merge($articles, $articles_b);
            usort($articles, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        }
    }

    $total_articles = count($articles);

    // ---- 挑选阶段：AI 先筛选相关文章 ----
    if ($select_first && $total_articles > 3) {
        // 构建轻量文章摘要列表供 AI 挑选
        $article_list = '';
        foreach ($articles as $a) {
            $d = substr($a['created_at'] ?? '', 0, 10);
            $t = $a['title'] ?? '';
            $preview = $a['content'] ?? '';
            if (function_exists('mb_strlen') ? mb_strlen($preview) > 200 : strlen($preview) > 200) {
                $preview = (function_exists('mb_substr') ? mb_substr($preview, 0, 200) : substr($preview, 0, 200)) . '...';
            }
            $tags = implode(', ', $a['tags'] ?? []);
            $article_list .= "[{$a['id']}] {$d} 《{$t}》" . ($tags ? " 标签：{$tags}" : "") . "\n  > {$preview}\n\n";
        }

        $select_context = '';
        if ($question) $select_context .= "用户的问题：{$question}\n";
        if ($keyword) $select_context .= "用户关注的关键词：{$keyword}\n";

        $select_result = call_deepseek(
            "你是一个精准的信息检索助手。用户有一个分析需求，你需要从文章列表中挑选出与分析需求相关的文章。\n\n规则：\n1. 只选真正相关的文章，无关的不要选\n2. 如果几乎所有文章都相关，可以全部选中（这种情况请说明理由）\n3. 最少选1篇\n\n返回严格JSON：\n{\n  \"selected_ids\": [\"文章id\"],\n  \"reason\": \"20字内简述挑选依据\",\n  \"select_all\": false\n}\n\n只输出JSON。",
            "{$select_context}以下是所有文章的列表，请挑选与分析需求相关的文章：\n\n{$article_list}",
            0.3,
            1024
        );

        if (!isset($select_result['error'])) {
            $selection = parse_ai_json($select_result['text'] ?? '');
            if ($selection && !empty($selection['selected_ids'])) {
                $selected_ids = $selection['selected_ids'];
                $articles = array_filter($articles, fn($a) => in_array($a['id'], $selected_ids));
                $articles = array_values($articles);
            }
            // 如果 AI 选不出来或选了全部，保持原样
        }
    }

    $maxPerArticle = $depth === 1 ? 200 : ($depth === 3 ? 0 : 400);
    $catalog = build_article_catalog($articles, $maxPerArticle);

    // 构建用户消息，融入关键词/问题
    $extra_context = '';
    if ($keyword) $extra_context .= "用户关注关键词：{$keyword}\n";
    if ($question) $extra_context .= "用户提出的问题：{$question}\n";
    if ($scope_b && $scope_b !== $scope) $extra_context .= "这是一个对比分析，文章来自两个不同的范围。请关注两者的差异和共性。\n";
    $extra_context .= "分析深度：{$depth}（1=简短, 2=标准, 3=深度）\n";

    // 深度影响 token 限制
    $max_tokens = $depth === 1 ? 1024 : ($depth === 3 ? 4096 : 2048);

    // 深度=3 且内容过多时：分批阅读 + 综合合成
    if ($depth === 3 && strlen($catalog) > 15000) {
        $chunk_size = max(1, (int)ceil(count($articles) / (int)ceil(strlen($catalog) / 10000)));
        $chunks = array_chunk($articles, $chunk_size);
        $summaries = [];

        foreach ($chunks as $i => $chunk) {
            $chunk_catalog = build_article_catalog($chunk, 0);
            $chunk_result = call_deepseek(
                "你是一个分析助手。请仔细阅读以下日记，提取所有关键信息：事件、情绪、决策、人物、模式、转折点。保留具体日期和原文关键句。用自然段落输出，200-500字。",
                "请分析以下日记（第 " . ($i+1) . "/" . count($chunks) . " 批）：\n\n{$chunk_catalog}",
                0.3,
                2048
            );
            if (!isset($chunk_result['error'])) {
                $summaries[] = $chunk_result['text'];
            }
        }

        if (empty($summaries)) json_response(['error' => '分析失败：所有批次均未能完成，请检查 API 配置或稍后重试'], 500);

        $synthesis_input = "以下是从用户所有日记中分批提取的关键信息摘要，请基于这些信息完成分析：\n\n" . implode("\n\n---\n\n", $summaries);
        $result = call_deepseek($prompt, $synthesis_input, 0.7, $max_tokens);

        // 合成失败时降级：将各批摘要作为原始结果返回
        if (isset($result['error'])) {
            $fallback = "## 分批阅读摘要（综合合成未能完成）\n\n" . implode("\n\n---\n\n", $summaries);
            json_response([
                'title' => '分批分析摘要',
                'summary' => '深度分析综合合成时出错（' . $result['error'] . '），以下为各批次的关键信息摘要供手动参考。',
                'insight' => $fallback,
                '_layout' => 'mixed',
                '_article_count' => count($articles),
            ]);
        }
    } else {
        $user_msg = "请分析以下日记：\n\n{$extra_context}\n{$catalog}";
        $result = call_deepseek($prompt, $user_msg, 0.7, $max_tokens);
    }

    if (isset($result['error'])) {
        // 判断是否为 API key 缺失（可能在请求过程中被清空）
        $msg = $result['error'];
        if (strpos($msg, 'API Key') !== false || strpos($msg, '配置') !== false) {
            json_response(['error' => $msg], 400);
        }
        json_response(['error' => 'AI 分析未能完成：' . $msg], 500);
    }
    $analysis = parse_ai_json($result['text'] ?? '');
    if (!$analysis) {
        // 解析失败：返回原始文本作为降级显示
        json_response([
            'title' => '分析结果',
            'summary' => 'AI 返回了非标准格式的结果，以下是原始内容：',
            'insight' => $result['text'] ?? '(空)',
            '_layout' => 'mixed',
            '_article_count' => count($articles),
        ]);
    }

    $analysis['_layout'] = $config['result_layout'] ?? 'mixed';
    $analysis['_article_count'] = count($articles);
    if ($select_first && $total_articles > 3) {
        $analysis['_selection'] = [
            'total' => $total_articles,
            'selected' => count($articles),
            'reason' => $selection['reason'] ?? '',
        ];
    }
    json_response($analysis);
});

$router->post('/api/insights/run/{id}/follow-up', function($id) {
    require_login();
    $app = json_read(DATA_DIR . '/insights_apps/' . $id . '.json');
    if (!$app) json_response(['error' => '应用不存在'], 404);

    $config = $app['analysis_config'] ?? [];
    $original_prompt = $config['prompt'] ?? '';

    $data = body_json();
    $question = trim($data['question'] ?? '');
    $prev_result = $data['prev_result'] ?? '';
    $scope_label = $data['scope_label'] ?? '所有文章';

    if (empty($question)) json_response(['error' => '请输入追问内容'], 400);

    $system = "你正在与用户进行关于日记分析的深入对话。之前你用以下视角分析过用户的日记：\n\n{$original_prompt}\n\n现在用户对分析结果有进一步的问题，请基于之前的分析上下文回答。你的回答应该温和、有洞察力，引用日记中的具体内容作为支持。如果是开放式问题，可以提出反思性问题帮助用户深入思考。\n\n回答控制在300字以内，使用自然段落，不要JSON格式。";

    $context = "分析范围：{$scope_label}\n\n之前的分析结果摘要：\n{$prev_result}\n\n用户追问：{$question}";

    $result = call_deepseek($system, $context, 0.7, 1024);

    if (isset($result['error'])) json_response($result, 500);
    json_response(['answer' => $result['text'] ?? '抱歉，我暂时无法回答这个问题，请稍后再试。']);
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

// ===== 回响追问 =====
$router->post('/api/insights/echo', function() {
    require_login();
    $data = body_json();
    $scope = $data['scope'] ?? 'all';
    $articles = resolve_insights_articles($scope);
    if (empty($articles)) json_response(['error' => '没有可分析的文章'], 400);
    $catalog = build_article_catalog($articles, 300);

    $result = call_deepseek(
        "你是一个温柔而敏锐的写作陪伴者。你的任务是通读用户的日记，找到那些「被一带而过但值得追问」的话题。\n\n什么是好话题？\n1. 用户曾提到某个挑战、困难或决定，但没有后续交代\n2. 用户一笔带过却暗含情绪的事件（如\"今天有点不开心但不想多说\"）\n3. 用户曾立下的目标、计划或承诺，后续日记中再未提及\n4. 反复出现的隐约模式——某个名字、某类场景、某种情绪\n\n你需要做的事：\n1. 引用原文中具体的那一两句话\n2. 用友善、好奇、不带压力的语气问一个问题\n3. 解释为什么你觉得这个话题值得重新提起\n\n返回严格JSON（不要注释）：\n{\n  \"question\": \"温暖而具体的追问（30-80字，语气像朋友关心）\",\n  \"context\": {\n    \"date\": \"原文日期 YYYY-MM-DD\",\n    \"title\": \"原文标题\",\n    \"quote\": \"直接引用的原句（15-50字）\",\n    \"article_id\": \"原文ID\"\n  },\n  \"why\": \"一句话解释为什么挑这个话题（10-20字）\"\n}\n\n只输出JSON。",
        "请通读以下日记，找到一个可追问的话题：\n\n{$catalog}",
        0.8,
        1024
    );

    if (isset($result['error'])) json_response($result, 500);
    $analysis = parse_ai_json($result['text'] ?? '');
    json_response($analysis ?: ['error' => '未能找到合适的话题，请稍后再试'], $analysis ? 200 : 500);
});

$router->post('/api/insights/echo/draft', function() {
    require_login();
    $data = body_json();
    $question = trim($data['question'] ?? '');
    $answer = trim($data['answer'] ?? '');
    $context = $data['context'] ?? [];
    if (empty($answer)) json_response(['error' => '请先写下你的回答'], 400);

    $ctx_text = '';
    if (!empty($context['date'])) $ctx_text .= "原文日期：{$context['date']}\n";
    if (!empty($context['title'])) $ctx_text .= "原标题：{$context['title']}\n";
    if (!empty($context['quote'])) $ctx_text .= "原文引用：{$context['quote']}\n";

    $result = call_deepseek(
        "你是一个日记编辑助手。用户回答了一个关于过往经历的追问，请将回答整理为一篇自然、真诚的日记。\n\n规则：\n1. 使用第一人称（\"我\"），保持用户的口吻和风格\n2. 开头自然承接到原始话题，不要生硬的\"关于...我的回答是...\"\n3. 可以加入适当的反思和情感表达\n4. 标题简洁有力（2-10字），不要带书名号\n5. 内容控制在200-500字\n6. 标签2-3个，中文\n\n返回严格JSON：\n{\n  \"title\": \"日记标题\",\n  \"content\": \"日记正文\",\n  \"tags\": [\"标签1\", \"标签2\"]\n}\n\n只输出JSON。",
        "追问：{$question}\n\n用户的回答：{$answer}\n\n原始上下文：\n{$ctx_text}",
        0.7,
        1024
    );

    if (isset($result['error'])) json_response($result, 500);
    $draft = parse_ai_json($result['text'] ?? '');
    if (!$draft || empty($draft['content'])) {
        // 降级：直接用用户回答作为内容
        json_response([
            'title' => '回响·' . date('m-d'),
            'content' => $answer,
            'tags' => ['回响', '反思'],
        ]);
        return;
    }
    json_response($draft);
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
            'write'       => '写作',
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
