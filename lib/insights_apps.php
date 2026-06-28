<?php
/**
 * 洞见应用系统 - 应用注册中心 & 工具函数
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// ==================== 内置应用定义 ====================

function get_builtin_insights_apps(): array {
    return [
        [
            'id' => 'sentiment',
            'name' => '情感分析',
            'description' => 'AI 自动分析或手动标记每篇文章的情感基调',
            'icon' => '',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'related',
            'name' => '相关回顾',
            'description' => '选择一篇文章，AI 找出历史中主题相关的内容',
            'icon' => '',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'summary',
            'name' => '周月总结',
            'description' => '基于选定时间范围，AI 生成回顾总结',
            'icon' => '',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'stats',
            'name' => '写作统计',
            'description' => '基于文章数据生成统计图表与 AI 洞察',
            'icon' => '',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'tasks',
            'name' => '待办纵览',
            'description' => '汇总所有文章中的待办事项，追踪完成进度',
            'icon' => '',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'mbti',
            'name' => 'MBTI 分析',
            'description' => 'AI 深度分析日记内容，推断 MBTI 人格类型并提供推理',
            'icon' => '',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'cbt',
            'name' => 'CBT 疗法',
            'description' => 'AI 识别笔记中的认知扭曲，提供 CBT 干预建议',
            'icon' => '',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'blindspot',
            'name' => '盲区探索',
            'description' => 'AI 发现你看不见的 3 个关于自己的隐藏真相',
            'icon' => '',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
    ];
}

// ==================== 应用仓库 ====================

function get_all_insights_apps(): array {
    $builtin = get_builtin_insights_apps();
    $custom = [];
    $dir = DATA_DIR . '/insights_apps';
    if (is_dir($dir)) {
        foreach (json_list($dir) as $app) {
            $custom[] = $app;
        }
        usort($custom, fn($a, $b) => strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? ''));
    }
    return array_merge($builtin, $custom);
}

function get_user_insights_apps(): array {
    $user = current_user();
    return $user['insights_apps'] ?? [];
}

function save_user_insights_apps(array $app_ids): void {
    $user = current_user();
    $path = DATA_DIR . '/users/' . $user['id'] . '.json';
    $user['insights_apps'] = array_values($app_ids);
    json_write($path, $user);
}

function get_enabled_insights_apps(): array {
    $all = get_all_insights_apps();
    $enabled_ids = get_user_insights_apps();
    $map = [];
    foreach ($all as $app) {
        $map[$app['id']] = $app;
    }
    $enabled = [];
    foreach ($enabled_ids as $id) {
        if (isset($map[$id])) {
            $enabled[] = $map[$id];
        }
    }
    return $enabled;
}

// ==================== 工具函数 ====================

function resolve_insights_articles(string $scope): array {
    $user = current_user();
    $articles = json_list(DATA_DIR . '/articles');

    if (str_starts_with($scope, 'collection:')) {
        $coll_id = substr($scope, 11);
        $coll = json_read(DATA_DIR . '/collections/' . $coll_id . '.json');
        if (!$coll) return [];
        $article_ids = $coll['article_ids'] ?? [];
        $articles = array_filter($articles, fn($a) => in_array($a['id'], $article_ids));
    } else {
        $collab_ids = [];
        $collections = json_list(DATA_DIR . '/collections');
        foreach ($collections as $c) {
            if (in_array($user['id'], $c['collaborator_ids'] ?? [])) {
                foreach ($c['article_ids'] ?? [] as $aid) $collab_ids[] = $aid;
            }
        }
        $collab_ids = array_unique($collab_ids);
        $articles = array_filter($articles, fn($a) =>
            ($a['user_id'] ?? '') === $user['id']
            || in_array($a['id'], $collab_ids)
            || $user['role'] === 'admin'
        );
    }

    usort($articles, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
    return array_values($articles);
}

function build_article_catalog(array $articles, int $maxPerArticle = 400): string {
    $catalog = '';
    foreach ($articles as $a) {
        $date = substr($a['created_at'] ?? '', 0, 10);
        $content = $a['content'] ?? '';
        $len = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
        if ($len > $maxPerArticle * 3) {
            $content = function_exists('mb_substr') ? mb_substr($content, 0, $maxPerArticle * 3) : substr($content, 0, $maxPerArticle * 3);
        }
        $catalog .= "--- {$date} {$a['title']} ---\n{$content}\n\n";
    }
    return $catalog;
}

function parse_ai_json(string $text): ?array {
    $data = json_decode($text, true);
    if (is_array($data)) return $data;
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) return $data;
    }
    return null;
}

// ==================== AI 应用组件库 & 模板组装 ====================

/**
 * 生成 AI 应用的标准 HTML/JS 模板
 *
 * 组件库包含：
 *   - scope_selector: 分析范围选择器（全部文章 / 指定合辑）
 *   - action_button: 触发分析的按钮
 *   - loading_panel: 加载等待状态
 *   - result_area: 结果渲染区域
 *   - error_display: 错误信息展示
 *   - result_cards: 卡片列表（适用于多项结果）
 *   - result_mixed: 混合布局（标题+正文+子项列表）
 *   - result_badge: 标签/徽章
 *   - result_quote: 引用块
 *
 * 所有 AI 应用共享同一套 UI 组件和交互逻辑，AI 只负责生成 prompt。
 */
