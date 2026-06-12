<?php
$collection = $collection ?? null;
if (!$collection): ?>
    <div class="empty-state"><p>合辑不存在</p></div>
<?php else:
$user = current_user();
$is_owner = ($collection['user_id'] ?? '') === $user['id'];
$is_collab = in_array($user['id'], $collection['collaborator_ids'] ?? []);
$can_edit = $is_owner || $is_collab;
?>
<div class="page-header<?= !empty($collection['cover']) ? ' has-cover' : '' ?>">
    <?php if (!empty($collection['cover'])): ?>
        <div class="coll-cover">
            <img src="<?= h($collection['cover']) ?>" alt="合辑封面" class="coll-cover-img">
        </div>
    <?php endif; ?>
    <div>
        <h1><?= h($collection['name']) ?></h1>
        <?php if (!empty($collection['description'])): ?>
            <p class="coll-description" style="color:var(--text-muted);font-family:var(--font-ui);font-size:0.9rem;margin-top:4px;">
                <?= h($collection['description']) ?>
            </p>
        <?php endif; ?>
        <div class="coll-meta" style="margin-top:8px;">
            <span><?= count($collection['article_ids'] ?? []) ?> 篇文章</span>
            <?php
            $owner = json_read(DATA_DIR . '/users/' . ($collection['user_id'] ?? '') . '.json');
            ?>
            <span style="margin-left:8px;">创建者: <?= h($owner['display_name'] ?? $owner['username'] ?? '?') ?></span>
        </div>
    </div>
    <div class="page-actions">
        <a href="/api/export/collection/<?= h($collection['id']) ?>/preview" class="btn" target="_blank">预览 / 导出 PDF 书</a>
        <?php if ($is_owner): ?>
            <button class="btn btn-sm" onclick="editCollection('<?= h($collection['id']) ?>')">编辑</button>
            <button class="btn btn-sm" onclick="manageCollaborators('<?= h($collection['id']) ?>')">协作者</button>
            <button class="btn btn-sm btn-danger" onclick="deleteCurrentCollection('<?= h($collection['id']) ?>')">删除合辑</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_edit): ?>
    <div class="add-to-collection" style="margin-bottom:20px;">
        <button class="btn btn-sm" onclick="showAddArticles('<?= h($collection['id']) ?>')">添加文章到合辑</button>
    </div>
<?php endif; ?>

    <!-- 批量操作栏 -->
    <div class="batch-bar" id="batch-bar" style="display:none;">
        <span id="batch-count">已选 0 篇</span>
        <button class="btn btn-sm" onclick="collectionBatchAskAI()">AI 提问</button>
        <button class="btn-text" onclick="collectionClearSelection()">取消选择</button>
    </div>

<div class="article-list" id="collection-articles">
    <div class="empty-state"><p>加载中...</p></div>
</div>

<!-- Add Articles Modal -->
<div class="modal" id="add-articles-modal" style="display:none">
    <div class="modal-overlay" onclick="closeAddArticles()"></div>
    <div class="modal-card" style="max-width:560px;">
        <h2>添加文章</h2>
        <div class="search-bar" style="margin-bottom:12px;">
            <input type="search" id="add-article-search" placeholder="搜索你的文章..." class="search-input" style="max-width:100%;" oninput="filterAddArticles()">
        </div>
        <div id="add-articles-list" style="max-height:400px;overflow-y:auto;">
            <p style="color:var(--text-muted);">加载中...</p>
        </div>
        <div class="modal-actions" style="margin-top:12px;">
            <button class="btn" onclick="closeAddArticles()">关闭</button>
        </div>
    </div>
</div>

<!-- 创建/编辑合辑弹窗 -->
<div class="modal" id="collection-modal" style="display:none">
	<div class="modal-overlay" onclick="closeCollectionModal()"></div>
	<div class="modal-card">
		<h2 id="coll-modal-title">编辑合辑</h2>
		<form id="collection-form" onsubmit="saveCollection(event)">
			<input type="hidden" id="coll-id">
			<label class="field">
				<span>名称</span>
				<input type="text" id="coll-name" required>
			</label>
			<label class="field">
				<span>描述</span>
				<textarea id="coll-desc" rows="3"></textarea>
			</label>
			<label class="field">
				<span>封面图</span>
				<div style="display:flex;gap:8px;align-items:stretch;">
					<input type="text" id="coll-cover" placeholder="URL 或上传本地图片" style="flex:1;">
					<input type="file" id="coll-cover-file" accept="image/*" style="display:none;" onchange="uploadCollectionCover()">
					<button type="button" class="btn btn-sm" onclick="document.getElementById('coll-cover-file').click()">上传</button>
				</div>
				<div id="coll-cover-preview" style="margin-top:8px;"></div>
			</label>
			<div class="modal-actions">
				<button type="button" class="btn" onclick="closeCollectionModal()">取消</button>
				<button type="submit" class="btn btn-primary">保存</button>
			</div>
		</form>
	</div>
