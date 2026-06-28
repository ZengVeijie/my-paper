<?php $user = current_user(); ?>
<div class="page-header">
    <h1>设置</h1>
</div>

<div class="admin-tabs">
    <button class="tab-btn active" onclick="switchSettingsTab('profile', event)">个人设置</button>
    <button class="tab-btn" onclick="switchSettingsTab('pages', event)">页面设置</button>
    <button class="tab-btn" onclick="switchSettingsTab('assistant', event)">助手管理</button>
    <button class="tab-btn" onclick="switchSettingsTab('data', event)">数据管理</button>
    <button class="tab-btn" onclick="switchSettingsTab('shares', event)">分享管理</button>
    <button class="tab-btn" onclick="switchSettingsTab('apps', event)">洞见仓库</button>
</div>

<!-- 个人设置 -->
<div class="admin-panel" id="settings-profile">
    <section class="settings-section">
        <h2>个人信息</h2>
        <form id="profile-form" onsubmit="updateProfile(event)">
            <label class="field">
                <span>用户名</span>
                <input type="text" value="<?= h($user['username']) ?>" disabled>
                <span class="field-hint">用户名不可修改</span>
            </label>
            <label class="field">
                <span>显示名称</span>
                <input type="text" name="display_name" value="<?= h($user['display_name'] ?? '') ?>">
            </label>
            <button type="submit" class="btn btn-primary">保存</button>
        </form>
    </section>

    <section class="settings-section">
        <h2>修改密码</h2>
        <form id="password-form" onsubmit="updatePassword(event)">
            <label class="field">
                <span>当前密码</span>
                <input type="password" name="current_password" required>
            </label>
            <label class="field">
                <span>新密码</span>
                <input type="password" name="new_password" required minlength="6">
            </label>
            <button type="submit" class="btn btn-primary">修改密码</button>
        </form>
    </section>

</div>

<!-- 页面设置 -->
<div class="admin-panel" id="settings-pages" style="display:none">
    <section class="settings-section">
        <h2>首页展示</h2>
        <p class="section-desc">控制首页展示哪些内容，合辑始终在文章之前</p>
        <form id="pages-form" onsubmit="updatePages(event)">
            <div class="field">
                <div class="radio-group">
                    <label class="radio-label"><input type="radio" name="homepage_mode" value="both" <?= ($user['homepage_mode'] ?? 'both') === 'both' ? 'checked' : '' ?>> 合辑 + 文章</label>
                    <label class="radio-label"><input type="radio" name="homepage_mode" value="collections_only" <?= ($user['homepage_mode'] ?? '') === 'collections_only' ? 'checked' : '' ?>> 仅展示合辑</label>
                    <label class="radio-label"><input type="radio" name="homepage_mode" value="articles_only" <?= ($user['homepage_mode'] ?? '') === 'articles_only' ? 'checked' : '' ?>> 仅展示文章</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">保存</button>
        </form>
    </section>
</div>

