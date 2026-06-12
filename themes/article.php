<?php
$article = $article ?? null;
$comments = $comments ?? [];
if (!$article): ?>
    <div class="empty-state"><p>文章不存在</p></div>
<?php else:
$user = current_user();
$is_owner = ($article['user_id'] ?? '') === $user['id'];
$is_faved = in_array($article['id'], $user['favorite_article_ids'] ?? []);
?>
<div class="article-layout">
    <!-- 左栏：文章内容 -->
    <div class="article-main">
        <div class="article-detail-header">
            <a href="javascript:history.back()" class="back-link" id="back-link" title="返回上一页">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
                返回
            </a>
            <div class="article-meta">
                <span><?= h($article['author_name'] ?? '') ?></span>
                <time datetime="<?= h($article['created_at']) ?>"><?= h(format_date($article['created_at'])) ?></time>
                <?php if ($article['updated_at'] !== $article['created_at']): ?>
                    <span class="edited-mark">（已编辑）</span>
                <?php endif; ?>
            </div>
            <h1><?= h($article['title'] ?: '无标题') ?></h1>
            <?php if (!empty($article['sentiment']['mood'])): ?>
            <div style="margin-bottom:8px;">
                <?php $s = $article['sentiment']; ?>
                <span class="sentiment-badge sentiment-<?= h($s['mood']) ?>" title="<?= h('情感强度: '.($s['intensity']??'?').'/10  ·  来源: '.($s['source']==='ai'?'AI 分析':'手动标记')) ?>">
                    <?= h($s['mood']) ?>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($article['tags'])): ?>
            <div class="article-tags">
                <?php foreach ($article['tags'] as $tag): ?>
                    <a href="/?tag=<?= urlencode($tag) ?>" class="tag"><?= h($tag) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="article-detail-actions">
                <?php if ($is_owner): ?>
                    <a href="/edit/<?= h($article['id']) ?>" class="btn btn-sm">编辑</a>
                    <a href="/api/export/<?= h($article['id']) ?>" class="btn btn-sm">导出 .md</a>
                    <select class="vis-select btn btn-sm" onchange="changeVisibility('<?= h($article['id']) ?>', this.value)">
                        <option value="private" <?= ($article['visibility'] ?? 'private') === 'private' ? 'selected' : '' ?>>仅自己</option>
                        <option value="internal" <?= ($article['visibility'] ?? '') === 'internal' ? 'selected' : '' ?>>站内可见</option>
                    </select>
                    <button class="btn btn-sm" onclick="quickShare('<?= h($article['id']) ?>')" title="分享">分享</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteArticle('<?= h($article['id']) ?>', true)">删除</button>
                <?php else: ?>
                    <button class="btn btn-sm<?= $is_faved ? ' btn-primary' : '' ?>" id="fav-btn" onclick="toggleFavorite('<?= h($article['id']) ?>')">
                        <?= $is_faved ? '★ 已收藏' : '☆ 收藏' ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="article-body rendered-content"><?= h($article['content'] ?? '') ?></div>
    </div>

    <!-- 右栏：留言区 -->
    <div class="article-comments-sidebar" id="comments-sidebar">
        <div class="comments-sidebar-header">
            <h2>留言 (<?= count($comments) ?>)</h2>
        </div>
        <div class="comment-form">
            <div id="quoted-preview" style="display:none;background:var(--bg);padding:8px 10px;border-radius:var(--radius);margin-bottom:6px;font-size:0.85rem;color:var(--text-muted);border-left:3px solid var(--accent);max-height:80px;overflow-y:auto;white-space:pre-wrap;"></div>
            <textarea id="comment-input" rows="2" placeholder="写下你的想法..." onkeydown="if(event.key==='Escape')clearQuotedText()"></textarea>
            <button class="btn btn-primary btn-sm" onclick="postComment('<?= h($article['id']) ?>', null)">发表</button>
        </div>
        <div class="comment-list" id="comment-list">
            <?php render_comments($comments, $article['id']); ?>
        </div>
    </div>

    <!-- AI 查询弹窗 -->
    <div class="ai-query-popup" id="ai-query-popup" style="display:none">
        <div class="ai-query-input-row">
            <button class="btn btn-sm" onclick="quoteSelectionForComment()" title="对选中文字发表评论">💬</button>
            <input type="text" id="ai-query-input" placeholder="询问 AI 关于选中的文字..." onkeydown="if(event.key==='Enter')submitAIQuery()">
            <button class="btn btn-sm btn-primary" onclick="submitAIQuery()">提问</button>
            <button class="btn-text" onclick="closeAIQuery()" style="font-size:1.2rem">&times;</button>
        </div>
        <div class="ai-query-result" id="ai-query-result" style="display:none"></div>
    </div>