</div>
<!-- Collaborators Modal -->
<div class="modal" id="collaborators-modal" style="display:none">
    <div class="modal-overlay" onclick="closeCollaborators()"></div>
    <div class="modal-card">
        <h2>协作者管理</h2>
        <div id="collaborators-list" style="margin-bottom:16px;">
            <p style="color:var(--text-muted);">加载中...</p>
        </div>
        <div style="display:flex;gap:8px;">
            <input type="text" id="collaborator-username" placeholder="输入用户名" class="search-input" style="flex:1;">
            <button class="btn btn-primary btn-sm" onclick="addCollaborator('<?= h($collection['id']) ?>')">添加</button>
        </div>
        <div class="modal-actions" style="margin-top:12px;">
            <button class="btn" onclick="closeCollaborators()">关闭</button>
        </div>
    </div>
</div>

<!-- AI 回答弹窗 -->
<div class="modal" id="ai-modal" style="display:none">
    <div class="modal-overlay" onclick="closeAIModal()"></div>
    <div class="modal-card" style="max-width:640px;">
        <h2 id="ai-modal-title">AI 回答</h2>
        <div id="ai-modal-body" style="max-height:60vh;overflow-y:auto;font-size:0.9rem;line-height:1.8;white-space:pre-wrap;background:var(--bg);padding:16px;border-radius:var(--radius);margin:8px 0;">
            <span style="color:var(--text-muted)">处理中...</span>
        </div>
        <div id="ai-modal-footer" style="font-size:0.75rem;color:var(--text-muted);"></div>
        <div class="modal-actions" style="margin-top:12px;">
            <button class="btn" onclick="closeAIModal()">关闭</button>
        </div>
    </div>
</div>

<script>
const collectionId = '<?= h($collection['id']) ?>';
const isOwner = <?= json_encode($is_owner) ?>;
const canEdit = <?= json_encode($can_edit) ?>;
const collArticleIds = <?= json_encode($collection['article_ids'] ?? []) ?>;

document.addEventListener('DOMContentLoaded', () => {
    loadCollectionArticles();
});

