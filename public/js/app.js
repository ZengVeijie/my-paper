/**
 * 平静之心 - 主应用脚本
 */

// Subdirectory deployment helpers
var bp = window.basePath && window.basePath !== '/' ? window.basePath : '';

function baseUrl(path) { return bp + path; }

// Monkey-patch fetch
(function() {
    if (bp) {
        var _fetch = window.fetch;
        window.fetch = function(url, opts) {
            if (typeof url === 'string' && url[0] === '/' && url[1] !== '/') {
                url = bp + url;
            }
            if (typeof opts !== 'undefined') return _fetch.call(window, url, opts);
            return _fetch.call(window, url);
        };
    }
})();

// ===== Markdown 引擎配置（含 LaTeX 支持） =====

(function() {
    if (typeof marked === 'undefined') return;

    marked.setOptions({ breaks: true, gfm: true });

    function escHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Block math: $$...$$
    const latexBlock = {
        name: 'latexBlock',
        level: 'block',
        start(src) { return src.indexOf('$$'); },
        tokenizer(src) {
            const m = src.match(/^\$\$\n?([\s\S]*?)\n?\$\$/);
            if (m) return { type: 'latexBlock', raw: m[0], math: m[1].trim() };
        },
        renderer(token) {
            if (typeof katex !== 'undefined') {
                try { return '<p>' + katex.renderToString(token.math, { displayMode: true, throwOnError: false }) + '</p>'; }
                catch (e) { /* fall through */ }
            }
            return '<pre><code>' + escHtml(token.math) + '</code></pre>';
        }
    };

    // Inline math: $...$
    const latexInline = {
        name: 'latexInline',
        level: 'inline',
        start(src) { return src.indexOf('$'); },
        tokenizer(src) {
            const m = src.match(/^\$(?!\$)([^\s\$](?:[^\$]*[^\s\$])?)\$/);
            if (m) return { type: 'latexInline', raw: m[0], math: m[1] };
        },
        renderer(token) {
            if (typeof katex !== 'undefined') {
                try { return katex.renderToString(token.math, { displayMode: false, throwOnError: false }); }
                catch (e) { /* fall through */ }
            }
            return '<code>' + escHtml(token.math) + '</code>';
        }
    };

    marked.use({ extensions: [latexBlock, latexInline] });
})();

// ===== 移动端菜单 =====

document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            document.body.classList.toggle('sidebar-open', sidebar.classList.contains('open'));
        });
        document.addEventListener('click', (e) => {
            if (!sidebar.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
                document.body.classList.remove('sidebar-open');
            }
        });
    }

    // 返回按钮：根据来源定制文字
    initBackLink();

    renderMarkdown();
    initAuthForms();
    initNotifications();
    initLightbox();

});

// ===== Markdown 渲染 =====

function renderMarkdown() {
    if (typeof marked === 'undefined') return;

    document.querySelectorAll('.rendered-content').forEach(el => {
        const raw = el.textContent || el.innerHTML;
        if (raw.trim() && !el.dataset.rendered) {
            // 与编辑器预览保持一致的预处理：多换行 → <br> 段落
            let processed = raw.replace(/\r?\n/g, '\n').replace(/\n{3,}/g, m => '\n\n' + '<br>'.repeat(m.length - 2) + '\n\n');
            el.innerHTML = marked.parse(processed);
            el.dataset.rendered = '1';
        }
    });
}

// ===== 认证表单 AJAX =====

function initAuthForms() {
    document.querySelectorAll('.auth-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            try {
                const resp = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(data)
                });
                const result = await resp.json();
                if (result.ok) {
                    window.location.href = baseUrl('/');
                } else {
                    alert(result.error || '操作失败');
                }
            } catch (err) {
                console.error(err);
                form.submit(); // fallback to non-AJAX
            }
        });
    });

    // Logout
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            await fetch('/api/auth/logout', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            window.location.href = baseUrl('/login');
        });
    }
}

// ===== 文章操作 =====

