<?php
/**
 * My Paper - 通知系统
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function add_notification(string $user_id, string $type, string $message, string $link): void {
    $path = DATA_DIR . '/notifications/' . $user_id . '.json';
    $data = json_read($path) ?: ['user_id' => $user_id, 'items' => []];

    $data['items'][] = [
        'id' => uuid(),
        'type' => $type,
        'message' => $message,
        'link' => $link,
        'read' => false,
        'created_at' => date('c'),
    ];

    // Keep last 50 notifications
    if (count($data['items']) > 50) {
        $data['items'] = array_slice($data['items'], -50);
    }

    json_write($path, $data);

    // Update unread count
    $user = json_read(DATA_DIR . '/users/' . $user_id . '.json');
    if ($user) {
        $user['unread_notifications'] = count(array_filter($data['items'], fn($n) => !$n['read']));
        json_write(DATA_DIR . '/users/' . $user_id . '.json', $user);
    }
}

function get_notifications(string $user_id): array {
    $data = json_read(DATA_DIR . '/notifications/' . $user_id . '.json');
    if (!$data) return ['items' => [], 'unread' => 0];

    $items = $data['items'] ?? [];
    $items = array_reverse($items); // newest first
    $unread = count(array_filter($items, fn($n) => !$n['read']));

    return ['items' => $items, 'unread' => $unread];
}

function handle_get_notifications(): void {
    require_login();
    $user = current_user();
    json_response(get_notifications($user['id']));
}

function handle_mark_read(): void {
    require_login();
    $user = current_user();
    $path = DATA_DIR . '/notifications/' . $user['id'] . '.json';
    $data = json_read($path);
    if (!$data) json_response(['ok' => true]);

    $id = body_json()['id'] ?? null;
    foreach ($data['items'] as &$item) {
        if ($id === null || $item['id'] === $id) {
            $item['read'] = true;
        }
    }
    json_write($path, $data);

    // Update user's unread count
    $user['unread_notifications'] = count(array_filter($data['items'], fn($n) => !$n['read']));
    json_write(DATA_DIR . '/users/' . $user['id'] . '.json', $user);

    json_response(['ok' => true]);
}

// ==================== 通知触发函数 ====================

// 评论通知：通知文章作者
function notify_comment(string $article_id, string $commenter_id, string $commenter_name): void {
    $article = json_read(DATA_DIR . '/articles/' . $article_id . '.json');
    if (!$article) return;

    $author_id = $article['user_id'] ?? '';
    if ($author_id && $author_id !== $commenter_id) {
        add_notification(
            $author_id,
            'comment',
            $commenter_name . ' 在你的文章《' . ($article['title'] ?? '无标题') . '》下发表了留言',
            '/article/' . $article_id
        );
    }
}

// 回复通知：通知被回复的留言作者（非回复者本人时）
function notify_comment_reply(array $parent_comment, array $article, string $replier_id, string $replier_name): void {
    $parent_author_id = $parent_comment['user_id'] ?? '';
    if ($parent_author_id && $parent_author_id !== $replier_id) {
        add_notification(
            $parent_author_id,
            'comment_reply',
            $replier_name . ' 回复了你在《' . ($article['title'] ?? '无标题') . '》中的留言',
            '/article/' . ($article['id'] ?? '')
        );
    }
}

// 协作者邀请通知：通知被添加为协作者的用户
function notify_collaborator_add(array $collection, string $target_user_id): void {
    $owner = json_read(DATA_DIR . '/users/' . ($collection['user_id'] ?? '') . '.json');
    $owner_name = $owner ? ($owner['display_name'] ?? $owner['username'] ?? '?') : '?';
    add_notification(
        $target_user_id,
        'collab_add',
        $owner_name . ' 将你添加为合辑《' . ($collection['name'] ?? '未命名') . '》的协作者',
        '/collection/' . $collection['id']
    );
}

// 合辑更新通知：当协作者添加/移除文章时，通知合辑所有者和其他协作者
function notify_collection_article_change(array $collection, string $actor_id, string $actor_name, int $added, int $removed): void {
    $parts = [];
    if ($added > 0) $parts[] = '添加了 ' . $added . ' 篇文章';
    if ($removed > 0) $parts[] = '移除了 ' . $removed . ' 篇文章';
    if (empty($parts)) return;
    $desc = implode('，', $parts);

    $notified = [$actor_id];
    // 通知所有者
    $owner_id = $collection['user_id'] ?? '';
    if ($owner_id && !in_array($owner_id, $notified)) {
        add_notification(
            $owner_id,
            'coll_update',
            $actor_name . ' 在合辑《' . ($collection['name'] ?? '未命名') . '》中' . $desc,
            '/collection/' . $collection['id']
        );
        $notified[] = $owner_id;
    }
    // 通知其他协作者
    foreach ($collection['collaborator_ids'] ?? [] as $cid) {
        if (!in_array($cid, $notified)) {
            add_notification(
                $cid,
                'coll_update',
                $actor_name . ' 在合辑《' . ($collection['name'] ?? '未命名') . '》中' . $desc,
                '/collection/' . $collection['id']
            );
            $notified[] = $cid;
        }
    }
}
