<?php
$articles = $articles ?? [];
$search = $search ?? '';
$page = $page ?? 1;
$total_pages = $total_pages ?? 1;
$total = $total ?? 0;
$homepage_mode = $homepage_mode ?? 'both';
$collections = $collections ?? [];
$show_collections = in_array($homepage_mode, ['both', 'collections_only']);
$show_articles = in_array($homepage_mode, ['both', 'articles_only']);
$homepage_calendar = $homepage_calendar ?? false;
$calendar = $calendar ?? null;
$cal_year = $cal_year ?? (int)date('Y');
$cal_month = $cal_month ?? (int)date('m');
?>
<div class="page-header">
    <h1>首页</h1>
    <div class="page-actions">
        <a href="/write" class="btn btn-primary">写新文章</a>
    </div>
</div>

<div class="search-bar">
    <form method="GET" action="/" class="search-form">
        <input type="search" name="search" value="<?= h($search) ?>" placeholder="搜索文章和合辑..." class="search-input">
        <?php if ($search): ?>
            <a href="/" class="search-clear">清除</a>
        <?php endif; ?>
    </form>
</div>

<?php if (!empty($homepage_calendar) && !empty($calendar)): ?>
<section class="home-section calendar-section">
    <?= render_calendar_html($calendar, $cal_year, $cal_month) ?>
</section>
<?php endif; ?>

<?php if ($show_collections): ?>
<section class="home-section">
    <div class="home-section-header">
        <h2>合辑 <span class="section-count"><?= count($collections) ?></span></h2>
        <a href="/collections" class="btn-text">查看全部 &rarr;</a>
    </div>
    <?php if (empty($collections)): ?>
        <div class="empty-state empty-state-sm">
            <?php if ($search): ?>
                <p>没有匹配的合辑</p>
            <?php else: ?>
                <p>还没有合辑</p>
                <p class="empty-hint"><a href="/collections">去创建</a>一个合辑，将文章归类整理</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="collections-grid">
            <?php foreach ($collections as $c): ?>
            <div class="collection-card-wrap">
                <a href="/collection/<?= h($c['id']) ?>" class="collection-card">
                    <?php if (!empty($c['cover'])): ?>
                        <img src="<?= h(str_starts_with($c['cover'], '/') ? $c['cover'] : '/' . $c['cover']) ?>" class="coll-cover" alt="">
                    <?php else: ?>
                        <div class="coll-cover" style="background:var(--accent-light);display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:1.5rem;"><?= h(first_char($c['name']) ?: ' ') ?></div>
                    <?php endif; ?>
                    <h3><?= h($c['name']) ?></h3>
                    <?php if (!empty($c['description'])): ?>
                        <p><?= h(function_exists('mb_substr') ? mb_substr($c['description'], 0, 100) : substr($c['description'], 0, 100)) ?></p>
                    <?php endif; ?>
                    <div class="coll-meta"><?= count($c['article_ids'] ?? []) ?> 篇文章</div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($show_collections && $show_articles): ?>
<hr class="section-divider">
<?php endif; ?>