async function deleteArticle(id, redirectAfter = false) {
    if (!confirm('确定删除这篇文章？此操作不可恢复。')) return;
    try {
        const resp = await fetch('/api/articles/' + id, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await resp.json();
        if (result.ok) {
            if (redirectAfter) window.location.href = baseUrl('/');
            else window.location.reload();
        } else {
            alert(result.error || '删除失败');
        }
    } catch (err) {
        alert('删除失败: ' + err.message);
    }
}

async function changeVisibility(id, visibility) {
    try {
        const resp = await fetch('/api/articles/' + id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ visibility })
        });
        const result = await resp.json();
        if (!result.id) alert(result.error || '操作失败');
    } catch (err) {
        alert('操作失败: ' + err.message);
    }
}

function quickShare(articleId) {
    document.getElementById('share-ids').value = JSON.stringify([articleId]);
    document.getElementById('share-modal').style.display = 'flex';
}

function closeShareModal() {
    document.getElementById('share-modal').style.display = 'none';
}

async function createShare(e) {
    e.preventDefault();
    const ids = JSON.parse(document.getElementById('share-ids').value);
    const password = document.getElementById('share-password').value;
    const expires = document.getElementById('share-expires').value;
    const showComments = document.getElementById('share-comments').checked;

    try {
        const resp = await fetch('/api/share', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({
                type: 'article',
                target_ids: ids,
                password: password || null,
                expires_at: expires || null,
                show_comments: showComments
            })
        });
        const r = await resp.json();
        if (r.url) {
            closeShareModal();
            const url = window.location.origin + bp + '/share/' + r.code;
            prompt('分享链接已生成，复制以下地址:', url);
            window.location.reload();
        } else {
            alert(r.error || '生成失败');
        }
    } catch(err) { alert('生成失败'); }
}

function copyShareUrl(url) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(() => alert('链接已复制到剪贴板'));
    } else {
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        alert('链接已复制到剪贴板');
    }
}

function showToast(msg, type) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'info');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('toast-show'));
    setTimeout(() => { t.classList.remove('toast-show'); setTimeout(() => t.remove(), 400); }, 3000);
}

function showLoading(msg) {
    let overlay = document.getElementById('loading-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'loading-overlay';
        overlay.className = 'loading-overlay';
        overlay.innerHTML = '<div class="loading-spinner"></div><p class="loading-text"></p>';
        document.body.appendChild(overlay);
    }
    overlay.querySelector('.loading-text').textContent = msg || '处理中...';
    overlay.style.display = 'flex';
}

function hideLoading() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) overlay.style.display = 'none';
}

async function downloadFile(url, filename, showProgress) {
    if (showProgress !== false) showLoading('正在生成下载文件...');
    try {
        const r = await fetch(url);
        if (!r.ok) { showToast('下载失败: ' + r.status, 'error'); return; }
        if (showProgress !== false) hideLoading();
        const blob = await r.blob();
        const blobUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = blobUrl;
        a.download = filename || 'download.zip';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
    } catch (e) {
        hideLoading();
        showToast('下载失败: ' + e.message, 'error');
    }
}

// ===== 返回按钮 =====

function initBackLink() {
    const link = document.getElementById('back-link');
    if (!link) return;
    const ref = document.referrer;
    if (ref) {
        const refUrl = new URL(ref);
        var refPath = refUrl.pathname;
        if (refPath === '/' || refPath === '' || refPath === bp + '/' || refPath === bp) {
            link.textContent = '← 返回首页';
        } else if (refPath.indexOf('/collection/') !== -1) {
            link.textContent = '← 返回合辑';
        } else {
            link.textContent = '← 返回上一页';
        }
        link.href = ref;
    } else {
        link.href = baseUrl('/');
        link.textContent = '← 返回首页';
    }
}

// ===== 留言 =====

