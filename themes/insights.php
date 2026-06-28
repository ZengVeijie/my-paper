<?php
$user = current_user();
$insights_apps = $insights_apps ?? [];
$all_insights_apps = $all_insights_apps ?? [];

// Build accessible article list for selects
$all_articles = json_list(DATA_DIR . '/articles');
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
usort($articles, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

// Scope options for analysis apps
$scope_options = [['value' => 'all', 'label' => '所有文章']];
foreach ($collections as $c) {
    $can = ($c['user_id'] ?? '') === $user['id']
        || in_array($user['id'], $c['collaborator_ids'] ?? [])
        || $user['role'] === 'admin';
    if ($can && !empty($c['article_ids'])) {
        $scope_options[] = ['value' => 'collection:' . $c['id'], 'label' => '合辑: ' . $c['name']];
    }
}
?>
<div class="page-header">
    <h1>洞见</h1>
</div>

<div class="admin-tabs">
    <?php foreach ($insights_apps as $i => $app): ?>
    <button class="tab-btn<?= $i === 0 ? ' active' : '' ?>" onclick="switchInsightsTab('<?= h($app['id']) ?>', event)"><?= h($app['name']) ?></button>
    <?php endforeach; ?>
    <?php if (empty($insights_apps)): ?>
    <p style="color:var(--text-muted);padding:8px;">尚未启用任何洞见应用，请前往 <a href="/settings">设置 → 洞见仓库</a> 启用</p>
    <?php endif; ?>
</div>

<?php foreach ($insights_apps as $i => $app): ?>
<?php $app_id = $app['id']; $is_first = ($i === 0); ?>
<!-- ===== <?= h($app['name']) ?> ===== -->
<div class="admin-panel" id="insights-<?= h($app_id) ?>"<?= $is_first ? '' : ' style="display:none"' ?>>
<?php if ($app['render_type'] === 'php'): ?>
<?php if ($app_id === 'sentiment'): ?>
    <section class="settings-section">
        <h2>文章情感总览</h2>
        <p class="section-desc">AI 自动分析或手动标记每篇文章的情感基调</p>
        <div id="sentiment-summary" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;"></div>
        <div id="sentiment-list">
            <p style="color:var(--text-muted);font-size:0.85rem;">加载中...</p>
        </div>
    </section>
<?php elseif ($app_id === 'related'): ?>
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
<?php elseif ($app_id === 'summary'): ?>
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
<?php elseif ($app_id === 'stats'): ?>
    <section class="settings-section">
        <h2>写作统计</h2>
        <p class="section-desc">基于你的文章数据生成统计图表与 AI 洞察</p>
        <div class="stats-grid" id="stats-grid"></div>
        <div id="stats-insight" style="margin-top:20px;"></div>
    </section>
<?php elseif ($app_id === 'tasks'): ?>
    <section class="settings-section">
        <h2>待办纵览</h2>
        <p class="section-desc">汇总所有文章中的待办事项，追踪完成进度</p>
        <div id="tasks-overview">
            <p style="color:var(--text-muted);font-size:0.85rem;">加载中...</p>
        </div>
    </section>
<?php elseif ($app_id === 'mbti'): ?>
    <section class="settings-section">
        <h2>MBTI 人格分析</h2>
        <p class="section-desc">AI 深度分析你的日记内容，推断 MBTI 人格类型并提供详细推理</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
            <select id="mbti-scope" style="flex:1;min-width:200px;padding:8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.85rem;">
                <?php foreach ($scope_options as $opt): ?>
                <option value="<?= h($opt['value']) ?>"><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" onclick="analyzeMBTI()">开始分析</button>
        </div>
        <div id="mbti-result"></div>
    </section>
<?php elseif ($app_id === 'cbt'): ?>
    <section class="settings-section">
        <h2>CBT 认知行为疗法</h2>
        <p class="section-desc">AI 识别你笔记中的认知扭曲，并提供具体的 CBT 干预建议</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
            <select id="cbt-scope" style="flex:1;min-width:200px;padding:8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.85rem;">
                <?php foreach ($scope_options as $opt): ?>
                <option value="<?= h($opt['value']) ?>"><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" onclick="analyzeCBT()">开始分析</button>
        </div>
        <div id="cbt-result"></div>
    </section>
<?php elseif ($app_id === 'blindspot'): ?>
    <section class="settings-section">
        <h2>盲区探索</h2>
        <p class="section-desc">AI 从你的日记中发现 3 个你看不见却能改变你的隐藏真相</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
            <select id="blindspot-scope" style="flex:1;min-width:200px;padding:8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);font-family:var(--font-ui);font-size:0.85rem;">
                <?php foreach ($scope_options as $opt): ?>
                <option value="<?= h($opt['value']) ?>"><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" onclick="analyzeBlindspot()">探索盲区</button>
        </div>
        <div id="blindspot-result"></div>
    </section>
<?php endif; ?>
<?php else: ?>
    <?= build_ai_app_template($app) ?>
<?php endif; ?>
</div>
<?php endforeach; ?>

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

/* New app styles */
.mbti-type-badge {
    display:inline-block;
    padding:12px 28px;
    border-radius:16px;
    font-size:2rem;
    font-weight:700;
    font-family:var(--font-ui);
    letter-spacing:0.2em;
    background:linear-gradient(135deg,var(--accent-light),#e8d5f5);
    color:var(--accent);
    margin-bottom:16px;
}
.mbti-dim-row {
    display:flex;
    align-items:center;
    gap:12px;
    margin:10px 0;
    font-size:0.85rem;
}
.mbti-dim-row .dim-label { width:40px; font-weight:600; font-family:var(--font-ui); flex-shrink:0; }
.mbti-dim-row .dim-bar {
    flex:1;
    height:8px;
    background:var(--border);
    border-radius:4px;
    overflow:hidden;
}
.mbti-dim-row .dim-fill {
    height:100%;
    background:var(--accent);
    border-radius:4px;
    transition:width 0.5s;
}
.mbti-dim-row .dim-pct { width:42px; font-size:0.75rem; color:var(--text-muted); flex-shrink:0; }
.mbti-conf { font-size:0.8rem; color:var(--text-muted); margin-top:8px; }

.distortion-card, .blindspot-card {
    background:var(--bg-card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:16px;
    margin-bottom:12px;
}
.distortion-card .dist-type {
    display:inline-block;
    padding:2px 10px;
    border-radius:10px;
    font-size:0.75rem;
    font-family:var(--font-ui);
    background:var(--accent-light);
    color:var(--accent);
    margin-bottom:8px;
}
.distortion-card .dist-quote {
    background:var(--bg);
    padding:8px 12px;
    border-left:3px solid var(--accent);
    margin:8px 0;
    font-size:0.85rem;
    line-height:1.6;
    color:var(--text-secondary);
}
.distortion-card .dist-intervention {
    font-size:0.85rem;
    line-height:1.7;
    color:var(--text);
}
.blindspot-card h4 { margin:0 0 8px; font-size:1rem; color:var(--accent); }
.blindspot-card .bs-evidence {
    background:var(--bg);
    padding:8px 12px;
    border-left:3px solid var(--warning, #e6a817);
    margin:8px 0;
    font-size:0.85rem;
    line-height:1.6;
    color:var(--text-secondary);
}
.blindspot-card .bs-suggestion { font-size:0.85rem; line-height:1.7; }

.task-group { margin-bottom:20px; }
.task-group-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:8px 0;
    border-bottom:1px solid var(--border);
    margin-bottom:8px;
}
.task-group-header a { font-weight:500; font-size:0.9rem; }
.task-group-header .task-group-progress { font-size:0.75rem; color:var(--text-muted); font-family:var(--font-ui); }
.task-item {
    display:flex;
    align-items:flex-start;
    gap:8px;
    padding:4px 0;
    font-size:0.85rem;
    line-height:1.6;
}
.task-item .task-check { flex-shrink:0; margin-top:3px; color:var(--text-muted); }
.task-item.task-done { color:var(--text-muted); text-decoration:line-through; }
.task-item .task-date { font-size:0.7rem; color:var(--text-muted); margin-left:4px; white-space:nowrap; }

/* AI 结果可视化 */
.ai-result-wrapper { animation: fadeSlideIn 0.35s ease; }
@keyframes fadeSlideIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
.ai-card {
    background:var(--bg-card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:20px;
    margin-bottom:16px;
    transition: box-shadow 0.2s;
}
.ai-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
.ai-card-badge {
    display:inline-block;
    padding:3px 12px;
    border-radius:12px;
    font-size:0.75rem;
    font-family:var(--font-ui);
    margin-bottom:10px;
    font-weight:600;
    letter-spacing:0.02em;
}
.ai-card-quote {
    margin:12px 0 0;
    padding:10px 14px;
    background:var(--bg);
    border-radius:6px;
    font-size:0.85rem;
    line-height:1.7;
    color:var(--text-secondary);
    font-style:italic;
}
.ai-numbered-item {
    padding:12px 0;
    border-bottom:1px solid var(--border-light);
    display:flex;
    align-items:flex-start;
    gap:12px;
}
.ai-numbered-circle {
    flex-shrink:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:28px;
    height:28px;
    border-radius:50%;
    background:var(--accent-light);
    color:var(--accent);
    font-size:0.8rem;
    font-family:var(--font-ui);
    font-weight:600;
}
.ai-summary-box {
    margin-top:16px;
    padding:14px 16px;
    background:var(--accent-light);
    border-radius:8px;
    font-size:0.85rem;
    line-height:1.8;
}
</style>

<script>
// Helpers
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

// Track loaded states
var insightsLoaded = {};

// ===== Tab switching =====
function switchInsightsTab(name, ev) {
    document.querySelectorAll('.admin-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    var panel = document.getElementById('insights-' + name);
    if (panel) panel.style.display = '';
    if (ev && ev.target) ev.target.classList.add('active');

    if (insightsLoaded[name]) return;
    insightsLoaded[name] = true;

    switch (name) {
        case 'sentiment': loadSentiments(); break;
        case 'stats': loadStats(); break;
        case 'tasks': loadTasks(); break;
    }
}

// ===== Auto-init first visible panel =====
document.addEventListener('DOMContentLoaded', function() {
    var firstPanel = document.querySelector('.admin-panel');
    if (!firstPanel) return;
    var appId = firstPanel.id.replace('insights-', '');
    insightsLoaded[appId] = true;
    if (appId === 'sentiment') loadSentiments();
    else if (appId === 'stats') loadStats();
    else if (appId === 'tasks') loadTasks();
});

async function loadSentiments() {
    var listEl = document.getElementById('sentiment-list');
    var summaryEl = document.getElementById('sentiment-summary');
    if (!listEl) return;
    try {
        var resp = await fetch('/api/articles', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        var data = await resp.json();
        var articles = data.articles || data;
        if (!Array.isArray(articles)) return;

        var counts = {};
        sentimentLabels.forEach(function(s) { counts[s] = 0; });
        var manual = 0, ai = 0, none = 0;
        articles.forEach(function(a) {
            var s = a.sentiment;
            if (s && s.mood) { counts[s.mood] = (counts[s.mood]||0) + 1; if (s.source === 'ai') ai++; else manual++; }
            else none++;
        });
        summaryEl.innerHTML = Object.entries(counts).map(function(e) {
            var mood = e[0], cnt = e[1];
            if (!cnt) return '';
            return '<span class="sentiment-badge sentiment-' + mood + '" onclick="filterSentiment(\'' + mood + '\')" style="cursor:pointer;" title="筛选' + mood + '的文章">' + mood + ' ' + cnt + '篇</span>';
        }).join('') + '<span style="font-size:0.75rem;color:var(--text-muted);font-family:var(--font-ui);">（AI分析 ' + ai + ' · 手动 ' + manual + ' · 未标记 ' + none + '）</span>';

        listEl.innerHTML = articles.map(renderSentimentRow).join('');
    } catch(e) {
        console.error(e);
        if (listEl) listEl.innerHTML = '<p style="color:var(--danger);">加载失败，请刷新重试</p>';
    }
}

function renderSentimentRow(a) {
    var s = a.sentiment;
    var mood = s && s.mood ? s.mood : '';
    var src = s && s.source === 'ai' ? 'AI' : (s && s.source === 'manual' ? '手动' : '');
    var badge = mood
        ? '<span class="sentiment-badge sentiment-' + mood + '" id="sb-' + a.id + '" title="点击修改" onclick="editSentiment(\'' + a.id + '\', event)">' + mood + (src ? ' ('+src+')' : '') + '</span>'
        : '<span class="sentiment-badge sentiment-empty" id="sb-' + a.id + '" title="点击设置情感" onclick="editSentiment(\'' + a.id + '\', event)">未标记</span>';
    var title = esc(a.title || '无标题');
    var date = a.created_at ? new Date(a.created_at).toLocaleDateString('zh-CN') : '';
    return '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light);gap:12px;">' +
        '<div style="min-width:0;flex:1;">' +
            '<a href="/article/' + a.id + '" style="font-size:0.9rem;">' + title + '</a>' +
            '<span style="font-size:0.75rem;color:var(--text-muted);margin-left:8px;">' + date + '</span>' +
        '</div>' +
        '<div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">' +
            badge +
            '<button class="btn btn-sm" onclick="analyzeSentiment(\'' + a.id + '\')" title="AI 分析情感">分析</button>' +
        '</div>' +
    '</div>';
}

async function analyzeSentiment(articleId) {
    var badgeEl = document.getElementById('sb-' + articleId);
    if (!badgeEl) { loadSentiments(); return; }
    badgeEl.textContent = '分析中...';
    badgeEl.className = 'sentiment-badge sentiment-empty';
    badgeEl.removeAttribute('onclick');
    try {
        var resp = await fetch('/api/ai/sentiment', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({article_id: articleId})
        });
        var r = await resp.json();
        if (r.sentiment) {
            var s = r.sentiment;
            var src = s.source === 'ai' ? ' (AI)' : '';
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
    var current = prompt('设置情感（' + sentimentLabels.join('/') + '）：');
    if (!current) return;
    if (!sentimentLabels.includes(current)) { alert('请输入有效的情感标签：' + sentimentLabels.join('/')); return; }
    try {
        var resp = await fetch('/api/articles/' + articleId, {
            method:'PUT',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({sentiment: {mood: current, source: 'manual', intensity: 5, keywords: []}})
        });
        var r = await resp.json();
        if (r.id) loadSentiments();
        else alert(r.error || '设置失败');
    } catch(e) { alert('设置失败'); }
}

function filterSentiment(mood) {
    document.querySelectorAll('#sentiment-list > div').forEach(function(row) {
        var badge = row.querySelector('.sentiment-badge');
        row.style.display = (!badge || badge.textContent.indexOf(mood) === -1) ? 'none' : '';
    });
}

// ===== 2. Related Articles =====
async function findRelated() {
    var select = document.getElementById('related-article-select');
    var id = select.value;
    if (!id) { alert('请先选择一篇文章'); return; }
    var resultEl = document.getElementById('related-result');
    resultEl.innerHTML = '<p style="color:var(--text-muted);">AI 正在查找相关文章...</p>';
    try {
        var resp = await fetch('/api/ai/related', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({article_id: id})
        });
        var r = await resp.json();
        if (r.error) { resultEl.innerHTML = '<p style="color:var(--danger);">'+esc(r.error)+'</p>'; return; }
        if (!r.articles || !r.articles.length) {
            resultEl.innerHTML = '<p style="color:var(--text-muted);">未找到明显相关的文章</p>';
            return;
        }
        resultEl.innerHTML = r.articles.map(function(a) {
            return '<div style="padding:10px 0;border-bottom:1px solid var(--border-light);">' +
                '<a href="/article/' + a.id + '" style="font-weight:500;">' + esc(a.title || '无标题') + '</a>' +
                '<span style="font-size:0.75rem;color:var(--text-muted);margin-left:8px;">' + (a.created_at ? new Date(a.created_at).toLocaleDateString('zh-CN') : '') + '</span>' +
                '<div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;">' + esc(a.reason || '') + '</div>' +
            '</div>';
        }).join('');
    } catch(e) { resultEl.innerHTML = '<p style="color:var(--danger);">请求失败</p>'; }
}

async function randomExplore() {
    var select = document.getElementById('related-article-select');
    var options = Array.from(select.options).filter(function(o) { return o.value; });
    if (!options.length) { alert('没有文章可供探索'); return; }
    var random = options[Math.floor(Math.random() * options.length)];
    select.value = random.value;
    findRelated();
}

// ===== 3. Period Summary =====
function setDatePreset(type) {
    var now = new Date();
    var from = new Date(), to = new Date();
    if (type === 'week') {
        var day = now.getDay() || 7;
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
    var from = document.getElementById('summary-from').value;
    var to = document.getElementById('summary-to').value;
    if (!from || !to) { alert('请选择日期范围'); return; }
    var resultEl = document.getElementById('summary-result');
    resultEl.innerHTML = '<p style="color:var(--text-muted);">AI 正在生成总结...</p>';
    try {
        var resp = await fetch('/api/ai/period-summary', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({from: from, to: to})
        });
        var r = await resp.json();
        if (r.error) { resultEl.innerHTML = '<p style="color:var(--danger);">'+esc(r.error)+'</p>'; return; }
        resultEl.innerHTML = '<div class="summary-card">' +
            '<h3>' + esc(r.title || '周期总结') + '</h3>' +
            '<p style="line-height:1.8;font-size:0.9rem;">' + esc(r.summary || '') + '</p>' +
            (r.events && r.events.length ? '<ul class="events">'+r.events.map(function(e){return '<li>'+esc(e)+'</li>';}).join('')+'</ul>' : '') +
            (r.mood_trend ? '<p class="mood-trend">'+esc(r.mood_trend)+'</p>' : '') +
            '<div style="margin-top:12px;display:flex;gap:8px;">' +
                '<button class="btn btn-sm btn-primary" onclick="saveSummaryAsArticle()" id="save-summary-btn">保存为文章</button>' +
                '<button class="btn btn-sm" onclick="copySummary()">复制 Markdown</button>' +
                '<span id="summary-data" style="display:none;">' + esc(JSON.stringify(r)) + '</span>' +
            '</div>' +
        '</div>';
    } catch(e) { resultEl.innerHTML = '<p style="color:var(--danger);">请求失败</p>'; }
}

function copySummary() {
    var dataEl = document.getElementById('summary-data');
    if (!dataEl) return;
    try {
        var r = JSON.parse(dataEl.textContent);
        var md = '# ' + (r.title||'周期总结') + '\n\n' + (r.summary||'') + '\n\n' +
            (r.events&&r.events.length ? r.events.map(function(e){return '- '+e;}).join('\n')+'\n\n' : '') +
            (r.mood_trend ? '> '+r.mood_trend : '');
        navigator.clipboard.writeText(md).then(function() { showToast('已复制到剪贴板', 'info'); });
    } catch(e) {}
}

async function saveSummaryAsArticle() {
    var dataEl = document.getElementById('summary-data');
    if (!dataEl) return;
    try {
        var r = JSON.parse(dataEl.textContent);
        var md = '# ' + (r.title||'周期总结') + '\n\n' + (r.summary||'') + '\n\n' +
            (r.events&&r.events.length ? r.events.map(function(e){return '- '+e;}).join('\n')+'\n\n' : '') +
            (r.mood_trend ? '> '+r.mood_trend : '');
        var resp = await fetch('/api/articles', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({title: r.title||'周期总结', content: md, summary: (r.summary||'').slice(0,200), tags:['洞见总结'], visibility:'private'})
        });
        var result = await resp.json();
        if (result.id) {
            showToast('已保存为文章', 'info');
            document.getElementById('save-summary-btn').disabled = true;
        } else alert(result.error||'保存失败');
    } catch(e) { alert('保存失败'); }
}

// ===== 4. Writing Stats =====
async function loadStats() {
    try {
        var resp = await fetch('/api/articles', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        var data = await resp.json();
        var articles = data.articles || data;
        if (!Array.isArray(articles)) return;
        computeStats(articles);
    } catch(e) { console.error(e); }
}

function computeStats(articles) {
    var grid = document.getElementById('stats-grid');
    if (!grid) return;
    if (!articles.length) {
        grid.innerHTML = '<p style="color:var(--text-muted);">暂无文章数据</p>';
        return;
    }

    var totalChars = 0;
    var tagCounts = {};
    var hourCounts = {};
    var monthlyCounts = {};
    for (var i = 0; i < 24; i++) hourCounts[i] = 0;

    articles.forEach(function(a) {
        var content = a.content || '';
        totalChars += content.replace(/[\s\r\n]+/g, '').length;
        (a.tags||[]).forEach(function(t) { tagCounts[t] = (tagCounts[t]||0)+1; });
        if (a.created_at) {
            var d = new Date(a.created_at);
            hourCounts[d.getHours()] = (hourCounts[d.getHours()]||0) + 1;
            var mk = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
            monthlyCounts[mk] = (monthlyCounts[mk]||0) + 1;
        }
    });

    var avgChars = Math.round(totalChars / articles.length);
    var topTags = Object.entries(tagCounts).sort(function(a,b){return b[1]-a[1];}).slice(0,15);
    var maxHour = Math.max.apply(null, Object.values(hourCounts)) || 1;
    var maxMonthly = Math.max.apply(null, Object.values(monthlyCounts)) || 1;
    var recentMonths = Object.entries(monthlyCounts).sort().slice(-6);
    var peakHour = Object.entries(hourCounts).sort(function(a,b){return b[1]-a[1];})[0];

    var sentimentCounts = {};
    articles.forEach(function(a) {
        var mood = a.sentiment && a.sentiment.mood ? a.sentiment.mood : '未标记';
        sentimentCounts[mood] = (sentimentCounts[mood]||0) + 1;
    });

    grid.innerHTML =
        '<div class="stat-card">' +
            '<h3>总览</h3>' +
            '<div style="display:flex;gap:24px;flex-wrap:wrap;">' +
                '<div><div class="stat-number">' + articles.length + '</div><div class="stat-label">文章总数</div></div>' +
                '<div><div class="stat-number">' + totalChars.toLocaleString() + '</div><div class="stat-label">总字数</div></div>' +
                '<div><div class="stat-number">' + avgChars.toLocaleString() + '</div><div class="stat-label">平均每篇字数</div></div>' +
            '</div>' +
        '</div>' +
        '<div class="stat-card">' +
            '<h3>发布时段分布</h3>' +
            '<div class="time-heatmap">' + Array.from({length:24},function(_,h){ return '<div class="hm-cell" style="background:var(--accent);opacity:' + (((hourCounts[h]||0)/maxHour*0.9+0.1).toFixed(2)) + '" title="' + h + ':00 — ' + (hourCounts[h]||0) + '篇"></div>'; }).join('') + '</div>' +
            '<div style="display:flex;justify-content:space-between;font-size:0.65rem;color:var(--text-muted);margin-top:4px;">' + ['0','6','12','18','23'].map(function(h){return '<span>'+h+'时</span>';}).join('') + '</div>' +
            (peakHour ? '<div class="stat-label" style="margin-top:4px;">最常在 ' + peakHour[0] + ':00 前后写作（' + peakHour[1] + '篇）</div>' : '') +
        '</div>' +
        '<div class="stat-card">' +
            '<h3>月度趋势</h3>' +
            '<div class="bar-chart">' + recentMonths.map(function(e){var m=e[0],c=e[1]; return '<div class="bar" style="height:' + (c/maxMonthly*100).toFixed(1) + '%" title="' + m + ': ' + c + '篇"></div>'; }).join('') + '</div>' +
            '<div style="display:flex;justify-content:space-between;font-size:0.65rem;color:var(--text-muted);margin-top:4px;">' + recentMonths.map(function(e){return '<span>'+e[0]+'</span>';}).join('') + '</div>' +
        '</div>' +
        '<div class="stat-card">' +
            '<h3>情感分布</h3>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + Object.entries(sentimentCounts).map(function(e) {
                var mood = e[0], cnt = e[1];
                var cls = mood !== '未标记' ? 'sentiment-' + mood : 'sentiment-empty';
                return '<span class="sentiment-badge ' + cls + '">' + mood + ': ' + cnt + '篇</span>';
            }).join('') + '</div>' +
        '</div>' +
        '<div class="stat-card" style="grid-column:1/-1;">' +
            '<h3>常用标签</h3>' +
            '<div class="tag-cloud">' + topTags.map(function(e){var t=e[0],c=e[1]; return '<span class="tc-tag" style="font-size:' + (0.75+Math.min(c/topTags[0][1],2)*0.3) + 'rem;">' + esc(t) + ' (' + c + ')</span>'; }).join('') + '</div>' +
            (topTags.length ? '' : '<span class="stat-label">暂无标签数据</span>') +
        '</div>';

    var insightEl = document.getElementById('stats-insight');
    if (!insightEl) return;
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
                top_tags: Object.entries(tagCounts).sort(function(a,b){return b[1]-a[1];}).slice(0,5).map(function(e){return e[0];}),
                sentiment_distribution: sentimentCounts,
                monthly_trend: recentMonths
            }
        })
    }).then(function(r) { return r.json(); }).then(function(r) {
        if (r.insight) {
            insightEl.innerHTML = '<div class="summary-card"><h3>AI 写作洞察</h3><p style="line-height:1.8;font-size:0.9rem;">' + esc(r.insight) + '</p></div>';
        } else {
            insightEl.innerHTML = '';
        }
    }).catch(function() { insightEl.innerHTML = ''; });
}