<?php if ($show_articles): ?>
<section class="home-section">
    <div class="home-section-header">
        <h2>文章 <span class="section-count"><?= $total ?></span></h2>
    </div>

    <?php if (empty($articles)): ?>
        <div class="empty-state empty-state-sm">
            <p>还没有文章</p>
            <p class="empty-hint">点击右上角"写新文章"开始书写</p>
        </div>
    <?php else: ?>
        <!-- 批量操作栏 -->
        <div class="batch-bar" id="batch-bar" style="display:none;">
            <span id="batch-count">已选 0 篇</span>
            <button class="btn btn-sm" onclick="batchExport()">导出选中 (.md ZIP)</button>
            <button class="btn btn-sm" onclick="batchShare()">生成分享链接</button>
            <button class="btn btn-sm" onclick="batchAskAI()">AI 提问</button>
            <button class="btn-text" onclick="clearSelection()">取消选择</button>
        </div>

        <div class="article-list" id="article-list">
            <?php foreach ($articles as $a): ?>
            <article class="article-card">
                <div class="article-row">
                    <label class="article-check">
                        <input type="checkbox" class="article-select" value="<?= h($a['id']) ?>" onchange="updateBatchBar()">
                    </label>
                    <div class="article-main">
                        <div class="article-meta">
                            <time datetime="<?= h($a['created_at']) ?>"><?= h(format_date($a['created_at'])) ?></time>
                            <?php if (($a['visibility'] ?? 'private') !== 'private'): ?>
                                <span class="vis-badge vis-<?= h($a['visibility']) ?>">
                                    <?= $a['visibility'] === 'internal' ? '站内可见' : '已分享' ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($a['pinned']): ?>
                                <span class="vis-badge vis-pinned">置顶</span>
                            <?php endif; ?>
                        </div>
                        <h2 class="article-title">
                            <a href="/article/<?= h($a['id']) ?>"><?= h($a['title'] ?: '无标题') ?></a>
                        </h2>
                        <?php if (!empty($a['task_total'])): ?>
                        <div class="task-progress" style="margin-top:4px;display:flex;align-items:center;gap:6px;font-size:0.75rem;color:var(--text-muted);font-family:var(--font-ui);">
                            <span><?= $a['task_done'] ?>/<?= $a['task_total'] ?> 任务</span>
                            <div style="flex:1;max-width:80px;height:4px;background:var(--border);border-radius:2px;overflow:hidden;">
                                <div style="height:100%;width:<?= round($a['task_done'] / max($a['task_total'], 1) * 100) ?>%;background:var(--accent);border-radius:2px;"></div>
                            </div>
                            <?php if ($a['task_total'] > 0 && $a['task_done'] === $a['task_total']): ?>
                                <span style="color:var(--accent);">全部完成</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($a['summary'])): ?>
                            <p class="article-summary"><?= h($a['summary']) ?></p>
                        <?php else: ?>
                            <p class="article-summary"><?= h(excerpt($a['content'] ?? '')) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($a['tags'])): ?>
                        <div class="article-tags">
                            <?php foreach ($a['tags'] as $tag): ?>
                                <a href="/?tag=<?= urlencode($tag) ?>" class="tag"><?= h($tag) ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php $is_my = ($a['user_id'] ?? '') === current_user()['id']; ?>
                        <?php if ($is_my): ?>
                        <div class="article-actions">
                            <a href="/edit/<?= h($a['id']) ?>" class="btn btn-sm btn-icon" title="编辑">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </a>
                            <a href="/api/export/<?= h($a['id']) ?>" class="btn btn-sm btn-icon" title="导出 Markdown">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            </a>
                            <button class="btn btn-sm btn-icon" onclick="quickShare('<?= h($a['id']) ?>')" title="分享">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                            </button>
                            <button class="btn btn-sm btn-icon btn-danger" onclick="deleteArticle('<?= h($a['id']) ?>')" title="删除">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="article-actions">
                            <?php if (is_admin()): ?>
                                <span style="color:var(--text-muted);font-family:var(--font-ui);font-size:0.8rem;">作者：<?= h($a['author_name'] ?? '未知') ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-family:var(--font-ui);font-size:0.8rem;">来自协作文集</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php $qs = http_build_query(array_filter(['page' => $i, 'search' => $search])); ?>
                <a href="/?<?= $qs ?>" class="page-link<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$show_collections && !$show_articles): ?>
    <div class="empty-state">
        <p>首页展示为空</p>
        <p class="empty-hint">请到 <a href="/settings">设置</a> 中调整首页展示内容</p>
    </div>
<?php endif; ?>

<!-- AI 问答弹窗 -->
<div class="modal" id="ai-modal" style="display:none">
    <div class="modal-overlay" onclick="closeAIModal()"></div>
    <div class="modal-card" style="max-width:640px;display:flex;flex-direction:column;max-height:85vh;">
        <h2 id="ai-modal-title">AI 回答</h2>
        <div id="ai-modal-body" style="flex:1;min-height:0;overflow-y:auto;font-size:0.9rem;line-height:1.8;margin:8px 0;">
        </div>
        <div id="ai-modal-footer" style="font-size:0.75rem;color:var(--text-muted);"></div>
        <div id="ai-follow-up" style="display:none;margin-top:8px;gap:6px;">
            <input type="text" id="ai-follow-input" placeholder="继续追问..."
                style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font-ui);font-size:0.85rem;outline:none;"
                onkeydown="if(event.key==='Enter')sendFollowUp()">
            <button class="btn btn-sm btn-primary" onclick="sendFollowUp()">发送</button>
        </div>
        <div class="modal-actions" style="margin-top:12px;">
            <button class="btn" onclick="closeAIModal()">关闭</button>
        </div>
    </div>