<!-- 助手管理 -->
<div class="admin-panel" id="settings-assistant" style="display:none">
    <section class="settings-section">
        <h2>DeepSeek API</h2>
        <form id="apikey-form" onsubmit="updateApiKey(event)">
            <label class="field">
                <span>个人 API Key</span>
                <input type="password" name="deepseek_api_key" value="<?= h($user['deepseek_api_key'] ?? '') ?>" placeholder="留空则使用全局配置">
                <span class="field-hint">你的 Key 优先级高于全局配置</span>
            </label>
            <label class="field">
                <span>AI 最大输出长度</span>
                <input type="number" name="ai_max_tokens" value="<?= h($user['ai_max_tokens'] ?? '') ?>" placeholder="留空使用默认（推荐 2048-4096）" min="64" max="16384" step="64" style="max-width:240px;">
                <span class="field-hint">控制 AI 每次回复的最大长度，越大回答越完整但消耗 token 越多。范围 64-16384</span>
            </label>
            <button type="submit" class="btn btn-primary">保存</button>
        </form>
    </section>

    <section class="settings-section">
        <h2>AI 自定义模板</h2>
        <p class="section-desc">创建常用的 AI 指令模板，在编辑器中一键调用。提示词中请用 <code>{{text}}</code> 作为文字占位符。</p>
        <div id="template-list" style="margin-bottom:16px;">
            <p style="color:var(--text-muted);font-size:0.85rem;">加载中...</p>
        </div>
        <div style="border:1px solid var(--border);border-radius:var(--radius);padding:12px;background:var(--bg);margin-bottom:12px;">
            <label class="field" style="margin:0;">
                <span>AI 生成模板</span>
                <span class="field-hint">用一句话描述你想要的模板效果，AI 自动生成名称和提示词并填入下方表单</span>
            </label>
            <div style="display:flex;gap:8px;">
                <input type="text" id="tpl-gen-desc" placeholder="如：把文章改成小红书风格的笔记" style="flex:1;font-size:0.85rem;">
                <button type="button" class="btn btn-primary btn-sm" id="tpl-gen-btn" onclick="aiGenerateTemplate()" style="white-space:nowrap;">生成</button>
            </div>
        </div>
        <form id="template-form" onsubmit="saveTemplate(event)" style="border:1px solid var(--border);border-radius:var(--radius);padding:12px;background:var(--bg);">
            <input type="hidden" id="tpl-edit-id">
            <label class="field">
                <span>模板名称</span>
                <input type="text" id="tpl-name" placeholder="如：鲁迅风格" required>
            </label>
            <label class="field">
                <span>提示词</span>
                <textarea id="tpl-prompt" rows="2" placeholder="如：用鲁迅的文风改写以下文字：{{text}}" required style="font-family:var(--font-ui);font-size:0.85rem;"></textarea>
            </label>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm" id="tpl-submit-btn">添加模板</button>
                <button type="button" class="btn btn-sm" id="tpl-cancel-btn" style="display:none;" onclick="cancelEditTemplate()">取消编辑</button>
            </div>
        </form>
    </section>
</div>

<!-- 数据管理 -->
<div class="admin-panel" id="settings-data" style="display:none">
    <section class="settings-section">
        <h2>文章导出</h2>
        <p class="section-desc">导出你的文章为 Markdown 或 ZIP 包</p>
        <div class="export-actions">
            <a href="#" class="btn" onclick="exportAllData();return false">导出全部数据 (ZIP)</a>
            <span class="field-hint" style="margin-left:8px;">文章列表中可多选批量导出</span>
        </div>
    </section>

    <section class="settings-section">
        <h2>模板导出</h2>
        <p class="section-desc">导出你的 AI 自定义模板</p>
        <div class="export-actions">
            <a href="/api/export/templates" class="btn">导出模板数据 (CSV)</a>
        </div>
    </section>
</div>

<!-- 洞见仓库 -->
<div class="admin-panel" id="settings-apps" style="display:none">
    <section class="settings-section">
        <h2>我的应用</h2>
        <p class="section-desc">管理洞见页面的应用展示。拖拽排序、启用/禁用，或从仓库中彻底删除自定义应用。</p>
        <div id="apps-list" style="margin-bottom:20px;">
            <p style="color:var(--text-muted);font-size:0.85rem;">加载中...</p>
        </div>
    </section>

    <section class="settings-section">
        <h2>AI 生成新应用</h2>
        <p class="section-desc">用一句话描述你想要的分析/洞察需求，AI 将自动生成一个完整的洞见应用。例如：<em>"分析我的情绪波动周期并给出作息建议"</em>、<em>"根据我记录的事件生成年度时间线"</em></p>
        <div style="display:flex;gap:8px;align-items:flex-start;">
            <input type="text" id="app-gen-desc" placeholder="描述你想要的洞见应用..." style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font-ui);font-size:0.85rem;">
            <button type="button" class="btn btn-primary" id="app-gen-btn" onclick="generateApp()" style="white-space:nowrap;">生成应用</button>
        </div>
        <div id="app-gen-result" style="margin-top:12px;"></div>
    </section>
</div>

<!-- 分享管理 -->
<div class="admin-panel" id="settings-shares" style="display:none">
    <section class="settings-section">
        <h2>我的分享</h2>
        <p class="section-desc">管理你创建的分享链接</p>
        <div id="share-list" style="margin-top:12px;">
            <p style="color:var(--text-muted);font-size:0.85rem;">加载中...</p>
        </div>
    </section>