// ===== 5. Tasks Overview =====
async function loadTasks() {
    var overview = document.getElementById('tasks-overview');
    if (!overview) return;
    overview.innerHTML = '<p style="color:var(--text-muted);">加载中...</p>';
    try {
        var resp = await fetch('/api/articles?per_page=100', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        var data = await resp.json();
        var articles = data.articles || data;
        if (!Array.isArray(articles)) return;

        var articlesWithTasks = [];
        articles.forEach(function(a) {
            var content = a.content || '';
            var lines = content.split('\n');
            var tasks = [];
            lines.forEach(function(line, idx) {
                var m = line.match(/^\s*- \[([ x])\]\s+(.*)/);
                if (m) {
                    var done = m[1] === 'x';
                    var text = m[2].replace(/\s*\(完成于.*?\)$/, '').trim();
                    var completedDate = '';
                    var dm = m[2].match(/\(完成于\s*(.+?)\)$/);
                    if (dm) completedDate = dm[1];
                    tasks.push({lineIndex: idx, text: text, done: done, completedDate: completedDate});
                }
            });
            if (tasks.length > 0) {
                articlesWithTasks.push({id: a.id, title: a.title || '无标题', created_at: a.created_at, tasks: tasks});
            }
        });

        if (!articlesWithTasks.length) {
            overview.innerHTML = '<p style="color:var(--text-muted);">暂无待办事项。在文章中使用 <code>- [ ]</code> 格式可创建任务清单。</p>';
            return;
        }

        overview.innerHTML = articlesWithTasks.map(function(a) {
            var total = a.tasks.length;
            var done = a.tasks.filter(function(t) { return t.done; }).length;
            var date = a.created_at ? new Date(a.created_at).toLocaleDateString('zh-CN') : '';
            return '<div class="task-group">' +
                '<div class="task-group-header">' +
                    '<a href="/article/' + a.id + '">' + esc(a.title) + ' <span style="font-size:0.75rem;color:var(--text-muted);">' + date + '</span></a>' +
                    '<span class="task-group-progress">' + done + '/' + total + (done === total ? ' ✓' : '') + '</span>' +
                '</div>' +
                a.tasks.map(function(t) {
                    var cls = t.done ? 'task-done' : '';
                    var icon = t.done ? '☑' : '☐';
                    var dateHtml = t.completedDate ? '<span class="task-date">完成于 ' + esc(t.completedDate) + '</span>' : '';
                    return '<div class="task-item ' + cls + '"><span class="task-check">' + icon + '</span><span>' + esc(t.text) + dateHtml + '</span></div>';
                }).join('') +
            '</div>';
        }).join('');
    } catch(e) {
        console.error(e);
        if (overview) overview.innerHTML = '<p style="color:var(--danger);">加载失败</p>';
    }
}

// ===== 6. MBTI Analysis =====
async function analyzeMBTI() {
    var scope = document.getElementById('mbti-scope').value;
    var resultEl = document.getElementById('mbti-result');
    resultEl.innerHTML = '<div style="text-align:center;padding:40px;"><p style="color:var(--text-muted);">AI 正在深度分析你的日记内容...</p><p style="font-size:0.75rem;color:var(--text-muted);">这可能需要 10-30 秒</p></div>';
    try {
        var resp = await fetch('/api/insights/mbti', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({scope: scope})
        });
        var r = await resp.json();
        if (r.error) { resultEl.innerHTML = '<p style="color:var(--danger);">' + esc(r.error) + '</p>'; return; }
        if (!r.type) { resultEl.innerHTML = '<p style="color:var(--danger);">分析结果不完整，请稍后重试</p>'; return; }

        // Parse type into dimensions
        var type = r.type;
        var dims = {
            'E/I': type.indexOf('E') !== -1 ? 'E' : 'I',
            'S/N': type.indexOf('S') !== -1 ? 'S' : 'N',
            'T/F': type.indexOf('T') !== -1 ? 'T' : 'F',
            'J/P': type.indexOf('J') !== -1 ? 'J' : 'P'
        };

        resultEl.innerHTML =
            '<div class="summary-card">' +
                '<div style="text-align:center;">' +
                    '<div class="mbti-type-badge">' + esc(type) + '</div>' +
                    '<div style="margin-bottom:16px;font-size:0.85rem;color:var(--text-muted);">置信度：' + esc(r.confidence || '中') + '</div>' +
                '</div>' +
                '<h3>维度分析</h3>' +
                Object.entries(dims).map(function(e) {
                    var dim = e[0], letter = e[1];
                    var pct = letter === dim.split('/')[0] ? 65 : 35;
                    return '<div class="mbti-dim-row">' +
                        '<span class="dim-label">' + dim + '</span>' +
                        '<div class="dim-bar"><div class="dim-fill" style="width:' + pct + '%;"></div></div>' +
                        '<span class="dim-pct">' + letter + ' ' + pct + '%</span>' +
                    '</div>';
                }).join('') +
                '<h3 style="margin-top:20px;">详细推理</h3>' +
                '<p style="line-height:1.8;font-size:0.9rem;white-space:pre-wrap;">' + esc(r.reasoning || '') + '</p>' +
            '</div>';
    } catch(e) {
        resultEl.innerHTML = '<p style="color:var(--danger);">请求失败: ' + esc(e.message) + '</p>';
    }
}