async function loadCollectionArticles() {
    const container = document.getElementById('collection-articles');
    if (!collArticleIds.length) {
        container.innerHTML = '<div class="empty-state"><p>合辑中还没有文章</p><p class="empty-hint">点击"添加文章到合辑"开始</p></div>';
        return;
    }
    try {
        const resp = await fetch('/api/articles?' + collArticleIds.map(id => 'ids[]=' + id).join('&'), {
            headers: {'X-Requested-With':'XMLHttpRequest'}
        });
        const data = await resp.json();
        const articles = data.articles || [];
        // Order by collArticleIds (which reflects the collection's stored order)
        const articleMap = {};
        articles.forEach(a => { articleMap[a.id] = a; });
        const ordered = collArticleIds.map(id => articleMap[id]).filter(Boolean);
        // Append any articles not in collArticleIds (shouldn't happen, but safe)
        articles.forEach(a => {
            if (!ordered.find(o => o.id === a.id)) ordered.push(a);
        });

        if (!ordered.length) {
            container.innerHTML = '<div class="empty-state"><p>文章加载失败</p></div>';
            return;
        }

        container.innerHTML = ordered.map((a, i) => `
            <div class="article-card collection-article-item" draggable="${canEdit}" data-id="${a.id}" data-idx="${i}">
                <div class="article-row">
                    <label class="article-check">
                        <input type="checkbox" class="article-select" value="${a.id}" onchange="collectionUpdateBatchBar()">
                    </label>
                    ${canEdit ? `<div class="article-check" style="padding-top:2px;">
                        <span class="drag-handle" style="cursor:grab;color:var(--text-muted);font-size:1.2rem;" title="拖拽排序">::</span>
                    </div>` : ''}
                    <div class="article-main">
                        <div class="article-meta">
                            <time>${esc(formatDate(a.created_at))}</time>
                            ${a.visibility !== 'private' ? `<span class="vis-badge vis-${a.visibility}">${a.visibility === 'internal' ? '站内可见' : '已分享'}</span>` : ''}
                        </div>
                        <h2 class="article-title">
                            <a href="/article/${a.id}">${esc(a.title || '无标题')}</a>
                        </h2>
                        <p class="article-summary">${esc(a.summary || (a.content||'').replace(/[#*`>\[\]()!\-|~]/g,'').replace(/\s+/g,' ').trim().substring(0,200))}</p>
                        <div class="article-actions">
                            ${canEdit ? `<button class="btn-text btn-danger" onclick="removeFromCollection('${a.id}', ${i})">移出合辑</button>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

        if (canEdit) initDragSort();
    } catch(e) { console.error(e); container.innerHTML = '<div class="empty-state"><p>加载失败</p></div>'; }
}

function initDragSort() {
    const items = document.querySelectorAll('.collection-article-item');
    items.forEach(item => {
        item.addEventListener('dragstart', e => {
            e.dataTransfer.setData('text/plain', item.dataset.idx);
            item.style.opacity = '0.5';
        });
        item.addEventListener('dragend', e => { item.style.opacity = '1'; });
        item.addEventListener('dragover', e => { e.preventDefault(); });
        item.addEventListener('drop', async e => {
            e.preventDefault();
            const fromIdx = parseInt(e.dataTransfer.getData('text/plain'));
            const toIdx = parseInt(item.dataset.idx);
            if (fromIdx === toIdx) return;

            const ids = collArticleIds.slice();
            const [moved] = ids.splice(fromIdx, 1);
            ids.splice(toIdx, 0, moved);

            try {
                const resp = await fetch('/api/collections/' + collectionId, {
                    method: 'PUT',
                    headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
                    body: JSON.stringify({article_ids: ids, sort_order: ids.map((_, i) => i)})
                });
                if ((await resp.json()).id) window.location.reload();
            } catch(e) { alert('排序失败'); }
        });
    });
}

async function removeFromCollection(articleId, idx) {
    if (!confirm('确定从合辑中移除此文章？')) return;
    const ids = collArticleIds.filter(id => id !== articleId);
    try {
        const resp = await fetch('/api/collections/' + collectionId, {
            method: 'PUT',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({article_ids: ids})
        });
        if ((await resp.json()).id) window.location.reload();
    } catch(e) { alert('移除失败'); }
}

// ===== Add Articles =====

let userArticles = [];
async function showAddArticles(collId) {
    document.getElementById('add-articles-modal').style.display = 'flex';
    try {
        const resp = await fetch('/api/articles', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const data = await resp.json();
        userArticles = (data.articles || []).filter(a => !collArticleIds.includes(a.id));
        renderAddArticleList(userArticles);
    } catch(e) {}
}

function closeAddArticles() { document.getElementById('add-articles-modal').style.display = 'none'; }

function filterAddArticles() {
    const q = document.getElementById('add-article-search').value.toLowerCase();
    const filtered = userArticles.filter(a =>
        (a.title||'').toLowerCase().includes(q) ||
        (a.content||'').toLowerCase().includes(q)
    );
    renderAddArticleList(filtered);
}

function renderAddArticleList(articles) {
    const el = document.getElementById('add-articles-list');
    if (!articles.length) { el.innerHTML = '<p style="color:var(--text-muted);">没有可添加的文章</p>'; return; }
    el.innerHTML = articles.map(a => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light);">
            <span style="font-size:0.9rem;">${esc(a.title||'无标题')}</span>
            <button class="btn btn-sm" onclick="addToCollection('${a.id}')">添加</button>
        </div>
    `).join('');
}

async function addToCollection(articleId) {
    const ids = [...collArticleIds, articleId];
    try {
        const resp = await fetch('/api/collections/' + collectionId, {
            method: 'PUT',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({article_ids: ids})
        });
        if ((await resp.json()).id) window.location.reload();
    } catch(e) { alert('添加失败'); }
}

// ===== Collaborators =====

async function manageCollaborators(collId) {
    document.getElementById('collaborators-modal').style.display = 'flex';
    await loadCollaboratorList();
}

function closeCollaborators() { document.getElementById('collaborators-modal').style.display = 'none'; }