</div>

<script>
function updateBatchBar() {
    const checked = document.querySelectorAll('.article-select:checked');
    const bar = document.getElementById('batch-bar');
    const count = document.getElementById('batch-count');
    if (checked.length > 0) {
        bar.style.display = 'flex';
        count.textContent = '已选 ' + checked.length + ' 篇';
    } else {
        bar.style.display = 'none';
    }
}

function clearSelection() {
    document.querySelectorAll('.article-select').forEach(cb => cb.checked = false);
    updateBatchBar();
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.article-select:checked')).map(cb => cb.value);
}

function batchExport() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    showLoading('正在生成下载文件...');
    fetch('/api/export/batch', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({article_ids: ids})
    }).then(r => r.json()).then(data => {
        if (!data.url) { hideLoading(); alert(data.error || '导出失败'); return; }
        downloadFile(data.url, 'My_Paper_export.zip');
    }).catch(e => {
        hideLoading();
        alert('导出失败: ' + (e.message || ''));
    });
}

function batchShare() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    document.getElementById('share-ids').value = JSON.stringify(ids);
    document.getElementById('share-modal').style.display = 'flex';
}

let _aiChatHistory = [];
let _aiArticleIds = [];

async function batchAskAI() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    const question = prompt('向 AI 提问（将基于选中文章内容回答）：');
    if (!question || !question.trim()) return;

    _aiArticleIds = ids;
    _aiChatHistory = [];

    const modal = document.getElementById('ai-modal');
    const body = document.getElementById('ai-modal-body');
    document.getElementById('ai-modal-title').textContent = 'AI 回答';
    document.getElementById('ai-modal-footer').textContent = '基于 ' + ids.length + ' 篇文章';
    body.innerHTML = '';
    modal.style.display = 'flex';
    document.getElementById('ai-follow-up').style.display = 'none';

    await doAskAI(question.trim());
}

async function doAskAI(question) {
    const body = document.getElementById('ai-modal-body');
    const input = document.getElementById('ai-follow-input');
    const followUp = document.getElementById('ai-follow-up');

    // 显示用户问题
    appendChatBubble('user', question);

    // 显示加载状态
    var loadingId = 'ai-loading-' + Date.now();
    body.insertAdjacentHTML('beforeend', '<div id="' + loadingId + '" style="color:var(--text-muted);padding:8px 12px;font-size:0.85rem;">思考中...</div>');
    body.scrollTop = body.scrollHeight;
    if (input) input.value = '';
    if (followUp) followUp.style.display = 'none';

    try {
        const resp = await fetch('/api/ai/query-articles', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ article_ids: _aiArticleIds, question: question, history: _aiChatHistory })
        });
        const result = await resp.json();
        var loadingEl = document.getElementById(loadingId);
        if (loadingEl) loadingEl.remove();

        if (result.text || result.answer) {
            var answer = result.text || result.answer;
            _aiChatHistory.push({ role: 'user', content: question });
            _aiChatHistory.push({ role: 'assistant', content: answer });
            appendChatBubble('assistant', answer);
        } else if (result.error) {
            appendChatBubble('system', '错误: ' + result.error);
        }
    } catch (err) {
        var loadingEl2 = document.getElementById(loadingId);
        if (loadingEl2) loadingEl2.remove();
        appendChatBubble('system', '请求失败: ' + err.message);
    }

    body.scrollTop = body.scrollHeight;
    if (followUp) followUp.style.display = 'flex';
    if (input) input.focus();
}

function appendChatBubble(role, text) {
    var body = document.getElementById('ai-modal-body');
    var bubble = document.createElement('div');
    bubble.className = 'ai-chat-bubble ai-chat-' + role;
    bubble.textContent = text;
    body.appendChild(bubble);
}

function sendFollowUp() {
    var input = document.getElementById('ai-follow-input');
    var q = (input.value || '').trim();
    if (!q) return;
    doAskAI(q);
}

function closeAIModal() {
    document.getElementById('ai-modal').style.display = 'none';
    _aiChatHistory = [];
    _aiArticleIds = [];
}

</script>
