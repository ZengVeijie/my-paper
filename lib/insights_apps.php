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
        [
            'id' => 'echo',
            'name' => '回响追问',
            'description' => 'AI 找到你曾经一带而过的挑战或心事，温柔追问近况，帮你写成新日记',
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
    $user = current_user();
    if (is_dir($dir)) {
        foreach (json_list($dir) as $app) {
            $visibility = $app['visibility'] ?? 'private';
            $owner_id = $app['user_id'] ?? '';
            // 自己的应用全部可见；他人的仅公开的可见
            if ($owner_id === $user['id'] || $visibility === 'public') {
                $custom[] = $app;
            }
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

    if (str_starts_with($scope, 'article:')) {
        $ids = explode(',', substr($scope, 8));
        $ids = array_map('trim', $ids);
        $articles = array_filter($articles, fn($a) => in_array($a['id'], $ids, true));
    } elseif (str_starts_with($scope, 'tag:')) {
        $tags = explode(',', substr($scope, 4));
        $tags = array_map('trim', $tags);
        $tags = array_filter($tags, fn($t) => $t !== '');
        $articles = array_filter($articles, function($a) use ($tags) {
            $article_tags = $a['tags'] ?? [];
            return !empty(array_intersect($article_tags, $tags));
        });
    } elseif (str_starts_with($scope, 'collection:')) {
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
    $no_truncate = ($maxPerArticle <= 0);
    foreach ($articles as $a) {
        $date = substr($a['created_at'] ?? '', 0, 10);
        $content = $a['content'] ?? '';
        if (!$no_truncate) {
            $len = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
            if ($len > $maxPerArticle * 3) {
                $content = function_exists('mb_substr') ? mb_substr($content, 0, $maxPerArticle * 3) : substr($content, 0, $maxPerArticle * 3);
            }
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
    $config = $app['analysis_config'] ?? [];
    $opts = $config['template_opts'] ?? [];
    // 支持数组，向后兼容字符串
    $raw = $opts['input_type'] ?? 'scope';
    $input_types = is_array($raw) ? $raw : [$raw];
    $features = $opts['features'] ?? [];
    $style = $opts['style'] ?? 'default';
    $auto_run = in_array('auto_run', $features);

    // 生成合集 scope 选项（供 scope / compare 使用）
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

    // 样式 CSS 类名
    $style_class = $style !== 'default' ? ' ai-app-' . $style : '';
    $wrapper_class = 'ai-app-panel' . $style_class;

    // ---- 构建输入区（支持多个 input_type 并存） ----
    $widgets = [];
    $has_scope = false; // 追踪 scope 控件是否存在（compare 需要复用其值）
    foreach ($input_types as $it) {
        $w = '';
        switch ($it) {
            case 'keyword':
                $ph = htmlspecialchars($opts['placeholder'] ?? '输入关键词或主题...', ENT_QUOTES, 'UTF-8');
                $w = "<input type=\"text\" id=\"ai-keyword-{$app_id}\" placeholder=\"{$ph}\"
                    style=\"flex:1;min-width:160px;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.82rem;\"
                    onkeydown=\"if(event.key==='Enter')runInsightsApp('{$app_id}')\">";
                break;

            case 'question':
                $ph = htmlspecialchars($opts['placeholder'] ?? '输入你想探索的问题...', ENT_QUOTES, 'UTF-8');
                $qhint = htmlspecialchars($opts['qhint'] ?? '例如：我最近的情绪波动有什么规律？', ENT_QUOTES, 'UTF-8');
                $w = "<div style=\"flex:1;min-width:160px;\">
                    <input type=\"text\" id=\"ai-question-{$app_id}\" placeholder=\"{$ph}\"
                        style=\"width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.82rem;\"
                        onkeydown=\"if(event.key==='Enter')runInsightsApp('{$app_id}')\">
                    <div style=\"font-size:0.7rem;color:var(--text-muted);margin-top:3px;\">{$qhint}</div>
                </div>";
                break;

            case 'date_range':
                $w = "<div style=\"display:flex;gap:5px;align-items:center;flex:1;min-width:160px;\">
                    <input type=\"date\" id=\"ai-date-start-{$app_id}\"
                        style=\"flex:1;padding:6px 7px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.78rem;\">
                    <span style=\"color:var(--text-muted);font-size:0.78rem;flex-shrink:0;\">至</span>
                    <input type=\"date\" id=\"ai-date-end-{$app_id}\"
                        style=\"flex:1;padding:6px 7px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.78rem;\">
                </div>";
                break;

            case 'scope':
                $has_scope = true;
                $w = "<select id=\"ai-scope-{$app_id}\" style=\"flex:1;min-width:160px;padding:7px 8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.82rem;\">
                    <option value=\"all\">所有文章</option>
                    {$scope_opts}
                </select>";
                break;

            case 'article_picker':
                $user_articles = resolve_insights_articles('all');
                $art_opts = '';
                foreach ($user_articles as $art) {
                    $art_date = substr($art['created_at'] ?? '', 0, 10);
                    $art_title = htmlspecialchars($art['title'] ?: '无标题', ENT_QUOTES, 'UTF-8');
                    $art_id = htmlspecialchars($art['id'], ENT_QUOTES, 'UTF-8');
                    $art_opts .= "<option value=\"{$art_id}\">{$art_date} {$art_title}</option>";
                }
                $w = "<select id=\"ai-article-{$app_id}\" multiple size=\"5\" class=\"ai-picker\"
                    style=\"flex:1;min-width:200px;\"
                    title=\"可多选（Ctrl+点击），不选则分析全部\">{$art_opts}</select>";
                break;

            case 'tag_filter':
                $tag_ph = htmlspecialchars($opts['placeholder'] ?? '输入标签，逗号分隔，如：工作, 心情', ENT_QUOTES, 'UTF-8');
                $all_tags = [];
                foreach (resolve_insights_articles('all') as $art) {
                    foreach ($art['tags'] ?? [] as $t) $all_tags[] = $t;
                }
                $all_tags = array_unique($all_tags);
                sort($all_tags);
                $dl_id = 'ai-tags-dl-' . $app_id;
                $dl_html = '';
                if (!empty($all_tags)) {
                    $dl_html = "<datalist id=\"{$dl_id}\">";
                    foreach ($all_tags as $t) {
                        $dl_html .= '<option value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
                    }
                    $dl_html .= '</datalist>';
                }
                $w = "<input type=\"text\" id=\"ai-tags-{$app_id}\" list=\"{$dl_id}\" placeholder=\"{$tag_ph}\"
                    style=\"flex:1;min-width:160px;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.82rem;\"
                    onkeydown=\"if(event.key==='Enter')runInsightsApp('{$app_id}')\">{$dl_html}";
                break;

            case 'none':
                // 不生成控件
                break;
        }
        if ($w !== '') $widgets[] = $w;
    }

    // ---- 构建功能组件 ----
    $features_html = '';

    // 惊喜按钮
    if (in_array('surprise', $features)) {
        $features_html .= <<<HTML
        <button class="btn btn-sm ai-surprise-btn" onclick="runInsightsApp('{$app_id}', 'surprise')" title="随机抽取一篇文章，用新视角发现意外洞察" style="flex-shrink:0;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="8" height="8" rx="1"/><rect x="14" y="2" width="8" height="8" rx="1"/><rect x="2" y="14" width="8" height="8" rx="1"/><rect x="14" y="14" width="8" height="8" rx="1"/></svg>
            <span style="font-size:0.8rem;">随机一篇</span>
        </button>
        HTML;
    }

    // 深度选择器
    if (in_array('depth_slider', $features)) {
        $features_html .= <<<HTML
        <div class="depth-picker" id="ai-depth-{$app_id}" title="1=快速概览 / 2=标准分析 / 3=深度阅读">
            <span class="depth-opt active" data-d="1" onclick="pickDepth('{$app_id}',this,1)">简</span>
            <span class="depth-opt" data-d="2" onclick="pickDepth('{$app_id}',this,2)">标</span>
            <span class="depth-opt" data-d="3" onclick="pickDepth('{$app_id}',this,3)">深</span>
        </div>
        HTML;
    }

    // 对比模式：两个 scope 选择器
    $compare_html = '';
    if (in_array('compare', $features)) {
        $compare_html = <<<HTML
        <div style="display:flex;gap:6px;align-items:center;flex:1;min-width:200px;">
            <select id="ai-scope-a-{$app_id}" style="flex:1;padding:7px 8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.78rem;">
                <option value="all">范围A：所有文章</option>
                {$scope_opts}
            </select>
            <span style="color:var(--text-muted);font-weight:600;">vs</span>
            <select id="ai-scope-b-{$app_id}" style="flex:1;padding:7px 8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.78rem;">
                <option value="all">范围B：所有文章</option>
                {$scope_opts}
            </select>
        </div>
        HTML;
    }

    // 操作按钮
    $has_none = in_array('none', $input_types);
    $show_button = !($auto_run && $has_none);
    $btn_label = in_array('question', $input_types) ? '探索' : '开始分析';
    $btn_html = $show_button ? "<button class=\"btn btn-primary btn-sm\" onclick=\"runInsightsApp('{$app_id}')\" style=\"flex-shrink:0;\">{$btn_label}</button>" : '';

    // ---- 组装 ----
    $panel_html = '';
    $widget_count = count($widgets);

    if (in_array('compare', $features)) {
        // 对比模式：widgets 在上，vs 选择器在下方独立行
        $panel_html = "<div class=\"ai-controls\" style=\"display:flex;flex-direction:column;gap:10px;margin-bottom:16px;\">";
        if ($widget_count > 0) {
            $panel_html .= "<div style=\"display:flex;gap:8px;align-items:center;flex-wrap:wrap;\">" . implode('', $widgets) . "</div>";
        }
        $panel_html .= "<div style=\"display:flex;flex-direction:column;gap:8px;\">{$compare_html}<div style=\"display:flex;gap:8px;align-items:center;flex-wrap:wrap;\">{$features_html}{$btn_html}</div></div>";
        $panel_html .= '</div>';
    } elseif ($widget_count > 1) {
        // 多输入：纵向堆叠，每行一个控件，最后一行放 features + 按钮
        $panel_html = "<div class=\"ai-controls\" style=\"display:flex;flex-direction:column;gap:8px;margin-bottom:16px;\">";
        foreach ($widgets as $i => $w) {
            $panel_html .= "<div style=\"display:flex;gap:8px;align-items:center;\">{$w}</div>";
        }
        if ($features_html || $btn_html) {
            $panel_html .= "<div style=\"display:flex;gap:8px;align-items:center;flex-wrap:wrap;\">{$features_html}{$btn_html}</div>";
        }
        $panel_html .= '</div>';
    } elseif ($widget_count === 1 || $features_html || $btn_html) {
        // 单输入或无输入（有 features/按钮）：水平一行
        $panel_html = "<div class=\"ai-controls\" style=\"display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;\">";
        $panel_html .= implode('', $widgets) . $features_html . $btn_html;
        $panel_html .= '</div>';
    }

    // 计数徽章
    $count_badge_html = '';
    if (in_array('count_badge', $features)) {
        $count_badge_html = <<<HTML
        <span id="ai-count-{$app_id}" class="ai-count-badge" style="display:none;"></span>
        HTML;
    }

    $auto_run_attr = $auto_run ? ' data-auto-run="true"' : '';

    return <<<HTML
<section class="{$wrapper_class}"{$auto_run_attr}>
    <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
        <h2>{$name}</h2>
        {$count_badge_html}
    </div>
    <p class="section-desc">{$desc}</p>
    {$panel_html}
    <div id="ai-loading-{$app_id}" class="ai-loading-panel">
        <div class="ai-loading-spinner"></div>
        <p style="color:var(--text-muted);">AI 正在分析...</p>
        <p style="font-size:0.75rem;color:var(--text-muted);">这可能需要 10-30 秒</p>
    </div>
    <div id="ai-result-{$app_id}"></div>
    <div id="ai-error-{$app_id}" style="display:none;color:var(--danger);margin-top:12px;"></div>
</section>
HTML;
}

// 标准化 AI 返回结果：将 AI 多变字段名映射到前端统一字段
function normalize_ai_result(array $analysis, string $layout, int $article_count): array {
    $analysis['_layout'] = $layout;
    $analysis['_article_count'] = $article_count;

    // 标准化 items 数组中的每个 item
    if (!empty($analysis['items']) && is_array($analysis['items'])) {
        foreach ($analysis['items'] as &$item) {
            if (!is_array($item)) continue;
            $item['badge']  = $item['badge']  ?? $item['type'] ?? $item['label'] ?? $item['bias'] ?? $item['category'] ?? $item['mood'] ?? $item['sentiment'] ?? '';
            $item['body']   = $item['body']   ?? $item['insight'] ?? $item['explanation'] ?? $item['intervention'] ?? $item['content'] ?? $item['summary'] ?? $item['reasoning'] ?? $item['description'] ?? $item['text'] ?? '';
            $item['quote']  = $item['quote']  ?? $item['evidence'] ?? '';
            $item['sub']    = $item['sub']    ?? $item['suggestion'] ?? $item['advice'] ?? $item['detail'] ?? $item['note'] ?? '';
        }
        unset($item);
    }

    // 标准化根级字段（mixed/report 等布局）
    $analysis['body']  = $analysis['body']  ?? $analysis['insight'] ?? $analysis['summary'] ?? $analysis['reasoning'] ?? $analysis['content'] ?? '';
    $analysis['badge'] = $analysis['badge'] ?? $analysis['type'] ?? $analysis['label'] ?? '';
    $analysis['quote'] = $analysis['quote'] ?? $analysis['evidence'] ?? '';
    $analysis['sub']   = $analysis['sub']   ?? $analysis['suggestion'] ?? $analysis['detail'] ?? '';

    return $analysis;
}