function build_ai_app_template(array $app): string {
    $app_id = $app['id'];
    $name = htmlspecialchars($app['name'] ?? '', ENT_QUOTES, 'UTF-8');
    $desc = htmlspecialchars($app['description'] ?? '', ENT_QUOTES, 'UTF-8');
    $layout = $app['analysis_config']['result_layout'] ?? 'cards';

    // 生成合集 scope 选项
    $scope_opts = '';
    $collections = json_list(DATA_DIR . '/collections');
    $user = current_user();
    foreach ($collections as $c) {
        $can = ($c['user_id'] ?? '') === $user['id']
            || in_array($user['id'], $c['collaborator_ids'] ?? [])
            || $user['role'] === 'admin';
        if ($can && !empty($c['article_ids'])) {
            $val = htmlspecialchars('collection:' . $c['id'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars('合辑: ' . $c['name'], ENT_QUOTES, 'UTF-8');
            $scope_opts .= "<option value=\"{$val}\">{$label}</option>";
        }
    }

    return <<<HTML
<section class="settings-section">
    <h2>{$name}</h2>
    <p class="section-desc">{$desc}</p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
        <select id="ai-scope-{$app_id}" style="flex:1;min-width:200px;padding:8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.85rem;">
            <option value="all">所有文章</option>
            {$scope_opts}
        </select>
        <button class="btn btn-primary btn-sm" onclick="runInsightsApp('{$app_id}')">开始分析</button>
    </div>
    <div id="ai-loading-{$app_id}" style="display:none;text-align:center;padding:40px;">
        <p style="color:var(--text-muted);">AI 正在分析...</p>
        <p style="font-size:0.75rem;color:var(--text-muted);">这可能需要 10-30 秒</p>
    </div>
    <div id="ai-result-{$app_id}"></div>
    <div id="ai-error-{$app_id}" style="display:none;color:var(--danger);"></div>
</section>
HTML;
}

/**
 * 渲染 AI 返回的结构化结果（客户端调用）
 * 在 insights.php 中以 JS 函数形式输出
 */
function get_result_renderer_js(): string {
    return <<<'JS'
function renderAIResult(containerId, data, layout) {
    var el = document.getElementById(containerId);
    if (!el) return;
    if (!data || (Array.isArray(data) && !data.length)) {
        el.innerHTML = '<p style="color:var(--text-muted);">暂无结果</p>';
        return;
    }

    if (layout === 'mixed') {
        el.innerHTML = renderMixedLayout(data);
    } else if (layout === 'cards' && Array.isArray(data)) {
        el.innerHTML = data.map(function(item, i) {
            return renderResultCard(item, i);
        }).join('');
    } else if (layout === 'list' && Array.isArray(data)) {
        el.innerHTML = '<div>' + data.map(function(item, i) {
            return renderResultListItem(item, i);
        }).join('') + '</div>';
    } else {
        el.innerHTML = renderMixedLayout(data);
    }
}

function renderResultCard(item, idx) {
    var title = item.title || item.name || item.type || ('#' + (idx+1));
    var body = item.insight || item.intervention || item.content || item.summary || item.reasoning || '';
    var sub = item.quote || item.evidence || item.suggestion || item.detail || '';
    var badge = item.type || item.label || item.confidence || '';
    return '<div class="summary-card" style="margin-bottom:12px;">' +
        (badge ? '<span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:0.75rem;font-family:var(--font-ui);background:var(--accent-light);color:var(--accent);margin-bottom:8px;">' + esc(badge) + '</span>' : '') +
        '<h3 style="margin:0 0 8px;font-size:1rem;">' + esc(title) + '</h3>' +
        '<p style="line-height:1.8;font-size:0.9rem;white-space:pre-wrap;">' + esc(body) + '</p>' +
        (sub ? '<div style="background:var(--bg);padding:8px 12px;border-left:3px solid var(--accent);margin:8px 0;font-size:0.85rem;line-height:1.6;color:var(--text-secondary);">' + esc(sub) + '</div>' : '') +
    '</div>';
}

function renderResultListItem(item, idx) {
    var title = item.title || item.name || ('#' + (idx+1));
    var body = item.content || item.summary || item.text || '';
    return '<div style="padding:10px 0;border-bottom:1px solid var(--border-light);">' +
        '<div style="font-weight:500;font-size:0.9rem;">' + esc(title) + '</div>' +
        (body ? '<div style="font-size:0.85rem;color:var(--text-muted);line-height:1.6;">' + esc(body) + '</div>' : '') +
    '</div>';
}

function renderMixedLayout(data) {
    var html = '<div class="summary-card">';
    if (data.title || data.type) {
        html += '<h3>' + esc(data.title || data.type || '分析结果') + '</h3>';
    }
    var primary = data.insight || data.summary || data.reasoning || data.content || '';
    if (primary) {
        html += '<p style="line-height:1.8;font-size:0.9rem;white-space:pre-wrap;">' + esc(primary) + '</p>';
    }
    if (data.confidence) {
        html += '<div style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">置信度：' + esc(data.confidence) + '</div>';
    }
    var items = data.distortions || data.blindspots || data.events || data.items || data.results || [];
    if (Array.isArray(items) && items.length) {
        html += '<div style="margin-top:16px;">' + items.map(function(item, i) {
            return renderResultCard(item, i);
        }).join('') + '</div>';
    }
    html += '</div>';
    return html;
}

async function runInsightsApp(appId) {
    var scopeEl = document.getElementById('ai-scope-' + appId);
    var loadingEl = document.getElementById('ai-loading-' + appId);
    var resultEl = document.getElementById('ai-result-' + appId);
    var errorEl = document.getElementById('ai-error-' + appId);
    var scope = scopeEl ? scopeEl.value : 'all';

    if (loadingEl) loadingEl.style.display = '';
    if (resultEl) resultEl.innerHTML = '';
    if (errorEl) errorEl.style.display = 'none';

    try {
        var resp = await fetch('/api/insights/run/' + appId, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({scope: scope})
        });
        var r = await resp.json();
        if (loadingEl) loadingEl.style.display = 'none';

        if (r.error) {
            if (errorEl) { errorEl.style.display = ''; errorEl.textContent = r.error; }
            return;
        }

        var layout = r._layout || 'mixed';
        renderAIResult('ai-result-' + appId, r, layout);
    } catch(e) {
        if (loadingEl) loadingEl.style.display = 'none';
        if (errorEl) { errorEl.style.display = ''; errorEl.textContent = '请求失败: ' + e.message; }
    }
}
JS;
}