// ===== 7. CBT Analysis =====
async function analyzeCBT() {
    var scope = document.getElementById('cbt-scope').value;
    var resultEl = document.getElementById('cbt-result');
    resultEl.innerHTML = '<div style="text-align:center;padding:40px;"><p style="color:var(--text-muted);">AI 正在分析你的思维模式...</p><p style="font-size:0.75rem;color:var(--text-muted);">这可能需要 10-30 秒</p></div>';
    try {
        var resp = await fetch('/api/insights/cbt', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({scope: scope})
        });
        var r = await resp.json();
        if (r.error) { resultEl.innerHTML = '<p style="color:var(--danger);">' + esc(r.error) + '</p>'; return; }
        if (!r.distortions || !r.distortions.length) {
            resultEl.innerHTML = '<p style="color:var(--text-muted);">未发现明显的认知扭曲，你的思维方式看起来很健康。</p>';
            return;
        }

        resultEl.innerHTML =
            r.distortions.map(function(d) {
                return '<div class="distortion-card">' +
                    '<span class="dist-type">' + esc(d.type || '认知扭曲') + '</span>' +
                    '<div class="dist-quote">"' + esc(d.quote || '') + '"</div>' +
                    '<div class="dist-intervention">' + esc(d.intervention || '') + '</div>' +
                '</div>';
            }).join('') +
            (r.summary ? '<div class="summary-card"><h3>总体建议</h3><p style="line-height:1.8;font-size:0.9rem;">' + esc(r.summary) + '</p></div>' : '');
    } catch(e) {
        resultEl.innerHTML = '<p style="color:var(--danger);">请求失败: ' + esc(e.message) + '</p>';
    }
}

