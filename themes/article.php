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
                    <select class="vis-select btn btn-sm" onchange="changeVisibility('<?= h($article['id']) ?>', this.value)" title="可见范围">
                        <option value="private" <?= ($article['visibility'] ?? 'private') === 'private' ? 'selected' : '' ?>>仅自己</option>
                        <option value="internal" <?= ($article['visibility'] ?? '') === 'internal' ? 'selected' : '' ?>>站内可见</option>
                    </select>
                    <span class="actions-sep"></span>
                    <a href="/edit/<?= h($article['id']) ?>" class="btn btn-sm btn-icon" title="编辑">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </a>
                    <a href="/api/export/<?= h($article['id']) ?>" class="btn btn-sm btn-icon" title="导出 Markdown">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </a>
                    <button class="btn btn-sm btn-icon" onclick="quickShare('<?= h($article['id']) ?>')" title="分享">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                    </button>
                    <span class="actions-sep"></span>
                    <button class="btn btn-sm btn-icon btn-danger" onclick="deleteArticle('<?= h($article['id']) ?>', true)" title="删除">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                <?php endif; ?>
                <button class="btn btn-sm btn-icon<?= $is_faved ? ' btn-primary' : '' ?>" id="fav-btn" onclick="toggleFavorite('<?= h($article['id']) ?>')" title="<?= $is_faved ? '取消收藏' : '收藏' ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="<?= $is_faved ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </button>
            </div>
        </div>

        <div class="article-body rendered-content" data-article-id="<?= h($article['id']) ?>" data-is-author="<?= $is_owner ? '1' : '0' ?>"><?= h($article['content'] ?? '') ?></div>
    </div>

    <!-- 右栏：留言区 -->
    <div class="article-comments-sidebar" id="comments-sidebar">
        <div class="comments-sidebar-header">
            <h2>留言 (<?= count($comments) ?>)</h2>
        </div>
        <div class="comment-form">
            <div id="quoted-preview" style="display:none;background:var(--bg);padding:8px 10px;border-radius:var(--radius);margin-bottom:6px;font-size:0.85rem;color:var(--text-muted);border-left:3px solid var(--accent);max-height:80px;overflow-y:auto;white-space:pre-wrap;position:relative;padding-right:60px;"><span id="quoted-preview-text"></span><button onclick="clearQuotedText()" title="取消引用" style="position:absolute;top:4px;right:6px;background:var(--bg-card);border:1px solid var(--border);border-radius:3px;font-size:0.85rem;color:var(--text-muted);cursor:pointer;line-height:1.2;padding:0 5px;">&times;</button></div>
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
            var svg = btn.querySelector('svg');
            if (svg) svg.setAttribute('fill', result.favorited ? 'currentColor' : 'none');
            btn.classList.toggle('btn-primary', result.favorited);
            btn.setAttribute('title', result.favorited ? '取消收藏' : '收藏');
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
    document.getElementById('quoted-preview-text').textContent = '> ' + quoted;
    document.getElementById('quoted-preview').style.display = 'block';
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
                article_content: '',
                mode: 'reader'
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