</div>
<script>
async function toggleFavorite(articleId) {
    const btn = document.getElementById('fav-btn');
    try {
        const resp = await fetch('/api/articles/' + articleId + '/favorite', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await resp.json();
        if (result.favorited !== undefined) {
            btn.innerHTML = result.favorited ? '★ 已收藏' : '☆ 收藏';
            btn.classList.toggle('btn-primary', result.favorited);
        }
    } catch (e) { /* ignore */ }
}

let aiQuerySelection = '';
document.addEventListener('mouseup', () => {
    const sel = window.getSelection().toString().trim();
    const popup = document.getElementById('ai-query-popup');
    if (!popup) return;
    if (sel.length > 0 && sel.length < 2000) {
        aiQuerySelection = sel;
        const range = window.getSelection().getRangeAt(0);
        const rect = range.getBoundingClientRect();
        popup.style.top = (rect.bottom + 8) + 'px';
        popup.style.left = Math.max(10, rect.left) + 'px';
        popup.style.display = 'block';
        document.getElementById('ai-query-result').style.display = 'none';
    } else if (sel.length === 0 && !popup.contains(document.activeElement)) {
        setTimeout(() => {
            if (document.activeElement !== document.getElementById('ai-query-input')) {
                popup.style.display = 'none';
            }
        }, 200);
    }
});

function quoteSelectionForComment() {
    const quoted = aiQuerySelection;
    if (!quoted) return;
    document.getElementById('ai-query-popup').style.display = 'none';
    const preview = document.getElementById('quoted-preview');
    preview.textContent = '> ' + quoted;
    preview.style.display = 'block';
    const input = document.getElementById('comment-input');
    input.dataset.quoted = quoted;
    input.focus();
}

function clearQuotedText() {
    document.getElementById('quoted-preview').style.display = 'none';
    document.getElementById('comment-input').dataset.quoted = '';
}

function closeAIQuery() {
    document.getElementById('ai-query-popup').style.display = 'none';
    document.getElementById('ai-query-result').style.display = 'none';
    document.getElementById('ai-query-input').value = '';
}

async function submitAIQuery() {
    const input = document.getElementById('ai-query-input');
    const question = input.value.trim();
    if (!question || !aiQuerySelection) return;

    const resultEl = document.getElementById('ai-query-result');
    resultEl.style.display = 'block';
    resultEl.innerHTML = '<span style="color:var(--text-muted)">处理中...</span>';

    try {
        const resp = await fetch('/api/ai/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                question: question + '\n\n选中的文字：' + aiQuerySelection,
                article_content: ''
            })
        });
        const result = await resp.json();
        if (result.text || result.answer) {
            resultEl.innerHTML = '<div style="padding:8px;background:var(--bg);border-radius:4px;font-size:0.85rem;line-height:1.6;white-space:pre-wrap;max-height:200px;overflow-y:auto;">' + esc(result.text || result.answer) + '</div>';
        } else if (result.error) {
            resultEl.innerHTML = '<span style="color:var(--danger)">错误: ' + esc(result.error) + '</span>';
        }
    } catch (err) {
        resultEl.innerHTML = '<span style="color:var(--danger)">请求失败</span>';
    }
}
</script>
<?php endif;

function render_comments(array $comments, string $article_id): void {
    foreach ($comments as $c) {
        $depth = $c['depth'] ?? 0;
        $collapsed = $depth >= 2;
        ?>
        <div class="comment<?= $depth > 0 ? ' comment-reply' : '' ?><?= $collapsed ? ' comment-collapsed' : '' ?>" id="comment-<?= h($c['id']) ?>" style="margin-left: <?= min($depth, 2) * 16 ?>px">
            <div class="comment-header">
                <span class="comment-author"><?= h($c['user_name'] ?? '?') ?></span>
                <time><?= h(format_date($c['created_at'])) ?></time>
                <?php if ($collapsed): ?>
                    <span class="comment-collapsed-mark">（折叠的回复）</span>
                <?php endif; ?>
            </div>
            <div class="comment-body">
                <?php if (!empty($c['quoted_text'])): ?>
                    <div class="comment-quoted"><?= nl2br(h($c['quoted_text'])) ?></div>
                <?php endif; ?>
                <?= nl2br(h($c['content'] ?? '')) ?>
            </div>
            <div class="comment-actions">
                <?php if ($depth === 0): ?>
                    <button class="btn-text" onclick="replyTo('<?= h($c['id']) ?>', '<?= h($c['user_name'] ?? '') ?>')">回复</button>
                <?php endif; ?>
                <?php if (current_user()['id'] === ($c['user_id'] ?? '') || current_user()['id'] === ($article['user_id'] ?? '') || is_admin()): ?>
                    <button class="btn-text btn-danger" onclick="deleteComment('<?= h($c['id']) ?>')">删除</button>
                <?php endif; ?>
            </div>
            <?php if (!empty($c['children'])): ?>
                <?php render_comments($c['children'], $article_id); ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
?>
