<?php
/**
 * 平静之心 - DeepSeek AI 集成
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function get_ai_api_key(): string {
    $user = current_user();
    if (!empty($user['deepseek_api_key'])) return $user['deepseek_api_key'];
    return DEEPSEEK_API_KEY;
}

function get_user_ai_max_tokens(): ?int {
    $user = current_user();
    if (!empty($user['ai_max_tokens']) && is_numeric($user['ai_max_tokens'])) {
        return max(64, min(16384, (int)$user['ai_max_tokens']));
    }
    return null;
}

function sanitize_utf8(string $s): string {
    // Try mbstring first
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        if ($converted !== false) return $converted;
    }
    // Try iconv
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($converted !== false) return $converted;
    }
    // Fallback: strip control chars and hope for the best
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
}

function call_deepseek(string $system_prompt, string $user_message, float $temperature = 0.7, int $max_tokens = 2048): array {
    $api_key = get_ai_api_key();
    if (empty($api_key)) {
        return ['error' => '请先在设置中配置 DeepSeek API Key'];
    }

    $system_prompt = sanitize_utf8($system_prompt);
    $user_message = sanitize_utf8($user_message);

    $user_max = get_user_ai_max_tokens();
    if ($user_max !== null) {
        $max_tokens = $user_max;
    }

    $body = [
        'model' => DEEPSEEK_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_message],
        ],
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
        'stream' => false,
    ];

    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['error' => '请求编码失败: ' . json_last_error_msg()];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n" .
                        "Authorization: Bearer {$api_key}\r\n",
            'content' => $json,
            'timeout' => 120,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents(DEEPSEEK_BASE_URL . '/chat/completions', false, $ctx);

    if ($response === false) {
        $err = error_get_last();
        return ['error' => 'API 请求失败: ' . ($err['message'] ?? '未知错误')];
    }

    // Check HTTP status from response headers
    $status_line = $http_response_header[0] ?? '';
    if (strpos($status_line, '200') === false) {
        $err_body = json_decode($response, true);
        $msg = $err_body['error']['message'] ?? '';
        if (empty($msg)) {
            $msg = substr($response, 0, 500);
        }
        return ['error' => $msg ?: "API 返回错误: {$status_line}"];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
        return ['error' => $data['error']['message'] ?? 'AI API 返回了无法识别的响应'];
    }
    $content = $data['choices'][0]['message']['content'];
    if ($content === '') return ['error' => 'AI 返回了空内容'];

    return ['text' => trim($content)];
}

// ==================== AI Actions ====================

function handle_ai_polish(): void {
    require_login();
    $data = body_json();
    $text = trim($data['text'] ?? '');
    if (empty($text)) json_response(['error' => '请提供要润色的文字'], 400);

    $result = call_deepseek(
        '你是一个文字润色助手。请润色用户提供的文字，使其表达更流畅、更优美，但保持原意不变。直接输出润色后的文字，不要加任何解释或标记。',
        $text,
        0.6
    );
    json_response($result, isset($result['error']) ? 500 : 200);
}

function handle_ai_translate(): void {
    require_login();
    $data = body_json();
    $text = trim($data['text'] ?? '');
    if (empty($text)) json_response(['error' => '请提供要翻译的文字'], 400);
    $target = $data['target'] ?? '英语';

    $result = call_deepseek(
        "你是一个翻译助手。请将用户提供的文字翻译为{$target}。直接输出翻译结果，不要加任何解释或标记。",
        $text,
        0.3
    );
    json_response($result, isset($result['error']) ? 500 : 200);
}

function handle_ai_explain(): void {
    require_login();
    $data = body_json();
    $text = trim($data['text'] ?? '');
    if (empty($text)) json_response(['error' => '请提供要解释的文字'], 400);

    $result = call_deepseek(
        "你是一个博学的知识助手。用户会提供一段包含专有名词、术语或概念的文本。请提取其中的关键名词和概念，用通俗易懂的中文逐一解释，帮助用户理解和学习。格式要求：
- 每个词条单独一行，格式为「**名词**：解释」
- 解释要简明扼要，每个词条不超过两句话
- 按文中出现顺序排列
- 如果文中有外语词汇或缩写，请注明全称
- 如果文中没有专有名词，直接说「未发现需要解释的专有名词」
- 直接输出解释，不要加额外的开场白或总结",
        $text,
        0.5,
        1024
    );
    json_response($result, isset($result['error']) ? 500 : 200);
}

function handle_ai_style(): void {
    require_login();
    $data = body_json();
    $text = trim($data['text'] ?? '');
    if (empty($text)) json_response(['error' => '请提供要转换的文字'], 400);

    $style = $data['style'] ?? '文学优美';
    $result = call_deepseek(
        "你是一个写作风格转换助手。请将用户提供的文字改写为「{$style}」风格。直接输出改写后的文字，不要加任何解释或标记。",
        $text,
        0.8
    );
    json_response($result, isset($result['error']) ? 500 : 200);
}

function handle_ai_format(): void {
    require_login();
    $data = body_json();
    $text = trim($data['text'] ?? '');
    if (empty($text)) json_response(['error' => '请提供要格式化的文字'], 400);

    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    $text_for_api = $len > 8000 ? (function_exists('mb_substr') ? mb_substr($text, 0, 8000) : substr($text, 0, 8000)) : $text;

    $result = call_deepseek(
        "你是一个 Markdown 格式化助手。请将用户提供的纯文本内容转换为结构清晰的 Markdown 格式，要求：
- 自动识别并添加合适的标题层级（##、###）
- 合理分段，段落之间空行
- 对列举内容使用有序/无序列表
- 对重点语句使用**加粗**或*斜体*
- 对引文使用 > 引用格式
- 保留原文的所有信息，不增删内容
直接输出格式化后的 Markdown，不要加任何解释或标记。",
        $text_for_api,
        0.3,
        4096
    );
    json_response($result, isset($result['error']) ? 500 : 200);
}

function handle_ai_summary(): void {
    require_login();
    $data = body_json();
    $text = trim($data['text'] ?? '');
    if (empty($text)) json_response(['error' => '请提供文章内容'], 400);

    // Truncate very long articles for the API call
    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    $text_for_api = $len > 8000 ? (function_exists('mb_substr') ? mb_substr($text, 0, 8000) : substr($text, 0, 8000)) : $text;

    $result = call_deepseek(
        '你是一个文章摘要助手。请为用户的文章生成一段简洁的摘要（不超过200字），直接输出摘要内容，不要加任何解释或标记。',
        "请为以下文章生成摘要：\n\n{$text_for_api}",
        0.5,
        512
    );
    json_response($result, isset($result['error']) ? 500 : 200);
}

function handle_ai_chat(): void {
    require_login();
    $data = body_json();
    $question = trim($data['question'] ?? '');
    if (empty($question)) json_response(['error' => '请输入问题'], 400);

    // 构建对话历史
    $history = '';
    $raw_history = $data['history'] ?? [];
    if (is_array($raw_history) && count($raw_history) > 0) {
        $recent = array_slice($raw_history, -10); // 最多保留最近10条
        foreach ($recent as $msg) {
            $role = ($msg['role'] ?? '') === 'assistant' ? '助手' : '用户';
            $content = trim($msg['content'] ?? '');
            if ($content !== '') {
                $history .= "{$role}：{$content}\n";
            }
        }
        if ($history !== '') {
            $history = "对话历史：\n{$history}\n";
        }
    }

    $context = '';
    if (!empty($data['article_content'])) {
        $alen = function_exists('mb_strlen') ? mb_strlen($data['article_content']) : strlen($data['article_content']);
        $ctx = $alen > 4000
            ? (function_exists('mb_substr') ? mb_substr($data['article_content'], 0, 4000) : substr($data['article_content'], 0, 4000))
            : $data['article_content'];
        $context = "当前用户正在编辑的文章内容如下：\n\n{$ctx}\n\n";
    }

    $result = call_deepseek(
        "你是一个 Markdown 编辑器中的写作助手。用户正在编辑文章原文，你看到的内容是纯文本 Markdown 源码。重要规则：
- 用户输入中的 `![alt](url)` 和 `<img src=\"...\">` 等是源码文本，不是真实的图片。你无法也不需要「查看」图片。
- 当用户要求修改图片宽度、大小、对齐等属性时，直接输出修改后的源码。例如将 `![desc](url)` 改为 `<img src=\"\" alt=\"desc\" width=\"300\">`。
- 当用户要求修改某个表达、句子、语法时，直接给出修改后的文本。
- 回复应直接给出修改结果（markdown 源码），不要说你「看不到图片」或让用户重新上传。
- 用简洁、友好的语气回答。如果不确定用户的意图，请先确认再修改。",
        "{$history}{$context}用户问题：{$question}",
        0.7,
        4096
    );
    json_response($result, isset($result['error']) ? 500 : 200);
}

function handle_ai_search(): void {
    require_login();
    $data = body_json();
    $query = trim($data['query'] ?? '');
    if (empty($query)) json_response(['error' => '请输入搜索内容'], 400);

    $user = current_user();
    $articles = json_list(DATA_DIR . '/articles');
    if ($user['role'] !== 'admin') {
        $articles = array_filter($articles, fn($a) => ($a['user_id'] ?? '') === $user['id']);
    }

    if (empty($articles)) json_response(['results' => []]);

    // Build a candidate list with title + excerpt
    $candidates = [];
    foreach ($articles as $a) {
        $candidates[] = [
            'id' => $a['id'],
            'title' => $a['title'] ?? '无标题',
            'snippet' => excerpt($a['content'] ?? '', 300),
            'created_at' => $a['created_at'],
        ];
    }

    // Send to DeepSeek for semantic matching
    $catalog = '';
    foreach ($candidates as $i => $c) {
        $catalog .= "[{$i}] {$c['title']}\n    {$c['snippet']}\n\n";
    }

    $result = call_deepseek(
        "你是一个文章搜索助手。根据用户的搜索查询，从文章列表中找到最相关的文章。返回一个JSON数组，包含相关文章的索引编号（从0开始），按相关度从高到低排列，最多返回10篇。只输出JSON数组，不要加任何解释。如果没有相关文章，返回空数组[]。",
        "搜索查询：{$query}\n\n文章列表：\n{$catalog}",
        0.3,
        1024
    );

    if (isset($result['error'])) json_response($result, 500);

    // Parse the returned indices
    $ai_text = $result['text'] ?? '[]';
    $indices = json_decode($ai_text, true);
    if (!is_array($indices)) {
        // Try to extract JSON array from text
        if (preg_match('/\[[\d,\s]*\]/', $ai_text, $m)) {
            $indices = json_decode($m[0], true);
        }
    }

    $results = [];
    if (is_array($indices)) {
        foreach ($indices as $idx) {
            if (isset($candidates[$idx])) {
                $results[] = $candidates[$idx];
            }
        }
    }

    json_response(['results' => $results]);
}

// ==================== AI: 多篇文章问答 ====================

function handle_ai_query_articles(): void {
    require_login();
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['article_ids'] ?? [];
    $question = trim($data['question'] ?? '');

    if (empty($ids)) json_response(['error' => '请选择文章'], 400);
    if ($question === '') json_response(['error' => '请输入问题'], 400);

    $user = current_user();

    // 获取用户作为协作者的合辑中的文章 ID
    $collab_ids = [];
    $collections = json_list(DATA_DIR . '/collections');
    foreach ($collections as $c) {
        if (in_array($user['id'], $c['collaborator_ids'] ?? [])) {
            foreach ($c['article_ids'] ?? [] as $aid) $collab_ids[] = $aid;
        }
    }
    $collab_ids = array_unique($collab_ids);

    $contents = [];
    foreach ($ids as $id) {
        $article = json_read(DATA_DIR . '/articles/' . $id . '.json');
        if (!$article) continue;
        // 自己的文章 / 协作文集中的文章 / 站内公开 / admin
        $can_access = ($article['user_id'] ?? '') === $user['id']
            || in_array($id, $collab_ids)
            || ($article['visibility'] ?? 'private') !== 'private'
            || $user['role'] === 'admin';
        if (!$can_access) continue;
        $contents[] = "--- {$article['title']} ---\n" . ($article['content'] ?? '');
    }

    if (empty($contents)) json_response(['error' => '没有可查询的文章'], 400);

    $combined = implode("\n\n", $contents);
    // Truncate if too long
    $clen = function_exists('mb_strlen') ? mb_strlen($combined) : strlen($combined);
    if ($clen > 16000) {
        $combined = (function_exists('mb_substr') ? mb_substr($combined, 0, 16000) : substr($combined, 0, 16000)) . "\n\n（内容已截断）";
    }

    $result = call_deepseek(
        "你是一个私人知识助手。用户会提供多篇文章内容和一个问题。请基于文章内容回答用户的问题。如果文章内容不足以回答，请如实说明。回答要简洁有条理。",
        "用户问题：{$question}\n\n文章内容：\n{$combined}",
        0.5,
        2048
    );

    if (isset($result['error'])) {
        json_response($result, 500);
    }
    json_response($result);
}

// ==================== 情感分析 ====================

function handle_ai_sentiment(): void {
    require_login();
    $data = body_json();
    $article_id = trim($data['article_id'] ?? '');

    if (empty($article_id)) {
        // Analyze from raw text
        $text = trim($data['text'] ?? '');
        if (empty($text)) json_response(['error' => '请提供文章内容或文章ID'], 400);
    } else {
        $article = json_read(DATA_DIR . '/articles/' . $article_id . '.json');
        if (!$article) json_response(['error' => '文章不存在'], 404);
        $user = current_user();
        $can = ($article['user_id'] ?? '') === $user['id'] || $user['role'] === 'admin';
        if (!$can) json_response(['error' => '无权访问'], 403);
        $text = $article['title'] . "\n\n" . ($article['content'] ?? '');
    }

    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    $text_for_api = $len > 4000
        ? (function_exists('mb_substr') ? mb_substr($text, 0, 4000) : substr($text, 0, 4000))
        : $text;

    $result = call_deepseek(
        '你是一个情感分析助手。请分析用户文章的情感基调。从以下枚举中选择最匹配的：喜悦、忧伤、愤怒、焦虑、平静、兴奋、疲惫、感激。也给出情感强度（1-10）和3个情感关键词。返回严格的JSON格式：{"mood":"平静","intensity":7,"keywords":["安宁","满足"]}',
        "请分析以下文章的情感：\n\n{$text_for_api}",
        0.3,
        256
    );

    if (isset($result['error'])) json_response($result, 500);

    $ai_text = $result['text'] ?? '';
    $sentiment = json_decode($ai_text, true);
    if (!is_array($sentiment) || !isset($sentiment['mood'])) {
        // Try to extract JSON from text
        if (preg_match('/\{[^}]+\}/', $ai_text, $m)) {
            $sentiment = json_decode($m[0], true);
        }
    }

    if (!is_array($sentiment) || !isset($sentiment['mood'])) {
        json_response(['error' => 'AI 返回了无法解析的结果'], 500);
    }

    $moods = ['喜悦', '忧伤', '愤怒', '焦虑', '平静', '兴奋', '疲惫', '感激'];
    if (!in_array($sentiment['mood'], $moods)) {
        $sentiment['mood'] = '平静';
    }

    $sentiment['source'] = 'ai';
    $sentiment['intensity'] = max(1, min(10, intval($sentiment['intensity'] ?? 5)));
    $sentiment['keywords'] = array_slice($sentiment['keywords'] ?? [], 0, 3);

    // If an article_id was given, persist the sentiment
    if (!empty($article_id) && isset($article)) {
        $article['sentiment'] = $sentiment;
        json_write(DATA_DIR . '/articles/' . $article_id . '.json', $article);
    }

    json_response($sentiment);
}

// ==================== 相关文章回顾 ====================

function handle_ai_related(): void {
    require_login();
    $data = body_json();
    $article_id = trim($data['article_id'] ?? '');

    if (empty($article_id)) json_response(['error' => '请提供文章ID'], 400);

    $user = current_user();
    $source = json_read(DATA_DIR . '/articles/' . $article_id . '.json');
    if (!$source) json_response(['error' => '文章不存在'], 404);

    // Get user's accessible articles
    $collab_ids = [];
    $collections = json_list(DATA_DIR . '/collections');
    foreach ($collections as $c) {
        if (in_array($user['id'], $c['collaborator_ids'] ?? [])) {
            foreach ($c['article_ids'] ?? [] as $aid) $collab_ids[] = $aid;
        }
    }
    $collab_ids = array_unique($collab_ids);

    $all_articles = json_list(DATA_DIR . '/articles');
    $candidates = [];
    foreach ($all_articles as $a) {
        if ($a['id'] === $article_id) continue;
        $can = ($a['user_id'] ?? '') === $user['id']
            || in_array($a['id'], $collab_ids)
            || ($a['visibility'] ?? 'private') !== 'private'
            || $user['role'] === 'admin';
        if ($can) $candidates[] = $a;
    }

    if (empty($candidates)) json_response(['articles' => []]);

    // Build catalog
    $catalog = '';
    foreach ($candidates as $i => $a) {
        $content_snip = excerpt($a['content'] ?? '', 300);
        $catalog .= "[{$i}] {$a['title']}\n    {$content_snip}\n\n";
    }

    $source_snip = excerpt($source['content'] ?? '', 500);

    // Truncate catalog if too large
    $clen = function_exists('mb_strlen') ? mb_strlen($catalog) : strlen($catalog);
    if ($clen > 8000) {
        $catalog = (function_exists('mb_substr') ? mb_substr($catalog, 0, 8000) : substr($catalog, 0, 8000)) . "\n\n（候选列表已截断）";
    }

    $result = call_deepseek(
        '你是一个文章关联发现助手。根据源文章，从候选文章列表中找出主题或情感上最相关的文章（最多5篇）。返回纯JSON数组格式：[{"index":0,"reason":"关联原因简述"}]。找不到则返回[]。只输出JSON。',
        "源文章：{$source['title']}\n{$source_snip}\n\n候选列表：\n{$catalog}",
        0.5,
        1024
    );

    if (isset($result['error'])) json_response($result, 500);

    $ai_text = $result['text'] ?? '[]';
    $matches = json_decode($ai_text, true);
    if (!is_array($matches)) {
        if (preg_match('/\[[\s\S]*\]/', $ai_text, $m)) {
            $matches = json_decode($m[0], true);
        }
    }

    $related = [];
    if (is_array($matches)) {
        foreach ($matches as $match) {
            $idx = $match['index'] ?? -1;
            if (isset($candidates[$idx])) {
                $related[] = [
                    'id' => $candidates[$idx]['id'],
                    'title' => $candidates[$idx]['title'] ?: '无标题',
                    'created_at' => $candidates[$idx]['created_at'] ?? '',
                    'reason' => $match['reason'] ?? '',
                ];
            }
        }
    }

    json_response(['articles' => $related]);
}

// ==================== 周期总结 ====================

function handle_ai_period_summary(): void {
    require_login();
    $data = body_json();
    $from = trim($data['from'] ?? '');
    $to = trim($data['to'] ?? '');

    if (empty($from) || empty($to)) json_response(['error' => '请提供日期范围'], 400);

    $user = current_user();
    $collab_ids = [];
    $collections = json_list(DATA_DIR . '/collections');
    foreach ($collections as $c) {
        if (in_array($user['id'], $c['collaborator_ids'] ?? [])) {
            foreach ($c['article_ids'] ?? [] as $aid) $collab_ids[] = $aid;
        }
    }
    $collab_ids = array_unique($collab_ids);

    $all_articles = json_list(DATA_DIR . '/articles');
    $in_range = [];
    foreach ($all_articles as $a) {
        $can = ($a['user_id'] ?? '') === $user['id']
            || in_array($a['id'], $collab_ids)
            || $user['role'] === 'admin';
        if (!$can) continue;
        $date = substr($a['created_at'] ?? '', 0, 10);
        if ($date >= $from && $date <= $to) {
            $in_range[] = $a;
        }
    }

    if (empty($in_range)) json_response(['error' => '该时间段内没有文章'], 404);

    $catalog = '';
    foreach ($in_range as $i => $a) {
        $content_snip = excerpt($a['content'] ?? '', 800);
        $date = substr($a['created_at'] ?? '', 0, 10);
        $mood = $a['sentiment']['mood'] ?? '';
        $catalog .= "[{$i}] {$date} — {$a['title']}" . ($mood ? "（{$mood}）" : '') . "\n{$content_snip}\n\n";
    }

    $result = call_deepseek(
        "你是一个日记回顾助手。请基于用户提供的一段时间内的日记，生成一段温馨有文学感的总结。返回JSON格式：{\"title\":\"周期标题\",\"summary\":\"200-400字的总结段落\",\"events\":[\"关键事件1\",\"关键事件2\",\"关键事件3\"],\"mood_trend\":\"情绪走向描述（如：从焦虑逐渐走向平静）\"}。",
        "以下是从 {$from} 到 {$to} 的文章：\n\n{$catalog}",
        0.7,
        1024
    );

    if (isset($result['error'])) json_response($result, 500);

    $ai_text = $result['text'] ?? '';
    $summary = json_decode($ai_text, true);
    if (!is_array($summary)) {
        if (preg_match('/\{[\s\S]*\}/', $ai_text, $m)) {
            $summary = json_decode($m[0], true);
        }
    }

    if (!is_array($summary)) {
        json_response(['title' => '周期总结', 'summary' => $ai_text, 'events' => [], 'mood_trend' => '']);
    } else {
        json_response($summary);
    }
}

// ==================== 写作洞察 ====================

function handle_ai_writing_insights(): void {
    require_login();
    $data = body_json();
    $stats = $data['stats'] ?? [];

    if (empty($stats)) json_response(['error' => '请提供统计数据'], 400);

    $stats_json = json_encode($stats, JSON_UNESCAPED_UNICODE);

    $result = call_deepseek(
        "你是一个写作分析助手。用户会提供自己的写作统计数据（文章数、字数、发布时段、标签、情感分布等），请你基于这些数据生成一段200字以内的个性化洞察。语气要温暖、鼓励，像一位了解你的朋友。可以发现有趣的规律，也可以给一些温和的建议。例如：\"你通常在深夜写作，平均每篇800字左右。'工作'和'反思'是你最常写的主题。最近一个月你的写作量有所下降，也许可以试试早晨写一小段，换个节奏。你的情绪分布比较均衡，平静和喜悦占了大多数，这很好。\"",
        "写作统计数据：\n{$stats_json}",
        0.7,
        512
    );

    if (isset($result['error'])) json_response($result, 500);

    json_response(['insight' => trim($result['text'] ?? '')]);
}

// ==================== 续写 ====================

function handle_ai_continue(): void {
    require_login();
    $data = body_json();
    $context = trim($data['context'] ?? '');
    if (empty($context)) json_response(['error' => '请提供上下文文字'], 400);

    $direction = $data['direction'] ?? '继续写下去';
    $len = function_exists('mb_strlen') ? mb_strlen($context) : strlen($context);
    $ctx = $len > 2000
        ? (function_exists('mb_substr') ? mb_substr($context, -2000) : substr($context, -2000))
        : $context;

    $system_prompts = [
        '继续写下去' => '你是作者的续写助手。请无缝接续用户提供的文字，就像同一个作者顺着思路往下写一样。保持原文的写作风格、语气、人称和节奏，延续其情感基调和叙事逻辑。不要加"续写："等前缀，不要评价、总结或回应上文——只输出自然延展的后续内容。',
        '换个角度写' => '你是一个写作助手。请从不同的视角或立场，重新展开用户提供的文字主题。输出一段新的展开。只输出内容，不要加任何解释。',
        '总结收尾' => '你是一个写作助手。请为用户提供的文字写一个简洁有力的收尾段落。只输出收尾内容，不要加任何解释。',
    ];

    $system = $system_prompts[$direction] ?? $system_prompts['继续写下去'];

    $result = call_deepseek($system, "请接着以下文字继续写下一段：\n\n{$ctx}", 0.7, 1024);
    json_response($result, isset($result['error']) ? 500 : 200);
}

// ==================== 标签推荐 ====================

function handle_ai_suggest_tags(): void {
    require_login();
    $data = body_json();
    $title = trim($data['title'] ?? '');
    $content = trim($data['content'] ?? '');
    if (empty($title) && empty($content)) json_response(['error' => '请提供文章标题或内容'], 400);

    $text = $title . "\n\n" . ($content ?: '');
    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    $text_for_api = $len > 2000
        ? (function_exists('mb_substr') ? mb_substr($text, 0, 2000) : substr($text, 0, 2000))
        : $text;

    $result = call_deepseek(
        '你是一个文章标签助手。根据文章内容推荐3-5个标签，每个2-4字。返回纯JSON数组，如：["旅行","大理","反思"]。只输出JSON数组。',
        $text_for_api,
        0.4,
        256
    );

    if (isset($result['error'])) json_response($result, 500);

    $ai_text = $result['text'] ?? '[]';
    $tags = json_decode($ai_text, true);
    if (!is_array($tags)) {
        if (preg_match('/\[[^\]]*\]/', $ai_text, $m)) {
            $tags = json_decode($m[0], true);
        }
    }

    json_response(['tags' => is_array($tags) ? array_slice($tags, 0, 5) : []]);
}

// ==================== 标题建议 ====================

function handle_ai_suggest_title(): void {
    require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    if (empty($content)) json_response(['error' => '请提供文章内容'], 400);

    $len = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
    $text_for_api = $len > 2000
        ? (function_exists('mb_substr') ? mb_substr($content, 0, 2000) : substr($content, 0, 2000))
        : $content;

    $result = call_deepseek(
        '你是一个文章标题助手。根据文章内容推荐3个标题，每个标题10-20字，风格多样（如诗意、简洁、悬念等）。返回纯JSON数组，如：["秋日洱海边的思绪","大理三日漫游记","那时我看见风穿过麦田"]。只输出JSON数组。',
        $text_for_api,
        0.7,
        256
    );

    if (isset($result['error'])) json_response($result, 500);

    $ai_text = $result['text'] ?? '[]';
    $titles = json_decode($ai_text, true);
    if (!is_array($titles)) {
        if (preg_match('/\[[^\]]*\]/', $ai_text, $m)) {
            $titles = json_decode($m[0], true);
        }
    }

    json_response(['titles' => is_array($titles) ? array_slice($titles, 0, 3) : []]);
}

// ==================== 金句提取 ====================

function handle_ai_highlights(): void {
    require_login();
    $data = body_json();
    $text = trim($data['text'] ?? '');
    if (empty($text)) json_response(['error' => '请提供文章内容'], 400);

    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    $text_for_api = $len > 4000
        ? (function_exists('mb_substr') ? mb_substr($text, 0, 4000) : substr($text, 0, 4000))
        : $text;

    $result = call_deepseek(
        '你是一个文字鉴赏助手。请从文章中找出写得最好的3-5个句子（金句），并为每个句子写一句简短的评价（为什么好）。返回JSON数组：[{"sentence":"原句","reason":"评价"}]。只输出JSON数组。如果没有特别出彩的句子，返回空数组[]。',
        $text_for_api,
        0.5,
        512
    );

    if (isset($result['error'])) json_response($result, 500);

    $ai_text = $result['text'] ?? '[]';
    $highlights = json_decode($ai_text, true);
    if (!is_array($highlights)) {
        if (preg_match('/\[[\s\S]*\]/', $ai_text, $m)) {
            $highlights = json_decode($m[0], true);
        }
    }

    json_response(['highlights' => is_array($highlights) ? $highlights : []]);
}

// ==================== 自定义模板 ====================

function get_user_ai_templates(): array {
    $user = current_user();
    return $user['ai_templates'] ?? [];
}

function save_user_ai_templates(array $templates): void {
    $user = current_user();
    $path = DATA_DIR . '/users/' . $user['id'] . '.json';
    $user['ai_templates'] = $templates;
    json_write($path, $user);
}

function handle_ai_generate_template(): void {
    require_login();
    $data = body_json();
    $description = trim($data['description'] ?? '');
    if (empty($description)) json_response(['error' => '请描述你想要的模板效果'], 400);

    $result = call_deepseek(
        '你是一个 AI 提示词专家。用户描述想要的写作辅助效果，你生成一个模板名称和一段系统提示词。
提示词中必须用 {{text}} 作为输入文字的占位符。提示词用中文写，要求具体、可操作，长度适中。
返回纯JSON对象，如：{"name":"鲁迅风格改写","prompt":"用鲁迅的文风改写以下文字，保留原意但使用鲁迅标志性的冷峻、简练和讽刺语气：\\n\\n{{text}}"}
只输出JSON对象，不要有其他文字。',
        $description,
        0.7,
        512
    );

    if (isset($result['error'])) json_response($result, 500);

    $ai_text = $result['text'] ?? '{}';
    $generated = json_decode($ai_text, true);
    if (!is_array($generated) || empty($generated['prompt'])) {
        if (preg_match('/\{[^}]+\}/s', $ai_text, $m)) {
            $generated = json_decode($m[0], true);
        }
    }
    if (!is_array($generated) || empty($generated['prompt'])) {
        json_response(['error' => '生成失败，请尝试更具体地描述需求'], 500);
    }

    // 确保 prompt 中包含占位符
    if (strpos($generated['prompt'], '{{text}}') === false) {
        $generated['prompt'] .= "\n\n{{text}}";
    }

    json_response([
        'name' => $generated['name'] ?? '自定义模板',
        'prompt' => $generated['prompt'],
    ]);
}

function handle_ai_list_templates(): void {
    require_login();
    json_response(get_user_ai_templates());
}

function handle_ai_create_template(): void {
    require_login();
    $data = body_json();
    $name = trim($data['name'] ?? '');
    $prompt = trim($data['prompt'] ?? '');
    if (empty($name) || empty($prompt)) json_response(['error' => '请填写名称和提示词'], 400);
    if (strpos($prompt, '{{text}}') === false) json_response(['error' => '提示词需包含 {{text}} 作为文字占位符'], 400);

    $templates = get_user_ai_templates();
    $templates[] = ['id' => uuid(), 'name' => $name, 'prompt' => $prompt];
    save_user_ai_templates($templates);
    json_response($templates, 201);
}

function handle_ai_update_template(string $id): void {
    require_login();
    $data = body_json();
    $name = trim($data['name'] ?? '');
    $prompt = trim($data['prompt'] ?? '');
    if (empty($name) || empty($prompt)) json_response(['error' => '请填写名称和提示词'], 400);
    if (strpos($prompt, '{{text}}') === false) json_response(['error' => '提示词需包含 {{text}} 作为文字占位符'], 400);

    $templates = get_user_ai_templates();
    $found = false;
    foreach ($templates as &$t) {
        if ($t['id'] === $id) {
            $t['name'] = $name;
            $t['prompt'] = $prompt;
            $found = true;
            break;
        }
    }
    unset($t);
    if (!$found) json_response(['error' => '模板不存在'], 404);

    save_user_ai_templates($templates);
    json_response(['ok' => true]);
}

function handle_ai_delete_template(string $id): void {
    require_login();
    $templates = get_user_ai_templates();
    $templates = array_values(array_filter($templates, fn($t) => $t['id'] !== $id));
    save_user_ai_templates($templates);
    json_response(['ok' => true]);
}

function handle_ai_use_template(string $id): void {
    require_login();
    $templates = get_user_ai_templates();
    $template = null;
    foreach ($templates as $t) { if ($t['id'] === $id) { $template = $t; break; } }
    if (!$template) json_response(['error' => '模板不存在'], 404);

    $data = body_json();
    $text = trim($data['text'] ?? '');
    if (empty($text)) json_response(['error' => '请提供待处理的文字'], 400);

    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    $text_for_api = $len > 4000
        ? (function_exists('mb_substr') ? mb_substr($text, 0, 4000) : substr($text, 0, 4000))
        : $text;

    $system = str_replace('{{text}}', $text_for_api, $template['prompt']);
    $result = call_deepseek($system, $text_for_api, 0.7, 2048);
    json_response($result, isset($result['error']) ? 500 : 200);
}