</div>

<script>
let sharesLoaded = false, templatesLoaded = false, appsLoaded = false;
function switchSettingsTab(name, ev) {
    document.querySelectorAll('.admin-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('settings-' + name).style.display = '';
    ev.target.classList.add('active');
    if (name === 'shares' && !sharesLoaded) { sharesLoaded = true; loadShares(); }
    if (name === 'assistant' && !templatesLoaded) { templatesLoaded = true; loadTemplates(); }
    if (name === 'apps' && !appsLoaded) { appsLoaded = true; loadApps(); }
}
async function updatePages(e) {
    // Reuse updateProfile which sends to /api/auth/profile
    await updateProfile(e);
}
async function loadShares() {
    try {
        const resp = await fetch('/api/shares', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const shares = await resp.json();
        const el = document.getElementById('share-list');
        if (!shares.length) { el.innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;">暂无分享链接</p>'; return; }
        el.innerHTML = shares.map(s => {
            const expired = s.expires_at && new Date(s.expires_at) < new Date();
            const created = s.created_at ? new Date(s.created_at).toLocaleDateString('zh-CN') : '';
            const expires = s.expires_at ? new Date(s.expires_at).toLocaleDateString('zh-CN') : '';
            const dateRange = created && expires ? `${created} ~ ${expires}` : (created ? `${created} 起` : '');
            const titles = (s.target_titles || []).map(t => esc(t)).join(', ');
            return `<div style="padding:10px 0;border-bottom:1px solid var(--border-light);">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <a href="/share/${esc(s.code)}" target="_blank" style="font-family:var(--font-ui);font-size:0.85rem;font-weight:500;">/share/${esc(s.code)}</a>
                    <button class="btn-text btn-danger" onclick="revokeShare('${s.code}')">撤销</button>
                </div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;line-height:1.5;">
                    ${titles ? '<div>文章：' + titles + '</div>' : ''}
                    ${dateRange ? '<div>有效期：' + dateRange + '</div>' : '<div>永久有效</div>'}
                    <span style="margin-right:6px;">${s.type==='collection'?'合辑':''} ${s.target_ids.length}篇</span>
                    ${s.password_hash ? '<span style="margin-right:6px;color:var(--accent);">密码保护</span>' : ''}
                    ${expired ? '<span style="color:var(--danger);">已过期</span>' : ''}
                </div>
            </div>`;
        }).join('');
    } catch(e) { console.error(e); }
}
async function revokeShare(code) {
    if (!confirm('确定撤销此分享链接？')) return;
    try {
        const resp = await fetch('/api/share/'+code, {method:'DELETE',headers:{'X-Requested-With':'XMLHttpRequest'}});
        const r = await resp.json();
        if (r.ok) loadShares();
        else alert(r.error||'撤销失败');
    } catch(e) { alert('撤销失败'); }
}

// ===== AI 模板管理 =====
async function loadTemplates() {
    try {
        const resp = await fetch('/api/ai/templates', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const templates = await resp.json();
        const el = document.getElementById('template-list');
        if (!templates.length) { el.innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;">暂无自定义模板</p>'; return; }
        allTemplates = templates;
        el.innerHTML = templates.map((t, i) =>
            `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light);gap:8px;">
                <div style="display:flex;flex-direction:column;gap:1px;flex-shrink:0;">
                    <button class="btn-text" onclick="moveTemplate('${t.id}', -1)" ${i === 0 ? 'disabled' : ''} style="font-size:0.65rem;padding:0 2px;line-height:1;" title="上移">▲</button>
                    <button class="btn-text" onclick="moveTemplate('${t.id}', 1)" ${i === templates.length-1 ? 'disabled' : ''} style="font-size:0.65rem;padding:0 2px;line-height:1;" title="下移">▼</button>
                </div>
                <div style="min-width:0;flex:1;">
                    <div style="font-weight:500;font-size:0.85rem;">${esc(t.name)}</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(t.prompt)}</div>
                </div>
                <div style="display:flex;gap:2px;flex-shrink:0;">
                    <button class="btn-text" onclick="editTemplate('${t.id}')">编辑</button>
                    <button class="btn-text btn-danger" onclick="deleteTemplate('${t.id}')">删除</button>
                </div>
            </div>`
        ).join('');
    } catch(e) { console.error(e); }
}
let allTemplates = [];
let tplEditId = null;

function saveTemplate(ev) {
    ev.preventDefault();
    if (tplEditId) updateTemplate(tplEditId);
    else createTemplate();
}

async function createTemplate() {
    const name = document.getElementById('tpl-name').value.trim();
    const prompt = document.getElementById('tpl-prompt').value.trim();
    if (!name || !prompt) return;
    try {
        const resp = await fetch('/api/ai/templates', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({name, prompt})
        });
        const r = await resp.json();
        if (r.error) { alert(r.error); return; }
        resetTemplateForm();
        loadTemplates();
    } catch(e) { alert('创建失败'); }
}

function editTemplate(id) {
    const t = allTemplates.find(t => t.id === id);
    if (!t) return;
    tplEditId = id;
    document.getElementById('tpl-edit-id').value = id;
    document.getElementById('tpl-name').value = t.name;
    document.getElementById('tpl-prompt').value = t.prompt;
    document.getElementById('tpl-submit-btn').textContent = '更新模板';
    document.getElementById('tpl-cancel-btn').style.display = '';
    document.getElementById('template-form').scrollIntoView({ behavior: 'smooth' });
}

async function updateTemplate(id) {
    const name = document.getElementById('tpl-name').value.trim();
    const prompt = document.getElementById('tpl-prompt').value.trim();
    if (!name || !prompt) return;
    try {
        const resp = await fetch('/api/ai/templates/' + id, {
            method:'PUT',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({name, prompt})
        });
        const r = await resp.json();
        if (r.error) { alert(r.error); return; }
        resetTemplateForm();
        loadTemplates();
    } catch(e) { alert('更新失败'); }
}

function cancelEditTemplate() {
    resetTemplateForm();
}

async function moveTemplate(id, direction) {
    const idx = allTemplates.findIndex(t => t.id === id);
    if (idx < 0) return;
    const newIdx = idx + direction;
    if (newIdx < 0 || newIdx >= allTemplates.length) return;

    const ids = allTemplates.map(t => t.id);
    ids.splice(idx, 1);
    ids.splice(newIdx, 0, id);

    try {
        const resp = await fetch('/api/ai/templates/reorder', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ids})
        });
        const r = await resp.json();
        if (r.error) { alert(r.error); return; }
        loadTemplates();
    } catch(e) { alert('排序失败'); }
}

async function aiGenerateTemplate() {
    const descInput = document.getElementById('tpl-gen-desc');
    const description = descInput.value.trim();
    if (!description) { alert('请先描述你想要的模板效果'); return; }

    const btn = document.getElementById('tpl-gen-btn');
    btn.disabled = true;
    btn.textContent = '生成中...';

    try {
        const resp = await fetch('/api/ai/generate-template', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({description})
        });
        const result = await resp.json();
        if (result.error) { alert(result.error); return; }
        if (result.name) document.getElementById('tpl-name').value = result.name;
        if (result.prompt) document.getElementById('tpl-prompt').value = result.prompt;
        document.getElementById('template-form').scrollIntoView({behavior:'smooth'});
    } catch (e) {
        alert('生成失败: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.textContent = '生成';
    }
}

function resetTemplateForm() {
    tplEditId = null;
    document.getElementById('tpl-edit-id').value = '';
    document.getElementById('tpl-name').value = '';
    document.getElementById('tpl-prompt').value = '';
    document.getElementById('tpl-submit-btn').textContent = '添加模板';
    document.getElementById('tpl-cancel-btn').style.display = 'none';
}

async function exportAllData() {
    showLoading('正在生成下载文件...');
    try {
        const resp = await fetch('/api/export/all');
        const data = await resp.json();
        if (!data.url) { hideLoading(); showToast(data.error || '导出失败', 'error'); return; }
        downloadFile(data.url, 'My_Paper_export.zip');
    } catch(e) {
        hideLoading();
        showToast('导出失败: ' + e.message, 'error');
    }
}

async function deleteTemplate(id) {
    if (!confirm('确定删除此模板？')) return;
    try {
        await fetch('/api/ai/templates/'+id, {method:'DELETE',headers:{'X-Requested-With':'XMLHttpRequest'}});
        cancelEditTemplate();
        loadTemplates();
    } catch(e) { alert('删除失败'); }
}

// ===== App Warehouse =====
let allApps = [];
let userAppIds = [];

async function loadApps() {
    try {
        var resp = await fetch('/api/insights/apps', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        allApps = await resp.json();
        userAppIds = <?= json_encode($user_insights_apps ?? [], JSON_UNESCAPED_UNICODE) ?>;
        renderAppList();
    } catch(e) { console.error(e); }
}

function renderAppList() {
    var el = document.getElementById('apps-list');
    if (!allApps.length) { el.innerHTML = '<p style="color:var(--text-muted);">暂无应用</p>'; return; }

    var enabledIds = userAppIds.slice();
    var disabledApps = allApps.filter(function(a) { return enabledIds.indexOf(a.id) === -1; });

    el.innerHTML =
        '<p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:8px;font-family:var(--font-ui);">已启用（' + enabledIds.length + '）— 拖动排序将影响洞见页展示顺序</p>' +
        enabledIds.map(function(id) {
            var a = allApps.find(function(x) { return x.id === id; });
            if (!a) return '';
            return renderAppRow(a, true, enabledIds);
        }).join('') +
        (disabledApps.length ? '<p style="font-size:0.8rem;color:var(--text-muted);margin:16px 0 8px;font-family:var(--font-ui);">未启用（' + disabledApps.length + '）</p>' : '') +
        disabledApps.map(function(a) { return renderAppRow(a, false, enabledIds); }).join('');
}

function renderAppRow(app, enabled, enabledIds) {
    var idx = enabledIds.indexOf(app.id);
    var isBuiltin = app.source === 'builtin';
    var sourceLabel = isBuiltin ? '内置' : (app.source === 'ai' ? 'AI 生成' : '自定义');
    var sortBtns = '';
    if (enabled) {
        sortBtns =
            '<button class="btn-text" onclick="moveApp(\'' + app.id + '\', -1)" ' + (idx === 0 ? 'disabled' : '') + ' style="font-size:0.65rem;padding:0 2px;" title="上移">▲</button>' +
            '<button class="btn-text" onclick="moveApp(\'' + app.id + '\', 1)" ' + (idx === enabledIds.length - 1 ? 'disabled' : '') + ' style="font-size:0.65rem;padding:0 2px;" title="下移">▼</button>';
    }

    return '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-light);gap:10px;">' +
        '<div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">' + sortBtns + '</div>' +
        '<div style="min-width:0;flex:1;">' +
            '<div style="font-weight:500;font-size:0.85rem;">' + esc(app.name) +
                ' <span style="font-size:0.65rem;color:var(--text-muted);font-family:var(--font-ui);">(' + sourceLabel + ')</span>' +
            '</div>' +
            '<div style="font-size:0.75rem;color:var(--text-muted);">' + esc(app.description || '') + '</div>' +
        '</div>' +
        '<div style="display:flex;gap:4px;flex-shrink:0;align-items:center;">' +
            '<button class="btn btn-sm" onclick="toggleApp(\'' + app.id + '\', ' + enabled + ')">' + (enabled ? '已启用' : '启用') + '</button>' +
            (isBuiltin ? '' : '<button class="btn-text btn-danger" onclick="deleteFromWarehouse(\'' + app.id + '\')" title="从仓库永久删除">删除</button>') +
        '</div>' +
    '</div>';
}

async function toggleApp(id, currentlyEnabled) {
    var ids = userAppIds.slice();
    if (currentlyEnabled) {
        if (!confirm('确定从洞见页移除此应用？应用仍在仓库中，随时可以重新启用。')) return;
        ids = ids.filter(function(x) { return x !== id; });
    } else {
        ids.push(id);
    }

    try {
        var resp = await fetch('/api/insights/apps/reorder', {
            method: 'PUT',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ids: ids})
        });
        var r = await resp.json();
        if (r.ok) {
            userAppIds = ids;
            renderAppList();
            showToast(currentlyEnabled ? '已从洞见移除' : '已添加到洞见', 'info');
        } else {
            alert(r.error || '操作失败');
        }
    } catch(e) { alert('请求失败'); }
}