// ===== 8. Blindspot Exploration =====
async function analyzeBlindspot() {
    var scope = document.getElementById('blindspot-scope').value;
    var resultEl = document.getElementById('blindspot-result');
    resultEl.innerHTML = '<div style="text-align:center;padding:40px;"><p style="color:var(--text-muted);">AI 正在探索你的隐藏模式...</p><p style="font-size:0.75rem;color:var(--text-muted);">这可能需要 10-30 秒</p></div>';
    try {
        var resp = await fetch('/api/insights/blindspot', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({scope: scope})
        });
        var r = await resp.json();
        if (r.error) { resultEl.innerHTML = '<p style="color:var(--danger);">' + esc(r.error) + '</p>'; return; }
        if (!r.blindspots || !r.blindspots.length) {
            resultEl.innerHTML = '<p style="color:var(--text-muted);">未能找到明显的盲区，你可能对自己有很清晰的认识。</p>';
            return;
        }

        resultEl.innerHTML =
            r.blindspots.map(function(bs, i) {
                return '<div class="blindspot-card">' +
                    '<h4>' + (i+1) + '. ' + esc(bs.title || '盲区') + '</h4>' +
                    '<p style="font-size:0.85rem;line-height:1.7;">' + esc(bs.insight || '') + '</p>' +
                    '<div class="bs-evidence">"这是为什么呢？让我来为你解析：它在文中体现在——' + esc(bs.evidence || '') + '"</div>' +
                    '<div class="bs-suggestion">' + esc(bs.suggestion || '') + '</div>' +
                '</div>';
            }).join('') +
            (r.summary ? '<div class="summary-card"><h3>总结</h3><p style="line-height:1.8;font-size:0.9rem;">' + esc(r.summary) + '</p></div>' : '');
    } catch(e) {
        resultEl.innerHTML = '<p style="color:var(--danger);">请求失败: ' + esc(e.message) + '</p>';
    }
}