async function postComment(articleId, parentId) {
    const input = document.getElementById('comment-input');
    const content = input.value.trim();
    if (!content) return;
    if (!parentId) parentId = input.dataset.replyTo || null;
    const quoted = input.dataset.quoted || '';

    try {
        const resp = await fetch('/api/articles/' + articleId + '/comments', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ content, parent_id: parentId, quoted_text: quoted })
        });
        const result = await resp.json();
        if (result.id) {
            input.value = '';
            delete input.dataset.quoted;
            delete input.dataset.replyTo;
            const preview = document.getElementById('quoted-preview');
            if (preview) preview.style.display = 'none';
            window.location.reload();
        } else {
            alert(result.error || '留言失败');
        }
    } catch (err) {
        alert('留言失败: ' + err.message);
    }
}

function replyTo(commentId, userName) {
    const input = document.getElementById('comment-input');
    input.value = '@' + userName + ' ';
    input.focus();
    input.dataset.replyTo = commentId;
}

async function deleteComment(id) {
    if (!confirm('确定删除这条留言？')) return;
    try {
        const resp = await fetch('/api/comments/' + id, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await resp.json();
        if (result.ok) window.location.reload();
        else alert(result.error || '删除失败');
    } catch (err) {
        alert('删除失败: ' + err.message);
    }
}

// ===== 设置页 =====

async function updateProfile(e) {
    e.preventDefault();
    const form = e.target;
    const data = Object.fromEntries(new FormData(form).entries());
    try {
        const resp = await fetch('/api/auth/profile', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.id) alert('保存成功');
        else alert(result.error || '保存失败');
    } catch (err) {
        alert('保存失败: ' + err.message);
    }
}

async function updatePassword(e) {
    e.preventDefault();
    const form = e.target;
    const data = Object.fromEntries(new FormData(form).entries());
    try {
        const resp = await fetch('/api/auth/password', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.id) { alert('密码修改成功'); form.reset(); }
        else alert(result.error || '修改失败');
    } catch (err) {
        alert('修改失败: ' + err.message);
    }
}

async function updateApiKey(e) {
    e.preventDefault();
    const form = e.target;
    const data = Object.fromEntries(new FormData(form).entries());
    try {
        const resp = await fetch('/api/auth/profile', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.id) alert('保存成功');
        else alert(result.error || '保存失败');
    } catch (err) {
        alert('保存失败: ' + err.message);
    }
}

// ===== 管理页 =====

function switchAdminTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.admin-panel').forEach(p => p.style.display = 'none');

    const buttons = document.querySelectorAll('.tab-btn');
    if (tab === 'users' && buttons[0]) buttons[0].classList.add('active');
    if (tab === 'invites' && buttons[1]) buttons[1].classList.add('active');

    const panel = document.getElementById('admin-' + tab);
    if (panel) panel.style.display = 'block';

    if (tab === 'users') loadUsers();
    else loadInvites();
}

// Switch tab buttons (admin page only)
document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('admin-users')) return;
    const buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach((btn, i) => {
        btn.addEventListener('click', () => switchAdminTab(i === 0 ? 'users' : 'invites'));
    });
    loadUsers();
});