async function loadCollaboratorList() {
    const el = document.getElementById('collaborators-list');
    try {
        const resp = await fetch('/api/collections', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const colls = await resp.json();
        const coll = colls.find(c => c.id === collectionId);
        if (!coll) { el.innerHTML = '<p style="color:var(--text-muted);">加载失败</p>'; return; }

        if (!(coll.collaborator_ids||[]).length) {
            el.innerHTML = '<p style="color:var(--text-muted);">暂无协作者</p>';
        } else {
            // Load user info
            const usersResp = await fetch('/api/admin/users', {headers:{'X-Requested-With':'XMLHttpRequest'}});
            const users = await usersResp.json();
            el.innerHTML = (coll.collaborator_ids||[]).map(uid => {
                const u = users.find(u => u.id === uid);
                return `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light);">
                    <span>${esc(u ? (u.display_name||u.username) : uid)}</span>
                    <button class="btn-text btn-danger" onclick="removeCollaborator('${uid}')">移除</button>
                </div>`;
            }).join('');
        }
    } catch(e) {}
}

async function addCollaborator(collId) {
    const username = document.getElementById('collaborator-username').value.trim();
    if (!username) return;
    try {
        // Find user by username
        const resp = await fetch('/api/admin/users', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const users = await resp.json();
        const user = users.find(u => u.username === username);
        if (!user) { alert('用户不存在'); return; }

        const addResp = await fetch('/api/collections/' + collectionId + '/collaborators', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({user_id: user.id})
        });
        if ((await addResp.json()).id) {
            document.getElementById('collaborator-username').value = '';
            loadCollaboratorList();
        }
    } catch(e) { alert('添加失败'); }
}

async function removeCollaborator(uid) {
    if (!confirm('确定移除该协作者？')) return;
    try {
        const resp = await fetch('/api/collections/' + collectionId + '/collaborators/' + uid, {
            method: 'DELETE',
            headers: {'X-Requested-With':'XMLHttpRequest'}
        });
        if ((await resp.json()).id) loadCollaboratorList();
    } catch(e) { alert('移除失败'); }
}

// ===== 批量选择 =====

function collectionUpdateBatchBar() {
    const checked = document.querySelectorAll('#collection-articles .article-select:checked');
    const bar = document.getElementById('batch-bar');
    const count = document.getElementById('batch-count');
    if (checked.length > 0) {
        bar.style.display = 'flex';
        count.textContent = '已选 ' + checked.length + ' 篇';
    } else {
        bar.style.display = 'none';
    }
}

function collectionClearSelection() {
    document.querySelectorAll('#collection-articles .article-select').forEach(cb => cb.checked = false);
    collectionUpdateBatchBar();
}

async function deleteCurrentCollection(id) {
    if (!confirm('确定删除此合辑？\n\n删除合辑不会删除其中的文章，但文章将不再关联到此合辑。')) return;
    try {
        const resp = await fetch('/api/collections/' + id, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await resp.json();
        if (data.ok) {
            window.location.href = baseUrl('/collections');
        } else {
            alert(data.error || '删除失败');
        }
    } catch (e) {
        alert('删除失败: ' + e.message);
    }
}

function closeAIModal() {
    document.getElementById('ai-modal').style.display = 'none';
}

function getCollectionSelectedIds() {
    return Array.from(document.querySelectorAll('#collection-articles .article-select:checked')).map(cb => cb.value);
}

async function collectionBatchAskAI() {
    const ids = getCollectionSelectedIds();
    if (!ids.length) return;
    const question = prompt('向 AI 提问（将基于选中文章内容回答）：');
    if (!question || !question.trim()) return;

    const modal = document.getElementById('ai-modal');
    const body = document.getElementById('ai-modal-body');
    const footer = document.getElementById('ai-modal-footer');
    document.getElementById('ai-modal-title').textContent = 'AI 回答';
    body.innerHTML = '<span style="color:var(--text-muted)">处理中...</span>';
    footer.textContent = '';
    modal.style.display = 'flex';

    try {
        const resp = await fetch('/api/ai/query-articles', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ article_ids: ids, question: question.trim() })
        });
        const result = await resp.json();
        if (result.text || result.answer) {
            body.textContent = result.text || result.answer;
            footer.textContent = '基于 ' + ids.length + ' 篇文章';
        } else if (result.error) {
            body.innerHTML = '<span style="color:var(--danger)">错误: ' + esc(result.error) + '</span>';
        }
    } catch (err) {
        body.innerHTML = '<span style="color:var(--danger)">请求失败: ' + esc(err.message) + '</span>';
    }
}
</script>
<?php endif; ?>