// ===== AI App: Standard runner & result renderer =====

async function runInsightsApp(appId) {
    var scopeEl = document.getElementById('ai-scope-' + appId);
    var loadingEl = document.getElementById('ai-loading-' + appId);
    var resultEl = document.getElementById('ai-result-' + appId);
    var errorEl = document.getElementById('ai-error-' + appId);
    var scope = scopeEl ? scopeEl.value : 'all';

    if (loadingEl) loadingEl.style.display = '';
    if (resultEl) resultEl.innerHTML = '';
    if (errorEl) errorEl.style.display = 'none';

    try {
        var resp = await fetch('/api/insights/run/' + appId, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({scope: scope})
        });
        var r = await resp.json();
        if (loadingEl) loadingEl.style.display = 'none';

        if (r.error) {
            if (errorEl) { errorEl.style.display = ''; errorEl.textContent = r.error; }
            return;
        }

        var layout = r._layout || 'mixed';
        renderAIResult('ai-result-' + appId, r, layout);
    } catch(e) {
        if (loadingEl) loadingEl.style.display = 'none';
        if (errorEl) { errorEl.style.display = ''; errorEl.textContent = '请求失败: ' + e.message; }
    }
}

function renderAIResult(containerId, data, layout) {
    var el = document.getElementById(containerId);
    if (!el) return;
    if (!data || (Array.isArray(data) && !data.length)) {
        el.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:40px;">暂无结果</p>';
        return;
    }
    var html = '<div class="ai-result-wrapper">';
    if (layout === 'cards' && Array.isArray(data)) {
        html += data.map(function(item, i) { return renderResultCard(item, i); }).join('');
    } else if (layout === 'list' && Array.isArray(data)) {
        html += data.map(function(item, i) { return renderResultListItem(item, i); }).join('');
    } else {
        html += renderResultMixed(data);
    }
    html += '</div>';
    el.innerHTML = html;
}

