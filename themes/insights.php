<?php
$user = current_user();
$all_articles = json_list(DATA_DIR . '/articles');
// Filter accessible articles
$collab_ids = [];
$collections = json_list(DATA_DIR . '/collections');
foreach ($collections as $c) {
    if (in_array($user['id'], $c['collaborator_ids'] ?? [])) {
        foreach ($c['article_ids'] ?? [] as $aid) $collab_ids[] = $aid;
    }
}
$collab_ids = array_unique($collab_ids);
$articles = [];
foreach ($all_articles as $a) {
    $can = ($a['user_id'] ?? '') === $user['id']
        || in_array($a['id'], $collab_ids)
        || ($a['visibility'] ?? 'private') !== 'private'
        || $user['role'] === 'admin';
    if ($can) $articles[] = $a;
}
// Sort by created_at desc
usort($articles, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
?>
<div class="page-header">
    <h1>洞见</h1>
</div>

<div class="admin-tabs">
    <button class="tab-btn active" onclick="switchInsightsTab('sentiment', event)">情感分析</button>
    <button class="tab-btn" onclick="switchInsightsTab('related', event)">相关回顾</button>
    <button class="tab-btn" onclick="switchInsightsTab('summary', event)">周月总结</button>
    <button class="tab-btn" onclick="switchInsightsTab('stats', event)">写作统计</button>
</div>

<!-- ===== 情感分析 ===== -->
<div class="admin-panel" id="insights-sentiment">
    <section class="settings-section">
        <h2>文章情感总览</h2>
        <p class="section-desc">AI 自动分析或手动标记每篇文章的情感基调</p>
        <div id="sentiment-summary" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;"></div>
        <div id="sentiment-list">
            <p style="color:var(--text-muted);font-size:0.85rem;">加载中...</p>
        </div>
    </section>
</div>

<!-- ===== 相关回顾 ===== -->
<div class="admin-panel" id="insights-related" style="display:none">
    <section class="settings-section">
        <h2>相关文章发现</h2>
        <p class="section-desc">选择一篇文章，AI 将从你的历史文章中找出主题相关的内容</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
            <select id="related-article-select" style="flex:1;min-width:200px;padding:8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.85rem;">
                <option value="">选择文章...</option>
                <?php foreach ($articles as $a): ?>
                <option value="<?= h($a['id']) ?>"><?= h(($a['title'] ?: '无标题') . ' — ' . format_date($a['created_at'])) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" onclick="findRelated()">查找关联</button>
            <button class="btn btn-sm" onclick="randomExplore()">随机探索</button>
        </div>
        <div id="related-result"></div>
    </section>
</div>

<!-- ===== 周月总结 ===== -->
<div class="admin-panel" id="insights-summary" style="display:none">
    <section class="settings-section">
        <h2>生成周期总结</h2>
        <p class="section-desc">选择时间范围，AI 基于该时段内的文章生成回顾总结</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
            <button class="btn btn-sm preset-btn" onclick="setDatePreset('week')">本周</button>
            <button class="btn btn-sm preset-btn" onclick="setDatePreset('month')">本月</button>
            <button class="btn btn-sm preset-btn" onclick="setDatePreset('lastmonth')">上个月</button>
            <input type="date" id="summary-from" style="padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font-ui);font-size:0.85rem;">
            <span style="color:var(--text-muted);">至</span>
            <input type="date" id="summary-to" style="padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font-ui);font-size:0.85rem;">
            <button class="btn btn-primary btn-sm" onclick="generateSummary()">生成总结</button>
        </div>
        <div id="summary-result"></div>
    </section>
</div>

<!-- ===== 写作统计 ===== -->
<div class="admin-panel" id="insights-stats" style="display:none">
    <section class="settings-section">
        <h2>写作统计</h2>
        <p class="section-desc">基于你的文章数据生成统计图表与 AI 洞察</p>
        <div class="stats-grid" id="stats-grid">
            <!-- filled by JS -->
        </div>
        <div id="stats-insight" style="margin-top:20px;"></div>
    </section>
</div>

<style>
.sentiment-badge {
    display:inline-block;
    padding:2px 10px;
    border-radius:12px;
    font-size:0.78rem;
    font-family:var(--font-ui);
    cursor:pointer;
    border:1px solid transparent;
    transition: border-color 0.15s;
}
.sentiment-badge:hover { border-color: var(--border); }
.sentiment-喜悦 { background:#fff7e0; color:#8a6d14; }
.sentiment-忧伤 { background:#e8f0fe; color:#3a5a8c; }
.sentiment-愤怒 { background:#fde8e8; color:#8c1c1c; }
.sentiment-焦虑 { background:#fff0e8; color:#8c4a1c; }
.sentiment-平静 { background:#e8f5e9; color:#2a6c2e; }
.sentiment-兴奋 { background:#fce4ec; color:#8c1c5a; }
.sentiment-疲惫 { background:#f0f0f0; color:#555; }
.sentiment-感激 { background:#fff8e1; color:#6c5a14; }
.sentiment-empty { background:var(--bg); color:var(--text-muted); }

.stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:768px){ .stats-grid{grid-template-columns:1fr;} }
.stat-card {
    background:var(--bg-card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:16px;
}
.stat-card h3 {
    font-size:0.9rem;
    margin:0 0 12px;
    font-family:var(--font-ui);
}
.stat-number {
    font-size:2rem;
    font-weight:600;
    color:var(--accent);
}
.stat-label {
    font-size:0.75rem;
    color:var(--text-muted);
    font-family:var(--font-ui);
}
.bar-chart { display:flex; align-items:flex-end; gap:2px; height:100px; }
.bar-chart .bar {
    flex:1;
    background:var(--accent);
    border-radius:3px 3px 0 0;
    min-width:6px;
    position:relative;
    transition:height 0.3s;
}
.bar-chart .bar:hover { opacity:0.75; }
.tag-cloud { display:flex; flex-wrap:wrap; gap:6px; }
.tag-cloud .tc-tag {
    padding:3px 10px;
    border-radius:12px;
    font-size:0.8rem;
    font-family:var(--font-ui);
    color:var(--text);
    border:1px solid var(--border);
}
.time-heatmap { display:grid; grid-template-columns:repeat(24,1fr); gap:1px; height:40px; }
.time-heatmap .hm-cell {
    border-radius:2px;
    background:var(--border-light);
    transition:background 0.2s;
}
.summary-card {
    background:var(--bg-card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:20px;
    margin-top:16px;
}
.summary-card h3 { margin:0 0 12px; font-size:1.1rem; }
.summary-card .events { margin:12px 0; padding-left:20px; }
.summary-card .events li { margin:4px 0; font-size:0.9rem; line-height:1.6; }
.summary-card .mood-trend { color:var(--text-muted); font-size:0.85rem; margin-top:8px; font-style:italic; }
</style>

<script>
// Local helpers (app.js loads after this)
var _esc = document.createElement('textarea');
function esc(s) { _esc.textContent = s || ''; return _esc.innerHTML; }
function showToast(msg, type) {
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'info');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function() { t.classList.add('toast-show'); }, 10);
    setTimeout(function() { t.classList.remove('toast-show'); setTimeout(function() { t.remove(); }, 300); }, 3000);
}

const sentimentLabels = ['喜悦','忧伤','愤怒','焦虑','平静','兴奋','疲惫','感激'];
const sentimentColors = {
    '喜悦':'#fff7e0','忧伤':'#e8f0fe','愤怒':'#fde8e8','焦虑':'#fff0e8',
    '平静':'#e8f5e9','兴奋':'#fce4ec','疲惫':'#f0f0f0','感激':'#fff8e1'
};
const sentimentTextColors = {
    '喜悦':'#8a6d14','忧伤':'#3a5a8c','愤怒':'#8c1c1c','焦虑':'#8c4a1c',
    '平静':'#2a6c2e','兴奋':'#8c1c5a','疲惫':'#555','感激':'#6c5a14'
};

// ===== Tab 切换 =====
function switchInsightsTab(name, ev) {
    document.querySelectorAll('.admin-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('insights-' + name);
    if (panel) panel.style.display = '';
    if (ev && ev.target) ev.target.classList.add('active');
    if (name === 'sentiment') loadSentiments();
    if (name === 'stats') loadStats();
}

// ===== 1. 情感分析 =====
document.addEventListener('DOMContentLoaded', loadSentiments);
async function loadSentiments() {
    const listEl = document.getElementById('sentiment-list');
    const summaryEl = document.getElementById('sentiment-summary');
    try {
        const resp = await fetch('/api/articles', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const data = await resp.json();
        const articles = data.articles || data;
        if (!Array.isArray(articles)) return;

        // Summary counts
        const counts = {};
        sentimentLabels.forEach(s => counts[s] = 0);
        let manual = 0, ai = 0, none = 0;
        articles.forEach(a => {
            const s = a.sentiment;
            if (s && s.mood) { counts[s.mood] = (counts[s.mood]||0) + 1; if (s.source === 'ai') ai++; else manual++; }
            else none++;
        });
        summaryEl.innerHTML = Object.entries(counts).map(([mood, cnt]) => {
            if (!cnt) return '';
            return `<span class="sentiment-badge sentiment-${mood}" onclick="filterSentiment('${mood}')" style="cursor:pointer;" title="筛选${mood}的文章">${mood} ${cnt}篇</span>`;
        }).join('') + `<span style="font-size:0.75rem;color:var(--text-muted);font-family:var(--font-ui);">（AI分析 ${ai} · 手动 ${manual} · 未标记 ${none}）</span>`;

        // Article list
        listEl.innerHTML = articles.map(a => renderSentimentRow(a)).join('');
    } catch(e) {
        console.error(e);
        document.getElementById('sentiment-list').innerHTML = '<p style="color:var(--danger);">加载失败，请刷新重试</p>';
    }
}

function renderSentimentRow(a) {
    const s = a.sentiment;
    const mood = s && s.mood ? s.mood : '';
    const src = s && s.source === 'ai' ? 'AI' : (s && s.source === 'manual' ? '手动' : '');
    const badge = mood
        ? `<span class="sentiment-badge sentiment-${mood}" id="sb-${a.id}" title="点击修改" onclick="editSentiment('${a.id}', event)">${mood}${src ? ' ('+src+')' : ''}</span>`
        : `<span class="sentiment-badge sentiment-empty" id="sb-${a.id}" title="点击设置情感" onclick="editSentiment('${a.id}', event)">未标记</span>`;
    const title = esc(a.title || '无标题');
    const date = a.created_at ? new Date(a.created_at).toLocaleDateString('zh-CN') : '';
    return `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light);gap:12px;">
        <div style="min-width:0;flex:1;">
            <a href="/article/${a.id}" style="font-size:0.9rem;">${title}</a>
            <span style="font-size:0.75rem;color:var(--text-muted);margin-left:8px;">${date}</span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
            ${badge}
            <button class="btn btn-sm" onclick="analyzeSentiment('${a.id}')" title="AI 分析情感">分析</button>
        </div>
    </div>`;
}

async function analyzeSentiment(articleId) {
    const badgeEl = document.getElementById('sb-' + articleId);
    if (!badgeEl) { loadSentiments(); return; }
    badgeEl.textContent = '分析中...';
    badgeEl.className = 'sentiment-badge sentiment-empty';
    badgeEl.removeAttribute('onclick');
    try {
        const resp = await fetch('/api/ai/sentiment', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({article_id: articleId})
        });
        const r = await resp.json();
        if (r.sentiment) {
            const s = r.sentiment;
            const src = s.source === 'ai' ? ' (AI)' : '';
            badgeEl.textContent = s.mood + src;
            badgeEl.className = 'sentiment-badge sentiment-' + s.mood;
            badgeEl.setAttribute('onclick', "editSentiment('" + articleId + "', event)");
            badgeEl.title = '点击修改';
            loadSentiments();
        } else if (r.error) {
            alert(r.error);
            loadSentiments();
        } else {
            loadSentiments();
        }
    } catch(e) {
        alert('分析失败: ' + (e.message || ''));
        loadSentiments();
    }
}

async function editSentiment(articleId, ev) {
    ev.stopPropagation();
    const current = prompt('设置情感（' + sentimentLabels.join('/') + '）：');
    if (!current) return;
    if (!sentimentLabels.includes(current)) { alert('请输入有效的情感标签：' + sentimentLabels.join('/')); return; }
    try {
        const resp = await fetch('/api/articles/' + articleId, {
            method:'PUT',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({sentiment: {mood: current, source: 'manual', intensity: 5, keywords: []}})
        });
        const r = await resp.json();
        if (r.id) loadSentiments();
        else alert(r.error || '设置失败');
    } catch(e) { alert('设置失败'); }
}

// ===== 2. 相关回顾 =====
async function findRelated() {
    const select = document.getElementById('related-article-select');
    const id = select.value;
    if (!id) { alert('请先选择一篇文章'); return; }
    const resultEl = document.getElementById('related-result');
    resultEl.innerHTML = '<p style="color:var(--text-muted);">AI 正在查找相关文章...</p>';
    try {
        const resp = await fetch('/api/ai/related', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({article_id: id})
        });
        const r = await resp.json();
        if (r.error) { resultEl.innerHTML = '<p style="color:var(--danger);">'+esc(r.error)+'</p>'; return; }
        if (!r.articles || !r.articles.length) {
            resultEl.innerHTML = '<p style="color:var(--text-muted);">未找到明显相关的文章</p>';
            return;
        }
        resultEl.innerHTML = r.articles.map(a =>
            `<div style="padding:10px 0;border-bottom:1px solid var(--border-light);">
                <a href="/article/${a.id}" style="font-weight:500;">${esc(a.title || '无标题')}</a>
                <span style="font-size:0.75rem;color:var(--text-muted);margin-left:8px;">${a.created_at ? new Date(a.created_at).toLocaleDateString('zh-CN') : ''}</span>
                <div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;">${esc(a.reason || '')}</div>
            </div>`
        ).join('');
    } catch(e) { resultEl.innerHTML = '<p style="color:var(--danger);">请求失败</p>'; }
}

async function randomExplore() {
    const select = document.getElementById('related-article-select');
    const options = Array.from(select.options).filter(o => o.value);
    if (!options.length) { alert('没有文章可供探索'); return; }
    const random = options[Math.floor(Math.random() * options.length)];
    select.value = random.value;
    findRelated();
}

// ===== 3. 周月总结 =====
function setDatePreset(type) {
    const now = new Date();
    let from = new Date(), to = new Date();
    if (type === 'week') {
        const day = now.getDay() || 7;
        from.setDate(now.getDate() - day + 1);
    } else if (type === 'month') {
        from = new Date(now.getFullYear(), now.getMonth(), 1);
    } else if (type === 'lastmonth') {
        from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        to = new Date(now.getFullYear(), now.getMonth(), 0);
    }
    document.getElementById('summary-from').value = from.toISOString().slice(0,10);
    document.getElementById('summary-to').value = to.toISOString().slice(0,10);
}

async function generateSummary() {
    const from = document.getElementById('summary-from').value;
    const to = document.getElementById('summary-to').value;
    if (!from || !to) { alert('请选择日期范围'); return; }
    const resultEl = document.getElementById('summary-result');
    resultEl.innerHTML = '<p style="color:var(--text-muted);">AI 正在生成总结...</p>';
    try {
        const resp = await fetch('/api/ai/period-summary', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({from, to})
        });
        const r = await resp.json();
        if (r.error) { resultEl.innerHTML = '<p style="color:var(--danger);">'+esc(r.error)+'</p>'; return; }
        resultEl.innerHTML = `<div class="summary-card">
            <h3>${esc(r.title || '周期总结')}</h3>
            <p style="line-height:1.8;font-size:0.9rem;">${esc(r.summary || '')}</p>
            ${r.events && r.events.length ? '<ul class="events">'+r.events.map(e=>'<li>'+esc(e)+'</li>').join('')+'</ul>' : ''}
            ${r.mood_trend ? '<p class="mood-trend">'+esc(r.mood_trend)+'</p>' : ''}
            <div style="margin-top:12px;display:flex;gap:8px;">
                <button class="btn btn-sm btn-primary" onclick="saveSummaryAsArticle()" id="save-summary-btn">保存为文章</button>
                <button class="btn btn-sm" onclick="copySummary()">复制 Markdown</button>
                <span id="summary-data" style="display:none;">${esc(JSON.stringify(r))}</span>
            </div>
        </div>`;
    } catch(e) { resultEl.innerHTML = '<p style="color:var(--danger);">请求失败</p>'; }
}

function copySummary() {
    const dataEl = document.getElementById('summary-data');
    if (!dataEl) return;
    try {
        const r = JSON.parse(dataEl.textContent);
        const md = '# ' + (r.title||'周期总结') + '\n\n' + (r.summary||'') + '\n\n' +
            (r.events&&r.events.length ? r.events.map(e=>'- '+e).join('\n')+'\n\n' : '') +
            (r.mood_trend ? '> '+r.mood_trend : '');
        navigator.clipboard.writeText(md).then(() => showToast('已复制到剪贴板', 'info'));
    } catch(e) {}
}

async function saveSummaryAsArticle() {
    const dataEl = document.getElementById('summary-data');
    if (!dataEl) return;
    try {
        const r = JSON.parse(dataEl.textContent);
        const md = '# ' + (r.title||'周期总结') + '\n\n' + (r.summary||'') + '\n\n' +
            (r.events&&r.events.length ? r.events.map(e=>'- '+e).join('\n')+'\n\n' : '') +
            (r.mood_trend ? '> '+r.mood_trend : '');
        const resp = await fetch('/api/articles', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({title: r.title||'周期总结', content: md, summary: (r.summary||'').slice(0,200), tags:['洞见总结'], visibility:'private'})
        });
        const result = await resp.json();
        if (result.id) {
            showToast('已保存为文章', 'info');
            document.getElementById('save-summary-btn').disabled = true;
        } else alert(result.error||'保存失败');
    } catch(e) { alert('保存失败'); }
}

// ===== 4. 写作统计 =====
async function loadStats() {
    try {
        const resp = await fetch('/api/articles', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const data = await resp.json();
        const articles = data.articles || data;
        if (!Array.isArray(articles)) return;
        computeStats(articles);
    } catch(e) { console.error(e); }
}

function computeStats(articles) {
    const grid = document.getElementById('stats-grid');
    if (!articles.length) {
        grid.innerHTML = '<p style="color:var(--text-muted);">暂无文章数据</p>';
        return;
    }

    // Basic stats
    let totalChars = 0;
    const tagCounts = {};
    const hourCounts = {};
    const monthlyCounts = {};
    for (let i = 0; i < 24; i++) hourCounts[i] = 0;

    articles.forEach(a => {
        const content = a.content || '';
        // 字数 = 去除空白后的字符数（中英文均按单字计）
        totalChars += content.replace(/[\s\r\n]+/g, '').length;
        (a.tags||[]).forEach(t => { tagCounts[t] = (tagCounts[t]||0)+1; });
        if (a.created_at) {
            const d = new Date(a.created_at);
            hourCounts[d.getHours()] = (hourCounts[d.getHours()]||0) + 1;
            const mk = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
            monthlyCounts[mk] = (monthlyCounts[mk]||0) + 1;
        }
    });

    const avgChars = Math.round(totalChars / articles.length);
    const topTags = Object.entries(tagCounts).sort((a,b)=>b[1]-a[1]).slice(0,15);
    const maxHour = Math.max(...Object.values(hourCounts), 1);
    const maxMonthly = Math.max(...Object.values(monthlyCounts), 1);
    const recentMonths = Object.entries(monthlyCounts).sort().slice(-6);
    const peakHour = Object.entries(hourCounts).sort((a,b)=>b[1]-a[1])[0];

    // Sentiment distribution
    const sentimentCounts = {};
    articles.forEach(a => {
        const mood = a.sentiment && a.sentiment.mood ? a.sentiment.mood : '未标记';
        sentimentCounts[mood] = (sentimentCounts[mood]||0) + 1;
    });

    grid.innerHTML = `
        <div class="stat-card">
            <h3>总览</h3>
            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <div><div class="stat-number">${articles.length}</div><div class="stat-label">文章总数</div></div>
                <div><div class="stat-number">${totalChars.toLocaleString()}</div><div class="stat-label">总字数</div></div>
                <div><div class="stat-number">${avgChars.toLocaleString()}</div><div class="stat-label">平均每篇字数</div></div>
            </div>
        </div>
        <div class="stat-card">
            <h3>发布时段分布</h3>
            <div class="time-heatmap">${Array.from({length:24},(_,h)=>`<div class="hm-cell" style="background:var(--accent);opacity:${((hourCounts[h]||0)/maxHour*0.9+0.1).toFixed(2)}" title="${h}:00 — ${hourCounts[h]||0}篇"></div>`).join('')}</div>
            <div style="display:flex;justify-content:space-between;font-size:0.65rem;color:var(--text-muted);margin-top:4px;">${['0','6','12','18','23'].map(h=>'<span>'+h+'时</span>').join('')}</div>
            ${peakHour ? `<div class="stat-label" style="margin-top:4px;">最常在 ${peakHour[0]}:00 前后写作（${peakHour[1]}篇）</div>` : ''}
        </div>
        <div class="stat-card">
            <h3>月度趋势</h3>
            <div class="bar-chart">${recentMonths.map(([m,c])=>`<div class="bar" style="height:${(c/maxMonthly*100).toFixed(1)}%" title="${m}: ${c}篇"></div>`).join('')}</div>
            <div style="display:flex;justify-content:space-between;font-size:0.65rem;color:var(--text-muted);margin-top:4px;">${recentMonths.map(([m])=>'<span>'+m+'</span>').join('')}</div>
        </div>
        <div class="stat-card">
            <h3>情感分布</h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">${Object.entries(sentimentCounts).map(([mood,cnt]) => {
                const cls = mood !== '未标记' ? `sentiment-${mood}` : 'sentiment-empty';
                return `<span class="sentiment-badge ${cls}">${mood}: ${cnt}篇</span>`;
            }).join('')}</div>
        </div>
        <div class="stat-card" style="grid-column:1/-1;">
            <h3>常用标签</h3>
            <div class="tag-cloud">${topTags.map(([t,c])=>`<span class="tc-tag" style="font-size:${0.75+Math.min(c/topTags[0][1],2)*0.3}rem;">${esc(t)} (${c})</span>`).join('')}</div>
            ${!topTags.length ? '<span class="stat-label">暂无标签数据</span>' : ''}
        </div>
    `;

    // Request AI insight
    const insightEl = document.getElementById('stats-insight');
    insightEl.innerHTML = '<p style="color:var(--text-muted);">AI 正在生成洞察...</p>';
    fetch('/api/ai/writing-insights', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({
            stats: {
                total_articles: articles.length,
                total_chars: totalChars,
                avg_chars: avgChars,
                peak_hour: peakHour ? parseInt(peakHour[0]) : 0,
                top_tags: Object.entries(tagCounts).sort((a,b)=>b[1]-a[1]).slice(0,5).map(e=>e[0]),
                sentiment_distribution: sentimentCounts,
                monthly_trend: recentMonths
            }
        })
    }).then(r => r.json()).then(r => {
        if (r.insight) {
            insightEl.innerHTML = `<div class="summary-card"><h3>AI 写作洞察</h3><p style="line-height:1.8;font-size:0.9rem;">${esc(r.insight)}</p></div>`;
        } else {
            insightEl.innerHTML = '';
        }
    }).catch(() => { insightEl.innerHTML = ''; });
}
</script>
