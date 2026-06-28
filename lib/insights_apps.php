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
            'icon' => '💭',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'related',
            'name' => '相关回顾',
            'description' => '选择一篇文章，AI 找出历史中主题相关的内容',
            'icon' => '🔗',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'summary',
            'name' => '周月总结',
            'description' => '基于选定时间范围，AI 生成回顾总结',
            'icon' => '📅',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'stats',
            'name' => '写作统计',
            'description' => '基于文章数据生成统计图表与 AI 洞察',
            'icon' => '📊',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'tasks',
            'name' => '待办纵览',
            'description' => '汇总所有文章中的待办事项，追踪完成进度',
            'icon' => '☑',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'mbti',
            'name' => 'MBTI 分析',
            'description' => 'AI 深度分析日记内容，推断 MBTI 人格类型并提供推理',
            'icon' => '🧠',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'cbt',
            'name' => 'CBT 疗法',
            'description' => 'AI 识别笔记中的认知扭曲，提供 CBT 干预建议',
            'icon' => '💡',
            'source' => 'builtin',
            'render_type' => 'php',
        ],
        [
            'id' => 'blindspot',
            'name' => '盲区探索',
            'description' => 'AI 发现你看不见的 3 个关于自己的隐藏真相',
            'icon' => '🔍',
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