async function loadUsers() {
    try {
        const resp = await fetch('/api/admin/users', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const users = await resp.json();
        const tbody = document.querySelector('#users-table tbody');
        tbody.innerHTML = users.map(u => `
            <tr>
                <td>${esc(u.username)}</td>
                <td>${esc(u.display_name || u.username)}</td>
                <td>${u.role === 'admin' ? '管理员' : '用户'}</td>
                <td>${u.enabled ? '启用' : '禁用'}</td>
                <td>${esc(formatDate(u.created_at))}</td>
                <td>
                    <button class="btn-text" onclick="editUser('${u.id}')">编辑</button>
                    <button class="btn-text btn-danger" onclick="deleteUser('${u.id}')">删除</button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        console.error(err);
    }
}

let allUsers = [];

async function editUser(id) {
    if (!allUsers.length) {
        const resp = await fetch('/api/admin/users', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        allUsers = await resp.json();
    }
    const u = allUsers.find(u => u.id === id);
    if (!u) return;
    document.getElementById('edit-user-id').value = u.id;
    document.getElementById('edit-user-display').value = u.display_name || '';
    document.getElementById('edit-user-role').value = u.role;
    document.getElementById('edit-user-enabled').value = u.enabled ? '1' : '0';
    document.getElementById('edit-user-password').value = '';
    document.getElementById('user-modal').style.display = 'flex';
}

function closeUserModal() {
    document.getElementById('user-modal').style.display = 'none';
}

async function updateUser(e) {
    e.preventDefault();
    const id = document.getElementById('edit-user-id').value;
    const data = {
        display_name: document.getElementById('edit-user-display').value,
        role: document.getElementById('edit-user-role').value,
        enabled: document.getElementById('edit-user-enabled').value === '1'
    };
    const pw = document.getElementById('edit-user-password').value;
    if (pw) data.password = pw;

    try {
        const resp = await fetch('/api/admin/users/' + id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.id) { closeUserModal(); loadUsers(); }
        else alert(result.error || '操作失败');
    } catch (err) {
        alert('操作失败: ' + err.message);
    }
}

async function deleteUser(id) {
    if (!confirm('确定删除此用户？其文章和合辑不会被删除。')) return;
    try {
        const resp = await fetch('/api/admin/users/' + id, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await resp.json();
        if (result.ok) loadUsers();
        else alert(result.error || '操作失败');
    } catch (err) {
        alert('操作失败: ' + err.message);
    }
}

// ===== 邀请码 =====

function showCreateInvite() {
    document.getElementById('invite-modal').style.display = 'flex';
}

function closeInviteModal() {
    document.getElementById('invite-modal').style.display = 'none';
}

async function createInvite(e) {
    e.preventDefault();
    const data = {
        max_uses: parseInt(document.getElementById('invite-max-uses').value),
        expires_at: document.getElementById('invite-expires').value || null,
        note: document.getElementById('invite-note').value
    };
    try {
        const resp = await fetch('/api/admin/invites', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.code) {
            closeInviteModal();
            loadInvites();
            alert('邀请码: ' + result.code);
        } else {
            alert(result.error || '生成失败');
        }
    } catch (err) {
        alert('生成失败: ' + err.message);
    }
}

async function loadInvites() {
    try {
        const resp = await fetch('/api/admin/invites', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const invites = await resp.json();
        const tbody = document.querySelector('#invites-table tbody');
        tbody.innerHTML = invites.map(inv => `
            <tr>
                <td><code>${esc(inv.code)}</code></td>
                <td>${esc(inv.note || '-')}</td>
                <td>${inv.used_count} / ${inv.max_uses}</td>
                <td>${inv.expires_at ? formatDate(inv.expires_at) : '永久'}</td>
                <td>${inv.enabled ? (inv.used_count >= inv.max_uses ? '已用完' : '有效') : '已作废'}</td>
                <td>
                    <button class="btn-text btn-danger" onclick="deleteInvite('${inv.code}')">作废</button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        console.error(err);
    }
}

async function deleteInvite(code) {
    if (!confirm('确定作废此邀请码？')) return;
    try {
        const resp = await fetch('/api/admin/invites/' + code, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await resp.json();
        if (result.ok) loadInvites();
        else alert(result.error || '操作失败');
    } catch (err) {
        alert('操作失败: ' + err.message);
    }
}

// ===== 合辑 =====

if (document.getElementById('collections-grid')) {
    loadCollections();
}

async function loadCollections() {
    try {
        const resp = await fetch('/api/collections', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const colls = await resp.json();
        const grid = document.getElementById('collections-grid');
        if (!colls.length) {
            grid.innerHTML = '<div class="empty-state"><p>还没有合辑</p></div>';
            return;
        }
        grid.innerHTML = colls.map(c => `
            <div class="collection-card-wrap">
                <a href="${bp}/collection/${c.id}" class="collection-card">
                    ${c.cover ? `<img src="${esc(c.cover[0] === '/' ? bp + c.cover : c.cover)}" class="coll-cover" alt="">` : `<div class="coll-cover" style="background:var(--accent-light);display:flex;align-items:center;justify-content:center;color:var(--accent);font-family:var(--font-body);font-size:1.5rem;">${esc(c.name[0] || '')}</div>`}
                    <h3>${esc(c.name)}</h3>
                    ${c.description ? `<p>${esc(c.description).substring(0, 100)}</p>` : ''}
                    <div class="coll-meta">${(c.article_ids || []).length} 篇文章</div>
                </a>
                ${c.user_id === window.currentUserId ? `<button class="coll-delete-btn" onclick="event.preventDefault();deleteCollection('${c.id}')" title="删除合辑">&times;</button>` : ''}
            </div>
        `).join('');
    } catch (err) {
        console.error(err);
    }
}

async function deleteCollection(id) {
    if (!confirm('确定删除此合辑？\n\n删除合辑不会删除其中的文章，但文章将不再关联到此合辑。')) return;
    try {
        const resp = await fetch('/api/collections/' + id, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await resp.json();
        if (data.ok) {
            showToast('合辑已删除');
            loadCollections();
        } else {
            showToast(data.error || '删除失败', 'error');
        }
    } catch (e) {
        showToast('删除失败: ' + e.message, 'error');
    }
}

function showCreateCollection() {
    document.getElementById('collection-modal').style.display = 'flex';
    document.getElementById('coll-modal-title').textContent = '创建合辑';
    document.getElementById('coll-id').value = '';
    document.getElementById('coll-name').value = '';
    document.getElementById('coll-desc').value = '';
    document.getElementById('coll-cover').value = '';
    document.getElementById('coll-cover-preview').innerHTML = '';
}

async function editCollection(id) {
    try {
        const resp = await fetch('/api/collections', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const colls = await resp.json();
        const coll = colls.find(c => c.id === id);
        if (!coll) { alert('合辑不存在'); return; }

        document.getElementById('collection-modal').style.display = 'flex';
        document.getElementById('coll-modal-title').textContent = '编辑合辑';
        document.getElementById('coll-id').value = coll.id;
        document.getElementById('coll-name').value = coll.name || '';
        document.getElementById('coll-desc').value = coll.description || '';
        document.getElementById('coll-cover').value = coll.cover || '';
        const preview = document.getElementById('coll-cover-preview');
        if (coll.cover) {
            preview.innerHTML = '<img src="' + esc(coll.cover) + '" style="max-width:200px;max-height:120px;border-radius:4px;margin-top:4px;">';
        } else {
            preview.innerHTML = '';
        }
    } catch (err) {
        alert('加载失败: ' + err.message);
    }
}

async function uploadCollectionCover() {
    const fileInput = document.getElementById('coll-cover-file');
    const file = fileInput.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('file', file);

    try {
        const resp = await fetch('/api/upload', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await resp.json();
        if (result.url) {
            document.getElementById('coll-cover').value = result.url;
            const preview = document.getElementById('coll-cover-preview');
            if (file.type.startsWith('image/')) {
                preview.innerHTML = '<img src="' + result.url + '" style="max-width:200px;max-height:120px;border-radius:4px;margin-top:4px;">';
            }
        } else if (result.error) {
            alert(result.error);
        }
    } catch (err) {
        alert('上传失败: ' + err.message);
    }
    fileInput.value = '';
}
function closeCollectionModal() { document.getElementById('collection-modal').style.display = 'none'; }

async function saveCollection(e) {
    e.preventDefault();
    const id = document.getElementById('coll-id').value;
    const data = {
        name: document.getElementById('coll-name').value,
        description: document.getElementById('coll-desc').value,
        cover: document.getElementById('coll-cover').value,
    };
    const url = id ? '/api/collections/' + id : '/api/collections';
    const method = id ? 'PUT' : 'POST';

    try {
        const resp = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.id) { closeCollectionModal(); if (typeof loadCollections === 'function') loadCollections(); else window.location.reload(); }
        else alert(result.error || '保存失败');
    } catch (err) {
        alert('保存失败: ' + err.message);
    }
}

// ===== 工具函数 =====

function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0') + ' ' +
        String(d.getHours()).padStart(2, '0') + ':' +
        String(d.getMinutes()).padStart(2, '0');
}

// ===== 通知系统 =====

async function initNotifications() {
    const bell = document.getElementById('notify-bell');
    if (!bell) return;

    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleNotifyDropdown();
    });

    document.addEventListener('click', (e) => {
        const dd = document.getElementById('notify-dropdown');
        if (dd && !dd.contains(e.target) && e.target !== bell && !bell.contains(e.target)) {
            dd.classList.remove('open');
        }
    });

    await refreshNotifyBadge();
}

async function refreshNotifyBadge() {
    try {
        const resp = await fetch('/api/notifications', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await resp.json();
        const badge = document.getElementById('notify-badge');
        if (badge) {
            if (data.unread > 0) {
                badge.style.display = 'flex';
                badge.textContent = data.unread > 99 ? '99+' : data.unread;
            } else {
                badge.style.display = 'none';
            }
        }
    } catch (e) { /* ignore */ }
}

async function toggleNotifyDropdown() {
    let dd = document.getElementById('notify-dropdown');
    if (dd) {
        dd.classList.toggle('open');
        if (dd.classList.contains('open')) await loadNotifyDropdown();
        return;
    }

    // Create dropdown
    dd = document.createElement('div');
    dd.id = 'notify-dropdown';
    dd.className = 'notify-dropdown open';
    document.body.appendChild(dd);
    await loadNotifyDropdown();
}

async function loadNotifyDropdown() {
    const dd = document.getElementById('notify-dropdown');
    if (!dd) return;

    try {
        const resp = await fetch('/api/notifications', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await resp.json();
        if (!data.items || !data.items.length) {
            dd.innerHTML = '<div class="notify-empty">暂无通知</div>';
            return;
        }
        dd.innerHTML = data.items.map(n => `
            <div class="notify-item${n.read ? '' : ' unread'}" onclick="openNotify('${esc(n.id)}','${esc(n.link)}')">
                <div>${esc(n.message)}</div>
                <div class="notify-time">${formatDate(n.created_at)}</div>
            </div>
        `).join('') + `<div class="notify-actions"><button class="btn-text" onclick="markAllRead()">全部已读</button></div>`;
    } catch (e) { dd.innerHTML = '<div class="notify-empty">加载失败</div>'; }
}

async function openNotify(id, link) {
    try {
        await fetch('/api/notifications/read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ id })
        });
    } catch (e) { /* ignore */ }
    document.getElementById('notify-dropdown')?.classList.remove('open');
    await refreshNotifyBadge();
    if (link) window.location.href = link;
}

async function markAllRead() {
    try {
        await fetch('/api/notifications/read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({})
        });
    } catch (e) { /* ignore */ }
    await loadNotifyDropdown();
    await refreshNotifyBadge();
}

// ===== 图片灯箱 =====

function initLightbox() {
    if (document.getElementById('lightbox-el')) return;

    const lb = document.createElement('div');
    lb.id = 'lightbox-el';
    lb.className = 'lightbox';
    lb.innerHTML = '<button class="lightbox-close" onclick="closeLightbox()">x</button><img id="lightbox-img" src="" alt="">';
    document.body.appendChild(lb);

    lb.addEventListener('click', (e) => {
        if (e.target === lb) closeLightbox();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeLightbox();
    });
}

function openLightbox(src) {
    const lb = document.getElementById('lightbox-el');
    const img = document.getElementById('lightbox-img');
    if (lb && img) {
        img.src = src;
        lb.classList.add('open');
    }
}

function closeLightbox() {
    const lb = document.getElementById('lightbox-el');
    if (lb) lb.classList.remove('open');
}

// 给文章中所有图片绑定点击事件
document.addEventListener('click', (e) => {
    if (e.target.tagName === 'IMG' && e.target.closest('.rendered-content, .article-body')) {
        openLightbox(e.target.src);
    }
});