async function moveApp(id, direction) {
    var ids = userAppIds.slice();
    var idx = ids.indexOf(id);
    if (idx < 0) return;
    var newIdx = idx + direction;
    if (newIdx < 0 || newIdx >= ids.length) return;
    ids.splice(idx, 1);
    ids.splice(newIdx, 0, id);

    try {
        var resp = await fetch('/api/insights/apps/reorder', {
            method: 'PUT',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ids: ids})
        });
        var r = await resp.json();
        if (r.ok) {
            userAppIds = ids;
            renderAppList();
        } else {
            alert(r.error || '排序失败');
        }
    } catch(e) { alert('排序失败'); }
}

async function deleteFromWarehouse(id) {
    var app = allApps.find(function(a) { return a.id === id; });
    if (!app) return;
    if (!confirm('确定从仓库中永久删除「' + app.name + '」？此操作不可恢复，所有用户的启用状态均会被清除。')) return;

    try {
        var resp = await fetch('/api/insights/apps/' + id, {
            method: 'DELETE',
            headers: {'X-Requested-With':'XMLHttpRequest'}
        });
        var r = await resp.json();
        if (r.ok) {
            userAppIds = userAppIds.filter(function(x) { return x !== id; });
            allApps = allApps.filter(function(a) { return a.id !== id; });
            renderAppList();
            showToast('已从仓库删除', 'info');
        } else {
            alert(r.error || '删除失败');
        }
    } catch(e) { alert('删除失败'); }
}