function renderResultCard(item, idx) {
    var title = item.title || item.name || item.type || '';
    var body = item.insight || item.intervention || item.content || item.summary || item.reasoning || item.description || '';
    var quote = item.quote || item.evidence || '';
    var sub = item.suggestion || item.detail || item.note || '';
    var badge = item.type || item.label || item.confidence || item.mood || '';
    var colors = ['#5b7b6f','#6b7d8e','#8b7355','#7b6b8e','#5b8b7f','#8e7b6b'];
    var accent = colors[idx % colors.length];

    return '<div class="ai-card" style="border-left:4px solid ' + accent + ';">' +
        (badge ? '<span class="ai-card-badge" style="background:' + accent + '15;color:' + accent + ';">' + esc(badge) + '</span>' : '') +
        (title ? '<h3 style="margin:0 0 10px;font-size:1.05rem;font-weight:600;">' + esc(title) + '</h3>' : '') +
        (body ? '<p style="line-height:1.85;font-size:0.9rem;white-space:pre-wrap;margin:0;">' + esc(body) + '</p>' : '') +
        (quote ? '<div class="ai-card-quote" style="border-left:3px solid ' + accent + '80;">' + esc(quote) + '</div>' : '') +
        (sub ? '<p style="margin:10px 0 0;font-size:0.85rem;line-height:1.7;color:var(--text-muted);">' + esc(sub) + '</p>' : '') +
    '</div>';
}

function renderResultListItem(item, idx) {
    var title = item.title || item.name || '';
    var body = item.content || item.summary || item.text || item.description || '';
    return '<div class="ai-numbered-item">' +
        '<span class="ai-numbered-circle">' + (idx+1) + '</span>' +
        '<div style="min-width:0;">' +
            (title ? '<div style="font-weight:600;font-size:0.9rem;margin-bottom:2px;">' + esc(title) + '</div>' : '') +
            (body ? '<div style="font-size:0.85rem;color:var(--text-muted);line-height:1.6;">' + esc(body) + '</div>' : '') +
        '</div>' +
    '</div>';
}

function renderResultMixed(data) {
    var html = '<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;">';

    var heading = data.title || data.type || data.name || '';
    if (heading) {
        html += '<div style="text-align:center;margin-bottom:20px;">' +
            '<h3 style="font-size:1.2rem;margin:0;">' + esc(heading) + '</h3>' +
            (data.confidence ? '<span style="display:inline-block;margin-top:6px;padding:2px 10px;border-radius:10px;font-size:0.75rem;font-family:var(--font-ui);background:var(--accent-light);color:var(--accent);">置信度：' + esc(data.confidence) + '</span>' : '') +
        '</div>';
    }

    var primary = data.insight || data.summary || data.reasoning || data.content || '';
    if (primary) {
        html += '<div style="line-height:1.9;font-size:0.92rem;white-space:pre-wrap;margin-bottom:20px;">' + esc(primary) + '</div>';
    }

    var items = data.distortions || data.blindspots || data.events || data.items || data.results || data.dimensions || [];
    if (Array.isArray(items) && items.length) {
        html += '<div>' + items.map(function(item, i) { return renderResultCard(item, i); }).join('') + '</div>';
    }

    if (data.summary && items.length) {
        html += '<div class="ai-summary-box">' +
            '<span style="font-weight:600;color:var(--accent);">总结 </span>' + esc(data.summary) +
        '</div>';
    }

    html += '</div>';
    return html;
}
</script>