async function generateApp() {
    var descInput = document.getElementById('app-gen-desc');
    var description = descInput.value.trim();
    if (!description) { alert('请描述你想要的洞见应用'); return; }

    var btn = document.getElementById('app-gen-btn');
    var resultEl = document.getElementById('app-gen-result');
    btn.disabled = true;
    btn.textContent = '生成中...';
    resultEl.innerHTML = '<p style="color:var(--text-muted);">AI 正在设计应用...</p>';

    try {
        var resp = await fetch('/api/insights/apps/generate', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({description: description})
        });
        var result = await resp.json();
        if (result.error) { resultEl.innerHTML = '<p style="color:var(--danger);">' + esc(result.error) + '</p>'; return; }

        resultEl.innerHTML =
            '<div class="summary-card" style="margin-top:0;">' +
                '<h3>' + esc(result.name) + ' <span style="font-size:0.75rem;color:var(--text-muted);font-weight:normal;">生成成功</span></h3>' +
                '<p style="font-size:0.85rem;color:var(--text-muted);margin:8px 0;">' + esc(result.description || '') + '</p>' +
                '<div style="display:flex;gap:8px;">' +
                    '<button class="btn btn-primary btn-sm" onclick="addGeneratedApp(\'' + result.id + '\')">添加到洞见</button>' +
                    '<button class="btn btn-sm" onclick="document.getElementById(\'app-gen-result\').innerHTML=\'\';document.getElementById(\'app-gen-desc\').value=\'\';">关闭</button>' +
                '</div>' +
            '</div>';

        allApps.push(result);
        renderAppList();
        descInput.value = '';
    } catch(e) {
        resultEl.innerHTML = '<p style="color:var(--danger);">生成失败: ' + esc(e.message) + '</p>';
    } finally {
        btn.disabled = false;
        btn.textContent = '生成应用';
    }
}

async function addGeneratedApp(id) {
    var ids = userAppIds.slice();
    if (ids.indexOf(id) === -1) ids.push(id);
    try {
        var resp = await fetch('/api/insights/apps/reorder', {
            method: 'PUT',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ids: ids})
        });
        var r = await resp.json();
        if (r.ok) {
            userAppIds = ids;
            renderAppList();
            showToast('已添加到洞见页面', 'info');
        }
    } catch(e) { alert('添加失败'); }
}
</script>
