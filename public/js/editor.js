/**
 * 平静之心 - 编辑器 & AI 助手
 */

let _previewSyncEnabled = true;

document.addEventListener('DOMContentLoaded', () => {
    initEditor();
    initAI();
    initUpload();
    initMobileEditor();
    initArticleRef();
});

// ===== 移动端编辑器标签切换 =====

function initMobileEditor() {
    const isMobile = window.innerWidth <= 768;

    // 元数据折叠
    const metaToggle = document.getElementById('meta-toggle');
    const metaInner = document.getElementById('meta-fields-inner');
    if (metaToggle && metaInner) {
        if (isMobile) metaInner.style.display = 'none';
        metaToggle.addEventListener('click', () => {
            const visible = metaInner.style.display !== 'none';
            metaInner.style.display = visible ? 'none' : '';
            metaToggle.querySelector('.meta-toggle-arrow').textContent = visible ? '▾' : '▴';
        });
    }

    // 标签切换（底部栏）
    const allTabs = document.querySelectorAll('.meb-tab');
    const panels = {
        editor: document.getElementById('editor-pane'),
        preview: document.getElementById('preview-pane'),
        ai: document.getElementById('ai-panel')
    };

    function switchMobileTab(tabName) {
        // 隐藏所有面板
        Object.entries(panels).forEach(([name, p]) => {
            if (!p) return;
            p.style.display = 'none';
        });
        // 显示目标面板，使用合适的 display 值
        const active = panels[tabName];
        if (active) {
            active.style.display = (tabName === 'preview') ? 'block' : 'flex';
        }

        // 同步所有标签状态
        allTabs.forEach(t => {
            t.classList.toggle('active', t.dataset.tab === tabName);
        });

        // 切换到预览时触发渲染
        if (tabName === 'preview') {
            const textarea = document.getElementById('article-content');
            const preview = document.getElementById('preview-pane');
            const previewEmpty = document.getElementById('preview-empty');
            if (textarea && preview && typeof marked !== 'undefined') {
                if (textarea.value.trim()) {
                    if (previewEmpty) previewEmpty.style.display = 'none';
                    preview.innerHTML = marked.parse(textarea.value);
                } else {
                    if (previewEmpty) previewEmpty.style.display = '';
                    preview.innerHTML = '';
                }
            }
        }
    }

    allTabs.forEach(tab => {
        tab.addEventListener('click', () => switchMobileTab(tab.dataset.tab));
    });

    // 初始状态
    if (isMobile) {
        switchMobileTab('editor');
        if (metaFields) metaFields.style.display = 'none';
    }

    // 窗口尺寸变化时重置
    window.addEventListener('resize', () => {
        const nowMobile = window.innerWidth <= 768;
        if (!nowMobile) {
            // 恢复桌面端所有面板
            Object.entries(panels).forEach(([name, p]) => {
                if (!p) return;
                p.style.display = (name === 'preview') ? 'block' : 'flex';
            });
            if (metaFields) metaFields.style.display = '';
        } else {
            // 切换回移动端时恢复当前活动标签
            const activeTab = document.querySelector('.meb-tab.active');
            if (activeTab) switchMobileTab(activeTab.dataset.tab);
            else switchMobileTab('editor');
            if (metaFields) metaFields.style.display = 'none';
        }
        initResizeHandles();
    });
}

// ===== 编辑器 =====

let editorAutoSaveTimer;
let editorDirty = false;
let lastAutoSaveContent = '';

function initEditor() {
    const textarea = document.getElementById('article-content');
    const preview = document.getElementById('preview-pane');
    const previewEmpty = document.getElementById('preview-empty');

    if (!textarea || !preview) return;

    // 离开编辑器时保存选中范围，以便 AI 操作能获取选中文字
    textarea.addEventListener('blur', () => {
        savedSelStart = textarea.selectionStart;
        savedSelEnd = textarea.selectionEnd;
    });

    // 实时预览
    function updatePreview() {
        const md = textarea.value;
        if (md.trim() && typeof marked !== 'undefined') {
            if (previewEmpty) previewEmpty.style.display = 'none';
            // 连续3个以上换行 → 每多一个换行插一个独立 <br> 段落（\n\n 隔离，避免污染相邻块语法）
            let processed = md.replace(/\r?\n/g, '\n').replace(/\n{3,}/g, m => '\n\n' + '<br>'.repeat(m.length - 2) + '\n\n');
            preview.innerHTML = marked.parse(processed);
        } else if (md.trim()) {
            if (previewEmpty) previewEmpty.style.display = 'none';
            preview.innerHTML = '<p style="color:var(--text-muted)">Markdown 渲染库加载中...</p>';
        } else {
            if (previewEmpty) previewEmpty.style.display = '';
        }
    }

    textarea.addEventListener('input', () => { editorDirty = true; updatePreview(); syncPreviewScroll(); });
    updatePreview();

    // 预览滚动同步：光标所在位置对应的预览内容保持在视线中部
    let _previewSyncPending = false;
    function syncPreviewScroll() {
        if (!_previewSyncEnabled || _previewSyncPending) return;
        _previewSyncPending = true;
        requestAnimationFrame(() => {
            _previewSyncPending = false;
            const totalLen = textarea.value.length;
            if (!totalLen) return;
            const maxScroll = preview.scrollHeight - preview.clientHeight;
            if (maxScroll <= 0) return;
            const pos = textarea.selectionStart;
            const ratio = pos / totalLen;
            const target = ratio * preview.scrollHeight - preview.clientHeight / 2;
            preview.scrollTop = Math.max(0, Math.min(target, maxScroll));
        });
    }
    textarea.addEventListener('keyup', syncPreviewScroll);
    textarea.addEventListener('click', syncPreviewScroll);
    textarea.addEventListener('scroll', () => {
        if (!_previewSyncEnabled) return;
        const maxScroll = textarea.scrollHeight - textarea.clientHeight;
        if (maxScroll <= 0) return;
        const ratio = textarea.scrollTop / maxScroll;
        const previewMax = preview.scrollHeight - preview.clientHeight;
        if (previewMax <= 0) return;
        preview.scrollTop = ratio * previewMax;
    });

    // 若 marked 尚未加载（CDN 延迟），等待后重试
    if (typeof marked === 'undefined') {
        let retries = 0;
        const waitMarked = setInterval(() => {
            retries++;
            if (typeof marked !== 'undefined') {
                clearInterval(waitMarked);
                updatePreview();
            } else if (retries > 30) {
                clearInterval(waitMarked);
            }
        }, 200);
    }

    // Tab 键支持
    textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Tab') {
            e.preventDefault();
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            if (start !== end) {
                // 多行选中：每行缩进
                const before = textarea.value.substring(0, start);
                const sel = textarea.value.substring(start, end);
                const after = textarea.value.substring(end);
                const lines = sel.split('\n');
                const indented = lines.map(l => '    ' + l).join('\n');
                doReplace(textarea, indented, start, end, start + indented.length);
            } else {
                doReplace(textarea, '    ', start, end, start + 4);
            }
        }
    });

    // 工具栏
    document.querySelectorAll('#editor-toolbar button[data-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const action = btn.dataset.action;
            toolbarAction(textarea, action);
            updatePreview();
            textarea.focus();
        });
    });

    // 裁剪按钮
    const cropBtn = document.getElementById('crop-btn');
    if (cropBtn) {
        cropBtn.addEventListener('click', openCropModal);
    }

    // ===== 草稿恢复 & 自动保存 =====
    restoreDraft();

    // 服务器端自动保存：每30秒检测一次变更
    setInterval(() => {
        const content = textarea.value;
        if (editorDirty && content !== lastAutoSaveContent) {
            saveDraft();
            lastAutoSaveContent = content;
            editorDirty = false;
        }
    }, 30000);

    // 页面离开前保存
    window.addEventListener('beforeunload', () => saveDraft());
}

async function restoreDraft() {
    try {
        const resp = await fetch('/api/drafts', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const draft = await resp.json();
        const isEdit = typeof window.isEdit !== 'undefined' && window.isEdit;
        const articleId = typeof window.articleId !== 'undefined' ? window.articleId : '';

        // 编辑模式下只恢复匹配的草稿；新建模式下恢复任何草稿
        const matches = !draft.article_id || (isEdit && draft.article_id === articleId) || (!isEdit && !draft.article_id);
        if (!matches || !draft.content) return;

        const textarea = document.getElementById('article-content');
        const titleEl = document.getElementById('article-title');

        // 如果编辑器已有内容（PHP 预填的），不覆盖
        if (textarea.value.trim() || titleEl.value.trim()) return;

        if (confirm('检测到服务器端未保存的草稿（' + formatDate(draft.updated_at) + '），是否恢复？')) {
            textarea.value = draft.content || '';
            titleEl.value = draft.title || '';
            document.getElementById('article-summary').value = draft.summary || '';
            document.getElementById('article-tags').value = (draft.tags || []).join(',');
            document.getElementById('article-visibility').value = draft.visibility || 'private';
            textarea.dispatchEvent(new Event('input'));
        } else {
            await fetch('/api/drafts', { method: 'DELETE', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        }
        lastAutoSaveContent = textarea.value;
    } catch (e) { /* 静默处理 */ }
}

async function saveDraft() {
    const isEdit = typeof window.isEdit !== 'undefined' && window.isEdit;
    const articleId = typeof window.articleId !== 'undefined' ? window.articleId : '';

    const data = {
        article_id: isEdit ? articleId : '',
        title: document.getElementById('article-title')?.value || '',
        content: document.getElementById('article-content')?.value || '',
        summary: document.getElementById('article-summary')?.value || '',
        tags: (document.getElementById('article-tags')?.value || '').split(',').map(t => t.trim()).filter(Boolean),
        visibility: document.getElementById('article-visibility')?.value || 'private',
    };

    try {
        await fetch('/api/drafts', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
    } catch (e) { /* 静默处理 */ }
}

function applyToolbar(textarea, before, after, sel, start, end) {
    // 若选中文字两端已包裹相同标记，则移除（toggle）
    if (sel && before && after && before === after) {
        const re = new RegExp('^' + escRegex(before) + '(.*)' + escRegex(after) + '$');
        const m = sel.match(re);
        if (m) {
            doReplace(textarea, m[1], start, end, start + m[1].length);
            return;
        }
    }
    // 若选中文字两端已有其他标记，叠加：新标记在外
    const replacement = before + sel + after;
    const cursorPos = (!sel && before && after) ? start + before.length : start + replacement.length;
    doReplace(textarea, replacement, start, end, cursorPos);
}

function doReplace(textarea, text, start, end, cursorPos) {
    textarea.focus();
    textarea.setSelectionRange(start, end);
    // execCommand('insertText') 是确保浏览器正确记录撤销栈的最可靠方式
    document.execCommand('insertText', false, text);
    // insertText 后光标在替换文本末尾，若需调整则手动设置
    if (cursorPos !== undefined && cursorPos !== textarea.selectionStart) {
        textarea.setSelectionRange(cursorPos, cursorPos);
    }
}

function escRegex(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

function toolbarAction(textarea, action) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const sel = textarea.value.substring(start, end);
    let before = '', after = '';

    switch (action) {
        case 'heading': before = '\n## '; break;
        case 'bold': before = '**'; after = '**'; break;
        case 'italic': before = '*'; after = '*'; break;
        case 'strikethrough': before = '~~'; after = '~~'; break;
        case 'quote': before = '\n> '; break;
        case 'ul': before = '\n- '; break;
        case 'ol': before = '\n1. '; break;
        case 'link': before = '['; after = '](url)'; break;
        case 'image':
            const imgUrl = prompt('请输入图片 URL：');
            if (!imgUrl) return;
            const imgWidth = prompt('图片宽度（可选，如 400，留空则使用原始大小）：');
            if (imgWidth && /^\d+$/.test(imgWidth.trim())) {
                doReplace(textarea, '<img src="' + imgUrl + '" alt="" width="' + imgWidth.trim() + '">', start, end, start);
            } else {
                doReplace(textarea, '![](' + imgUrl + ')', start, end, start);
            }
            return;
        case 'code': before = '\n```\n'; after = '\n```\n'; break;
        case 'table':
            const rows = parseInt(prompt('表格行数：', '3'));
            if (!rows || rows < 1) return;
            const cols = parseInt(prompt('表格列数：', '3'));
            if (!cols || cols < 1) return;
            let tableMd = '\n| ' + Array(cols).fill('  标题').join(' | ') + ' |\n';
            tableMd += '| ' + Array(cols).fill(' --- ').join(' | ') + ' |\n';
            for (let r = 1; r < rows; r++) {
                tableMd += '| ' + Array(cols).fill('  内容').join(' | ') + ' |\n';
            }
            doReplace(textarea, tableMd, start, end, start + tableMd.length);
            return;
        case 'hr': before = '\n---\n'; break;
        case 'indent':
            if (sel && sel.includes('\n')) {
                const lines = sel.split('\n');
                const indented = lines.map(l => '　　' + l).join('\n');
                doReplace(textarea, indented, start, end, start + indented.length);
                return;
            }
            before = '　　';
            break;
        case 'latex-inline': before = '$'; after = '$'; break;
        case 'latex-block': before = '$$\n'; after = '\n$$'; break;
        case 'color':
            showColorPicker(textarea, sel, start, end);
            return;
        case 'hex':
            showHexPicker(textarea, start, end);
            return;
    }

    applyToolbar(textarea, before, after, sel, start, end);
}

// ===== 文字颜色（包裹 span） =====

let _colorPicker = null;

function showColorPicker(textarea, sel, start, end) {
    if (_colorPicker) { _colorPicker.remove(); _colorPicker = null; return; }

    const rect = textarea.getBoundingClientRect();
    const presetColors = [
        '#e74c3c','#e67e22','#f1c40f','#2ecc71','#1abc9c','#3498db',
        '#9b59b6','#e91e63','#00bcd4','#8bc34a','#ff5722','#607d8b',
        '#c0392b','#d35400','#f39c12','#27ae60','#16a085','#2980b9',
        '#8e44ad','#ffffff','#cccccc','#999999','#666666','#333333','#000000',
    ];

    const popupW = 232, popupH = 210;
    let pLeft = Math.max(8, Math.min(rect.left, window.innerWidth - popupW - 8));
    let pTop = rect.bottom + 6;
    if (pTop + popupH > window.innerHeight - 8) pTop = rect.top - popupH - 6;
    if (pTop < 8) pTop = 8;

    const div = document.createElement('div');
    div.id = 'color-picker-popup';
    div.className = 'color-picker-popup';
    div.style.cssText = 'position:fixed;z-index:1100;background:var(--bg-card,#fff);border:1px solid var(--border);border-radius:8px;padding:10px;box-shadow:0 4px 16px rgba(0,0,0,0.15);display:flex;flex-wrap:wrap;gap:4px;width:' + popupW + 'px;';
    div.style.left = pLeft + 'px';
    div.style.top = pTop + 'px';

    // 自定义颜色输入
    const customRow = document.createElement('div');
    customRow.style.cssText = 'width:100%;display:flex;gap:4px;margin-bottom:4px;';
    const customInput = document.createElement('input');
    customInput.type = 'text';
    customInput.placeholder = '#hex 或 rgb()';
    customInput.style.cssText = 'flex:1;padding:3px 6px;border:1px solid var(--border);border-radius:4px;font-size:0.75rem;font-family:monospace;';
    const customBtn = document.createElement('button');
    customBtn.textContent = '应用';
    customBtn.style.cssText = 'padding:3px 8px;border:1px solid var(--border);border-radius:4px;background:var(--bg);cursor:pointer;font-size:0.75rem;';
    customBtn.onclick = () => { applyColor(textarea, customInput.value.trim(), sel, start, end, div); };
    customInput.onkeydown = (e) => { if (e.key === 'Enter') applyColor(textarea, customInput.value.trim(), sel, start, end, div); };
    customRow.appendChild(customInput);
    customRow.appendChild(customBtn);
    div.appendChild(customRow);

    presetColors.forEach(c => {
        const swatch = document.createElement('button');
        swatch.style.cssText = 'width:24px;height:24px;border-radius:4px;border:1px solid var(--border);cursor:pointer;background:' + c + ';';
        if (c === '#ffffff') swatch.style.border = '1px solid #ccc';
        swatch.onmousedown = (e) => { e.preventDefault(); applyColor(textarea, c, sel, start, end, div); };
        div.appendChild(swatch);
    });

    // 清除颜色按钮
    const clearBtn = document.createElement('button');
    clearBtn.textContent = '清除颜色';
    clearBtn.style.cssText = 'width:100%;margin-top:4px;padding:4px;border:1px solid var(--border);border-radius:4px;background:var(--bg);cursor:pointer;font-size:0.72rem;color:var(--text-muted);';
    clearBtn.onmousedown = (e) => { e.preventDefault();
        if (sel && /<span\s+style="[^"]*color:\s*[^;"]*;?\s*"[^>]*>(.*?)<\/span>/gi.test(sel)) {
            const cleaned = sel.replace(/<span\s+style="[^"]*color:\s*[^;"]*;?\s*"[^>]*>(.*?)<\/span>/gi, '$1');
            doReplace(textarea, cleaned, start, end, start + cleaned.length);
        }
        div.remove(); _colorPicker = null;
    };
    div.appendChild(clearBtn);

    document.body.appendChild(div);
    _colorPicker = div;

    const closeCP = (e) => { if (!div.contains(e.target) && e.target !== textarea) { div.remove(); _colorPicker = null; document.removeEventListener('click', closeCP); } };
    setTimeout(() => document.addEventListener('click', closeCP), 0);
}

function applyColor(textarea, color, sel, start, end, pickerDiv) {
    if (!color) return;
    color = color.replace(/\s+/g, '');
    if (!/^(#[0-9a-fA-F]{3,8}|rgb\(|rgba\(|hsl\(|hsla\()/.test(color)) {
        if (/^[0-9a-fA-F]{3,8}$/.test(color)) color = '#' + color;
        else return;
    }
    const text = sel || '文字';
    const wrapped = '<span style="color: ' + color + '">' + text + '</span>';
    doReplace(textarea, wrapped, start, end, start + wrapped.length);
    if (pickerDiv) { pickerDiv.remove(); _colorPicker = null; }
}

// ===== 十六进制颜色取色器（插入纯 hex 文本） =====

let _hexPicker = null;

function showHexPicker(textarea, start, end) {
    if (_hexPicker) { _hexPicker.remove(); _hexPicker = null; return; }

    const presetColors = [
        '#e74c3c','#e67e22','#f1c40f','#2ecc71','#1abc9c','#3498db',
        '#9b59b6','#e91e63','#00bcd4','#8bc34a','#ff5722','#607d8b',
        '#c0392b','#d35400','#f39c12','#27ae60','#16a085','#2980b9',
        '#8e44ad','#ffffff','#cccccc','#999999','#666666','#333333','#000000',
    ];

    const popupW = 240;
    let pLeft = Math.max(8, Math.min(textarea.getBoundingClientRect().left, window.innerWidth - popupW - 8));
    let pTop = textarea.getBoundingClientRect().bottom + 6;
    if (pTop + 280 > window.innerHeight - 8) pTop = textarea.getBoundingClientRect().top - 286;
    if (pTop < 8) pTop = 8;

    const div = document.createElement('div');
    div.id = 'hex-picker-popup';
    div.className = 'color-picker-popup';
    div.style.cssText = 'position:fixed;z-index:1100;background:var(--bg-card,#fff);border:1px solid var(--border);border-radius:8px;padding:10px;box-shadow:0 4px 16px rgba(0,0,0,0.15);width:' + popupW + 'px;';
    div.style.left = pLeft + 'px';
    div.style.top = pTop + 'px';

    // 原生取色器行
    const pickerRow = document.createElement('div');
    pickerRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
    const nativeInput = document.createElement('input');
    nativeInput.type = 'color';
    nativeInput.value = '#e74c3c';
    nativeInput.style.cssText = 'width:36px;height:30px;border:none;border-radius:4px;cursor:pointer;padding:0;background:transparent;';
    const hexDisplay = document.createElement('span');
    hexDisplay.style.cssText = 'font-family:monospace;font-size:0.85rem;font-weight:600;color:var(--text);';
    hexDisplay.textContent = '#E74C3C';
    nativeInput.addEventListener('input', () => { hexDisplay.textContent = nativeInput.value.toUpperCase(); });
    const insertBtn = document.createElement('button');
    insertBtn.textContent = '插入';
    insertBtn.style.cssText = 'margin-left:auto;padding:3px 12px;border:1px solid var(--accent);border-radius:4px;background:var(--accent);color:#fff;cursor:pointer;font-size:0.78rem;font-weight:500;';
    insertBtn.onmousedown = (e) => { e.preventDefault(); applyHexColor(textarea, nativeInput.value, start, end, div); };
    pickerRow.appendChild(nativeInput);
    pickerRow.appendChild(hexDisplay);
    pickerRow.appendChild(insertBtn);
    div.appendChild(pickerRow);

    // 预设色块
    const swatchesRow = document.createElement('div');
    swatchesRow.style.cssText = 'display:flex;flex-wrap:wrap;gap:5px;';
    presetColors.forEach(c => {
        const swatch = document.createElement('button');
        swatch.style.cssText = 'width:26px;height:26px;border-radius:4px;border:1px solid var(--border);cursor:pointer;background:' + c + ';';
        if (c === '#ffffff') swatch.style.border = '1px solid #ccc';
        swatch.onmousedown = (e) => { e.preventDefault(); applyHexColor(textarea, c, start, end, div); };
        swatchesRow.appendChild(swatch);
    });
    div.appendChild(swatchesRow);

    document.body.appendChild(div);
    _hexPicker = div;

    const closeCP = (e) => { if (!div.contains(e.target) && e.target !== textarea) { div.remove(); _hexPicker = null; document.removeEventListener('click', closeCP); } };
    setTimeout(() => document.addEventListener('click', closeCP), 0);
}

function applyHexColor(textarea, color, start, end, pickerDiv) {
    if (!color) return;
    color = color.replace(/\s+/g, '');
    if (!/^#[0-9a-fA-F]{3,8}$/.test(color)) {
        if (/^[0-9a-fA-F]{3,8}$/.test(color)) color = '#' + color;
        else return;
    }
    const hex = color.length === 4
        ? '#' + color[1]+color[1] + color[2]+color[2] + color[3]+color[3]
        : color.toUpperCase();
    doReplace(textarea, hex, start, end, start + hex.length);
    if (pickerDiv) { pickerDiv.remove(); _hexPicker = null; }
}

// ===== 保存文章 =====

function gatherArticleData() {
    const title = document.getElementById('article-title').value.trim();
    const content = document.getElementById('article-content').value;
    const summary = document.getElementById('article-summary').value.trim();
    const tagsStr = document.getElementById('article-tags').value;
    const visibility = document.getElementById('article-visibility').value;
    const sentimentEl = document.getElementById('article-sentiment');
    const tags = tagsStr ? tagsStr.split(',').map(t => t.trim()).filter(Boolean) : [];
    const data = { title: title || '无标题', content, summary, tags, visibility };
    if (sentimentEl && sentimentEl.value) {
        data.sentiment = { mood: sentimentEl.value, source: 'manual', intensity: 5, keywords: [] };
    }
    return data;
}

async function clearDraftAndGo(id) {
    try { await fetch('/api/drafts', { method: 'DELETE', headers: { 'X-Requested-With': 'XMLHttpRequest' } }); } catch (e) {}
    window.location.href = (window.basePath || '') + '/article/' + id;
}

// 保存：新建时创建文章，编辑时覆盖原文章。保存后跳转到阅读页。
async function saveArticle() {
    const data = gatherArticleData();
    const isEdit = typeof window.isEdit !== 'undefined' && window.isEdit;
    const articleId = typeof window.articleId !== 'undefined' ? window.articleId : '';

    const url = isEdit ? '/api/articles/' + articleId : '/api/articles';
    const method = isEdit ? 'PUT' : 'POST';

    try {
        const resp = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.id) {
            clearDraftAndGo(result.id);
        } else {
            alert(result.error || '保存失败');
        }
    } catch (err) {
        alert('保存失败: ' + err.message);
    }
}

// 另存为：始终创建新文章，原文章不变。保存后跳转到新文章的阅读页。
async function saveAsArticle() {
    const data = gatherArticleData();

    try {
        const resp = await fetch('/api/articles', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.id) {
            clearDraftAndGo(result.id);
        } else {
            alert(result.error || '创建失败');
        }
    } catch (err) {
        alert('创建失败: ' + err.message);
    }
}

// ===== 文件上传 =====

async function uploadFiles(files) {
    const textarea = document.getElementById('article-content');
    if (!files.length || !textarea) return;

    const pos = textarea.selectionStart;
    let inserted = '';

    for (const file of files) {
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
                const url = (window.basePath || '') + result.url;
                const isImage = file.type.startsWith('image/');
                if (isImage) {
                    const w = prompt('图片宽度（可选，如 400，留空则使用原始大小）：');
                    if (w && /^\d+$/.test(w.trim())) {
                        inserted += `<img src="${url}" alt="${file.name}" width="${w.trim()}">\n`;
                    } else {
                        inserted += `![${file.name}](${url})\n`;
                    }
                } else {
                    inserted += `[${file.name}](${url})\n`;
                }
            } else if (result.error) {
                alert(result.error);
            }
        } catch (err) {
            alert('上传失败: ' + err.message);
        }
    }

    if (inserted) {
        textarea.value = textarea.value.substring(0, pos) + inserted + textarea.value.substring(pos);
        textarea.dispatchEvent(new Event('input'));
    }
}

function initUpload() {
    const uploadBtn = document.getElementById('upload-btn');
    const mebUpload = document.getElementById('meb-upload');
    const fileInput = document.getElementById('file-input');
    const textarea = document.getElementById('article-content');
    const editorPane = document.getElementById('editor-pane');
    if (!fileInput) return;

    if (uploadBtn) uploadBtn.addEventListener('click', () => fileInput.click());
    if (mebUpload) mebUpload.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', async () => {
        await uploadFiles(Array.from(fileInput.files));
        fileInput.value = '';
    });

    // 拖拽上传：编辑器面板范围
    if (editorPane) {
        let dragCounter = 0;
        editorPane.addEventListener('dragenter', (e) => {
            e.preventDefault();
            dragCounter++;
            editorPane.classList.add('drag-over');
        });
        editorPane.addEventListener('dragleave', () => {
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                editorPane.classList.remove('drag-over');
            }
        });
        editorPane.addEventListener('dragover', (e) => { e.preventDefault(); });
        editorPane.addEventListener('drop', async (e) => {
            e.preventDefault();
            dragCounter = 0;
            editorPane.classList.remove('drag-over');
            // Track cursor position in textarea at drop point
            if (e.target === textarea) {
                // The drop happened directly on textarea; cursor position is set by browser
            }
            await uploadFiles(Array.from(e.dataTransfer.files));
        });
    }

    // 粘贴图片（Ctrl+V）
    textarea.addEventListener('paste', async (e) => {
        const items = e.clipboardData?.items;
        if (!items) return;
        const imageItems = [];
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                imageItems.push(item.getAsFile());
            }
        }
        if (imageItems.length) {
            e.preventDefault();
            await uploadFiles(imageItems);
        }
    });
}

// ===== AI 助手 =====

// Track per-result data so multiple results coexist and survive undo
const _aiResultData = new Map();
let _aiResultId = 0;
let lastAIAction = null;
let lastAISelection = null;
// Persist text selection across tab switches (mobile fix)
let savedSelStart = 0;
let savedSelEnd = 0;
// AI 对话历史
let _aiHistory = [];

function initAI() {
    const panel = document.getElementById('ai-panel');
    if (!panel) return;

    const collapseBtn = document.getElementById('ai-collapse-btn');
    const reopenBtn = document.getElementById('ai-reopen-btn');
    const container = document.getElementById('editor-container');

    function toggleAI(show) {
        if (show) {
            panel.classList.remove('collapsed');
            container?.classList.add('three-col');
            if (collapseBtn) collapseBtn.textContent = '折叠';
            if (reopenBtn) reopenBtn.style.display = 'none';
        } else {
            panel.classList.add('collapsed');
            container?.classList.remove('three-col');
            if (collapseBtn) collapseBtn.textContent = '展开 AI 面板';
            if (reopenBtn) reopenBtn.style.display = '';
        }
        initResizeHandles();
    }

    if (collapseBtn) {
        collapseBtn.addEventListener('click', () => {
            toggleAI(panel.classList.contains('collapsed'));
        });
    }

    if (reopenBtn) {
        reopenBtn.addEventListener('click', () => toggleAI(true));
    }

    toggleAI(true);
}

// ===== 可拖拽列宽调节 =====

let resizeState = null;

function initResizeHandles() {
    const container = document.getElementById('editor-container');
    const handle1 = document.getElementById('resize-handle-1');
    const handle2 = document.getElementById('resize-handle-2');
    const editorPane = document.getElementById('editor-pane');
    const previewPane = document.getElementById('preview-pane');
    const aiPanel = document.getElementById('ai-panel');

    if (!container || !handle1 || !handle2 || !editorPane || !previewPane || !aiPanel) return;
    if (!container.classList.contains('three-col')) return;
    if (window.innerWidth <= 1024) return;

    // Load saved ratios
    const saved = JSON.parse(localStorage.getItem('editor_col_ratios') || 'null') || [4, 4, 2];
    applyRatios(editorPane, previewPane, aiPanel, saved);

    [handle1, handle2].forEach(handle => {
        handle.style.display = '';
        handle.onmousedown = null;
        handle.addEventListener('mousedown', (e) => {
            e.preventDefault();
            const isHandle1 = handle === handle1;
            const leftEl = isHandle1 ? editorPane : previewPane;
            const rightEl = isHandle1 ? previewPane : aiPanel;

            const containerRect = container.getBoundingClientRect();
            const leftFlex = parseFloat(leftEl.style.flex) || (isHandle1 ? 4 : 4);
            const rightFlex = parseFloat(rightEl.style.flex) || (isHandle1 ? 4 : 2);
            const totalFlex = leftFlex + rightFlex;
            const totalWidth = containerRect.width - 12; // subtract handle widths

            handle.classList.add('active');
            resizeState = { handle, leftEl, rightEl, totalFlex, totalWidth, startX: e.clientX, startLeftFlex: leftFlex, startRightFlex: rightFlex };
        });
    });

    document.addEventListener('mousemove', (e) => {
        if (!resizeState) return;
        const { leftEl, rightEl, totalFlex, totalWidth, startX, startLeftFlex, startRightFlex } = resizeState;
        const dx = e.clientX - startX;
        const flexPerPx = totalFlex / totalWidth;
        let newLeftFlex = startLeftFlex + dx * flexPerPx;
        let newRightFlex = startRightFlex - dx * flexPerPx;

        // Enforce minimum widths (200px each ≈ ~2 flex units)
        const minFlex = 1.5;
        if (newLeftFlex < minFlex) { newLeftFlex = minFlex; newRightFlex = totalFlex - minFlex; }
        if (newRightFlex < minFlex) { newRightFlex = minFlex; newLeftFlex = totalFlex - minFlex; }

        // Round to 1 decimal for stability
        newLeftFlex = Math.round(newLeftFlex * 10) / 10;
        newRightFlex = Math.round(newRightFlex * 10) / 10;

        leftEl.style.flex = newLeftFlex;
        rightEl.style.flex = newRightFlex;
    });

    document.addEventListener('mouseup', () => {
        if (!resizeState) return;
        resizeState.handle.classList.remove('active');
        const editorPane = document.getElementById('editor-pane');
        const previewPane = document.getElementById('preview-pane');
        const aiPanel = document.getElementById('ai-panel');
        const ratios = [
            Math.round((parseFloat(editorPane.style.flex) || 4) * 10) / 10,
            Math.round((parseFloat(previewPane.style.flex) || 4) * 10) / 10,
            Math.round((parseFloat(aiPanel.style.flex) || 2) * 10) / 10,
        ];
        localStorage.setItem('editor_col_ratios', JSON.stringify(ratios));
        resizeState = null;
    });
}

function applyRatios(editorPane, previewPane, aiPanel, ratios) {
    editorPane.style.flex = ratios[0];
    previewPane.style.flex = ratios[1];
    aiPanel.style.flex = ratios[2];
}

function updateAIReference() {
    const textarea = document.getElementById('article-content');
    savedSelStart = textarea.selectionStart;
    savedSelEnd = textarea.selectionEnd;
    const sel = textarea.value.substring(savedSelStart, savedSelEnd);
    const refEl = document.getElementById('ai-reference');
    if (!refEl) return;
    if (sel.trim()) {
        refEl.innerHTML = '<span class="ref-label">已选中 ' + sel.length + ' 个字：</span><span class="ref-text">' + esc(sel.substring(0, 80)) + (sel.length > 80 ? '...' : '') + '</span>';
        refEl.style.display = 'block';
    } else {
        refEl.innerHTML = '<span class="ref-label">当前操作：全文（' + textarea.value.length + ' 字）</span>';
        refEl.style.display = 'block';
    }
}

async function aiAction(action) {
    const textarea = document.getElementById('article-content');
    let selStart = textarea.selectionStart;
    let selEnd = textarea.selectionEnd;
    // 如果当前无选中（可能因切换标签而丢失），回退到保存的选区
    if (selStart === selEnd && savedSelStart !== savedSelEnd) {
        selStart = savedSelStart;
        selEnd = savedSelEnd;
    }
    const sel = textarea.value.substring(selStart, selEnd);
    const text = sel || textarea.value;
    if (!text.trim()) { alert('请先选中文字或书写内容'); return; }

    updateAIReference();

    let body = { text };
    let actionLabel = action;

    if (action === 'style') {
        const style = prompt('请选择写作风格：\n\n可选：文学优美、简洁精炼、学术严谨、随笔随性、口语化', '文学优美');
        if (!style) return;
        body.style = style.trim();
        actionLabel = '风格切换（' + style.trim() + '）';
    } else if (action === 'summary') {
        body = { text: textarea.value };
        actionLabel = '生成摘要';
    } else if (action === 'polish') {
        actionLabel = '润色';
    } else if (action === 'translate') {
        actionLabel = '翻译为英语';
    } else if (action === 'explain') {
        if (!sel) { alert('请先选中需要解释的专有名词或术语'); return; }
        actionLabel = '名词解释';
    } else if (action === 'format') {
        actionLabel = 'MD 格式化';
    }

    addAIMessage('user', '[' + actionLabel + '] ' + (sel ? '处理选中文字...' : '处理全文...'));
    addAIMessage('system', '处理中...');

    lastAIAction = action;
    lastAISelection = sel ? { start: selStart, end: selEnd } : null;

    try {
        const resp = await fetch('/api/ai/' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        });
        const result = await resp.json();

        // Remove "处理中..." message
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '处理中...') m.remove(); });

        if (result.text || result.result) {
            const output = result.text || result.result;

            // Show result with action buttons
            const container = document.getElementById('ai-messages');
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-msg-wrapper';
            const rid = ++_aiResultId;
            wrapper.dataset.resultId = rid;
            _aiResultData.set(rid, { text: output, sel: lastAISelection });
            const selLabel = lastAISelection ? '选中文字' : '全文';
            wrapper.innerHTML = `
                <div class="ai-msg assistant">${esc(output)}</div>
                <div class="ai-msg-actions">
                    ${(action === 'polish' || action === 'style' || action === 'translate' || action === 'format') ? `
                        <button class="btn btn-sm btn-primary" onclick="replaceAIResult(this)">替换${selLabel}</button>
                        <button class="btn btn-sm" onclick="insertAIResult(this)">追加到文末</button>
                    ` : ''}
                    ${action === 'summary' ? `
                        <button class="btn btn-sm btn-primary" onclick="applySummary(this)">设为摘要</button>
                    ` : ''}
                </div>
            `;
            container.appendChild(wrapper);
            container.scrollTop = container.scrollHeight;
        } else if (result.error) {
            addAIMessage('system', '错误: ' + result.error);
        }
    } catch (err) {
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '处理中...') m.remove(); });
        addAIMessage('system', '请求失败: ' + err.message);
    }
}

function getResultData(wrapper) {
    const id = wrapper && wrapper.dataset && wrapper.dataset.resultId;
    return id ? _aiResultData.get(parseInt(id)) : null;
}

function replaceAIResult(btn) {
    const wrapper = btn.closest('.ai-msg-wrapper');
    const data = getResultData(wrapper);
    if (!data) return;
    const textarea = document.getElementById('article-content');
    textarea.focus();
    if (data.sel) {
        textarea.setSelectionRange(data.sel.start, data.sel.end);
    } else {
        textarea.select();
    }
    document.execCommand('insertText', false, data.text);
    textarea.dispatchEvent(new Event('input'));
}

function insertAIResult(btn) {
    const wrapper = btn.closest('.ai-msg-wrapper');
    const data = getResultData(wrapper);
    if (!data) return;
    const textarea = document.getElementById('article-content');
    textarea.focus();
    const end = textarea.value.length;
    textarea.setSelectionRange(end, end);
    document.execCommand('insertText', false, '\n\n' + data.text);
    textarea.dispatchEvent(new Event('input'));
}

function applySummary(btn) {
    const wrapper = btn.closest('.ai-msg-wrapper');
    const data = getResultData(wrapper);
    if (!data) return;
    document.getElementById('article-summary').value = data.text;
}

async function aiChat() {
    const input = document.getElementById('ai-input');
    const question = input.value.trim();
    if (!question) return;

    addAIMessage('user', question);
    _aiHistory.push({ role: 'user', content: question });
    input.value = '';
    addAIMessage('system', '思考中...');

    const textarea = document.getElementById('article-content');
    const articleContent = textarea ? textarea.value : '';

    try {
        const resp = await fetch('/api/ai/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ question, article_content: articleContent, history: _aiHistory.slice(0, -1) })
        });
        const result = await resp.json();
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '思考中...') m.remove(); });

        if (result.text || result.answer) {
            const reply = result.text || result.answer;
            addAIMessage('assistant', reply);
            _aiHistory.push({ role: 'assistant', content: reply });
        } else if (result.error) {
            addAIMessage('system', '错误: ' + result.error);
        }
    } catch (err) {
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '思考中...') m.remove(); });
        addAIMessage('system', '请求失败: ' + err.message);
    }
}

function addAIMessage(role, content) {
    const container = document.getElementById('ai-messages');
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'ai-msg ' + role;
    div.textContent = content;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

// ===== 图片裁剪 =====

let cropState = {
    img: null,
    rect: { x: 0, y: 0, w: 0, h: 0 },
    dragging: false,
    resizing: false,
    corner: '',
    startX: 0,
    startY: 0,
    startRect: null,
    scale: 1,
};

function openCropModal() {
    document.getElementById('crop-modal').style.display = 'flex';
    const canvas = document.getElementById('crop-canvas');
    canvas.width = 0;
    canvas.height = 0;
    cropState.img = null;
    document.getElementById('crop-file-input').value = '';
}

function closeCropModal() {
    document.getElementById('crop-modal').style.display = 'none';
}

function loadCropImage(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            cropState.img = img;

            // Fit canvas to modal
            const maxW = Math.min(window.innerWidth * 0.85, 800);
            const maxH = Math.min(window.innerHeight * 0.5, 500);
            let w = img.width, h = img.height;
            if (w > maxW) { h = h * maxW / w; w = maxW; }
            if (h > maxH) { w = w * maxH / h; h = maxH; }
            cropState.scale = w / img.width;

            const canvas = document.getElementById('crop-canvas');
            canvas.width = w;
            canvas.height = h;

            // Initial crop rect: 80% of image, centered
            const margin = 0.1;
            cropState.rect = {
                x: w * margin,
                y: h * margin,
                w: w * (1 - 2 * margin),
                h: h * (1 - 2 * margin),
            };

            drawCropCanvas();
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function drawCropCanvas() {
    const canvas = document.getElementById('crop-canvas');
    const ctx = canvas.getContext('2d');
    const { img, rect, scale } = cropState;
    if (!img) return;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Draw image
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

    // Darken outside crop area
    ctx.fillStyle = 'rgba(0,0,0,0.5)';
    ctx.fillRect(0, 0, canvas.width, rect.y);
    ctx.fillRect(0, rect.y, rect.x, rect.h);
    ctx.fillRect(rect.x + rect.w, rect.y, canvas.width - rect.x - rect.w, rect.h);
    ctx.fillRect(0, rect.y + rect.h, canvas.width, canvas.height - rect.y - rect.h);

    // Draw crop border
    ctx.strokeStyle = '#5b7b6f';
    ctx.lineWidth = 2;
    ctx.strokeRect(rect.x, rect.y, rect.w, rect.h);

    // Draw corner handles
    const corners = [
        { x: rect.x, y: rect.y },
        { x: rect.x + rect.w, y: rect.y },
        { x: rect.x, y: rect.y + rect.h },
        { x: rect.x + rect.w, y: rect.y + rect.h },
    ];
    corners.forEach(c => {
        ctx.fillStyle = '#fff';
        ctx.strokeStyle = '#5b7b6f';
        ctx.lineWidth = 1;
        ctx.fillRect(c.x - 5, c.y - 5, 10, 10);
        ctx.strokeRect(c.x - 5, c.y - 5, 10, 10);
    });

    // Rule of thirds lines
    ctx.strokeStyle = 'rgba(255,255,255,0.3)';
    ctx.lineWidth = 0.5;
    ctx.setLineDash([4, 4]);
    ctx.beginPath();
    ctx.moveTo(rect.x + rect.w / 3, rect.y); ctx.lineTo(rect.x + rect.w / 3, rect.y + rect.h);
    ctx.moveTo(rect.x + 2 * rect.w / 3, rect.y); ctx.lineTo(rect.x + 2 * rect.w / 3, rect.y + rect.h);
    ctx.moveTo(rect.x, rect.y + rect.h / 3); ctx.lineTo(rect.x + rect.w, rect.y + rect.h / 3);
    ctx.moveTo(rect.x, rect.y + 2 * rect.h / 3); ctx.lineTo(rect.x + rect.w, rect.y + 2 * rect.h / 3);
    ctx.stroke();
    ctx.setLineDash([]);
}

// Canvas pointer handling (mouse + touch)
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('crop-canvas');
    if (!canvas) return;
    canvas.style.touchAction = 'none';

    function canvasPos(e) {
        const rect = canvas.getBoundingClientRect();
        return { mx: e.clientX - rect.left, my: e.clientY - rect.top };
    }

    canvas.addEventListener('pointerdown', function(e) {
        if (!cropState.img) return;
        canvas.setPointerCapture(e.pointerId);
        const { mx, my } = canvasPos(e);
        const r = cropState.rect;
        const threshold = 12;

        const corners = { nw: [r.x, r.y], ne: [r.x + r.w, r.y], sw: [r.x, r.y + r.h], se: [r.x + r.w, r.y + r.h] };
        let hitCorner = '';
        for (const [name, [cx, cy]] of Object.entries(corners)) {
            if (Math.abs(mx - cx) < threshold && Math.abs(my - cy) < threshold) {
                hitCorner = name;
                break;
            }
        }

        if (hitCorner) {
            cropState.resizing = true;
            cropState.corner = hitCorner;
            cropState.startX = mx;
            cropState.startY = my;
            cropState.startRect = { ...r };
        } else if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.h) {
            cropState.dragging = true;
            cropState.startX = mx;
            cropState.startY = my;
            cropState.startRect = { ...r };
        }
    });

    canvas.addEventListener('pointermove', function(e) {
        const { mx, my } = canvasPos(e);
        const r = cropState.rect;
        const threshold = 12;

        if (cropState.dragging) {
            const dx = mx - cropState.startX;
            const dy = my - cropState.startY;
            const sr = cropState.startRect;
            let nx = sr.x + dx, ny = sr.y + dy;
            nx = Math.max(0, Math.min(nx, canvas.width - r.w));
            ny = Math.max(0, Math.min(ny, canvas.height - r.h));
            cropState.rect.x = nx;
            cropState.rect.y = ny;
            drawCropCanvas();
        } else if (cropState.resizing) {
            const dx = mx - cropState.startX;
            const dy = my - cropState.startY;
            const sr = cropState.startRect;
            const minSize = 20;
            const c = cropState.corner;

            let nx = sr.x, ny = sr.y, nw = sr.w, nh = sr.h;
            if (c.includes('e')) nw = Math.max(minSize, sr.w + dx);
            if (c.includes('w')) { nw = Math.max(minSize, sr.w - dx); nx = sr.x + dx; }
            if (c.includes('s')) nh = Math.max(minSize, sr.h + dy);
            if (c.includes('n')) { nh = Math.max(minSize, sr.h - dy); ny = sr.y + dy; }

            if (nx < 0) { nw += nx; nx = 0; }
            if (ny < 0) { nh += ny; ny = 0; }
            if (nx + nw > canvas.width) nw = canvas.width - nx;
            if (ny + nh > canvas.height) nh = canvas.height - ny;

            cropState.rect = { x: nx, y: ny, w: nw, h: nh };
            drawCropCanvas();
        } else {
            const corners = [[r.x, r.y], [r.x + r.w, r.y], [r.x, r.y + r.h], [r.x + r.w, r.y + r.h]];
            let nearCorner = false;
            for (const [cx, cy] of corners) {
                if (Math.abs(mx - cx) < threshold && Math.abs(my - cy) < threshold) {
                    nearCorner = true;
                    break;
                }
            }
            if (nearCorner) {
                canvas.style.cursor = 'nesw-resize';
            } else if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.h) {
                canvas.style.cursor = 'move';
            } else {
                canvas.style.cursor = 'crosshair';
            }
        }
    });

    const stopDrag = () => {
        cropState.dragging = false;
        cropState.resizing = false;
        cropState.corner = '';
    };
    canvas.addEventListener('pointerup', stopDrag);
    canvas.addEventListener('pointerleave', stopDrag);
    canvas.addEventListener('pointercancel', stopDrag);
});

async function doCrop() {
    const canvas = document.getElementById('crop-canvas');
    const { img, rect, scale } = cropState;
    if (!img) return;

    // Extract cropped region from original image
    const cropCanvas = document.createElement('canvas');
    const sx = rect.x / scale;
    const sy = rect.y / scale;
    const sw = rect.w / scale;
    const sh = rect.h / scale;
    cropCanvas.width = sw;
    cropCanvas.height = sh;
    const ctx = cropCanvas.getContext('2d');
    ctx.drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);

    // Convert to blob and upload
    const blob = await new Promise(resolve => cropCanvas.toBlob(resolve, 'image/png'));

    const formData = new FormData();
    formData.append('file', blob, 'cropped.png');

    try {
        const resp = await fetch('/api/upload', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await resp.json();
        if (result.url) {
            const url = (window.basePath || '') + result.url;
            const w = prompt('图片宽度（可选，如 400，留空则使用原始大小）：');
            const textarea = document.getElementById('article-content');
            if (w && /^\d+$/.test(w.trim())) {
                insertAtCursor(textarea, '<img src="' + url + '" alt="" width="' + w.trim() + '">');
            } else {
                insertAtCursor(textarea, '![](' + url + ')');
            }
            closeCropModal();
        } else if (result.error) {
            alert(result.error);
        }
    } catch (err) {
        alert('裁剪失败: ' + err.message);
    }
}

// ===== 续写 =====
function aiContinue() {
    const textarea = document.getElementById('article-content');
    if (!textarea) return;
    let selStart = textarea.selectionStart;
    let selEnd = textarea.selectionEnd;
    if (selStart === selEnd && savedSelStart !== savedSelEnd) {
        selStart = savedSelStart;
        selEnd = savedSelEnd;
    }
    const sel = textarea.value.substring(selStart, selEnd);
    const text = sel || textarea.value;
    if (!text.trim()) { alert('请先书写内容'); return; }

    updateAIReference();
    // Store context for when user picks a direction
    window._continueContext = text;
    window._continueCursorPos = sel ? null : textarea.selectionStart;
    window._continueHasSelection = !!sel;
    window._continueSelStart = selStart;
    window._continueSelEnd = selEnd;

    const container = document.getElementById('ai-messages');
    const wrapper = document.createElement('div');
    wrapper.className = 'ai-msg-wrapper';
    wrapper.id = 'continue-prompt';
    wrapper.innerHTML = `<div class="ai-msg assistant">
        <div style="font-weight:500;margin-bottom:6px;">续写方向：</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button class="btn btn-sm btn-primary" onclick="doContinue('继续写下去')">继续写下去</button>
            <button class="btn btn-sm" onclick="doContinue('换个角度写')">换个角度写</button>
            <button class="btn btn-sm" onclick="doContinue('总结收尾')">总结收尾</button>
        </div>
    </div>`;
    container.appendChild(wrapper);
    container.scrollTop = container.scrollHeight;
}

async function doContinue(direction) {
    // Remove the prompt buttons
    const promptEl = document.getElementById('continue-prompt');
    if (promptEl) promptEl.remove();

    const text = window._continueContext;
    if (!text) return;

    addAIMessage('user', '[续写] ' + direction);
    addAIMessage('system', '续写中...');

    try {
        const resp = await fetch('/api/ai/continue', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({context: text, direction})
        });
        const result = await resp.json();

        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '续写中...') m.remove(); });

        if (result.text) {
            const sel = window._continueHasSelection ? {start: window._continueSelStart, end: window._continueSelEnd} : null;

            const container = document.getElementById('ai-messages');
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-msg-wrapper';
            const rid = ++_aiResultId;
            wrapper.dataset.resultId = rid;
            _aiResultData.set(rid, { text: result.text, sel: sel });
            const selLabel = sel ? '选中文字' : '全文';
            wrapper.innerHTML = `
                <div class="ai-msg assistant">${esc(result.text)}</div>
                <div class="ai-msg-actions">
                    <button class="btn btn-sm btn-primary" onclick="replaceAIResult(this)">替换${selLabel}</button>
                    <button class="btn btn-sm" onclick="insertAIResult(this)">追加到文末</button>
                    ${!window._continueHasSelection ? `<button class="btn btn-sm" onclick="insertContinueResult(this)">插入到光标处</button>` : ''}
                </div>
            `;
            container.appendChild(wrapper);
            container.scrollTop = container.scrollHeight;
        } else if (result.error) {
            addAIMessage('system', '错误: ' + result.error);
        }
    } catch (err) {
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '续写中...') m.remove(); });
        addAIMessage('system', '请求失败: ' + err.message);
    }

    window._continueContext = null;
    window._continueCursorPos = null;
    window._continueHasSelection = null;
}

function insertContinueResult(btn) {
    const wrapper = btn.closest('.ai-msg-wrapper');
    const data = getResultData(wrapper);
    if (!data) return;
    const textarea = document.getElementById('article-content');
    textarea.focus();
    const pos = window._continueCursorPos || textarea.selectionStart;
    textarea.setSelectionRange(pos, pos);
    document.execCommand('insertText', false, '\n\n' + data.text);
    textarea.dispatchEvent(new Event('input'));
}

// ===== 金句提取 =====
async function aiHighlights() {
    const textarea = document.getElementById('article-content');
    const text = textarea ? textarea.value : '';
    if (!text.trim()) { alert('请先书写文章内容'); return; }

    addAIMessage('user', '[金句提取] 分析全文...');
    addAIMessage('system', '分析中...');

    try {
        const resp = await fetch('/api/ai/highlights', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({text})
        });
        const result = await resp.json();

        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '分析中...') m.remove(); });

        if (result.highlights && result.highlights.length) {
            const container = document.getElementById('ai-messages');
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-msg-wrapper';
            wrapper.innerHTML = `<div class="ai-msg assistant" style="line-height:1.8;">
                <strong>金句提取：</strong>
                ${result.highlights.map((h, i) =>
                    `<div style="margin:2px 0 2px 8px;border-left:2px solid var(--accent);padding-left:8px;font-size:0.8rem;">
                        <div style="font-style:italic;">${esc(h.sentence)}</div>
                        <div style="font-size:0.72rem;color:var(--text-muted);">${esc(h.reason || '')}</div>
                    </div>`
                ).join('')}
            </div>`;
            container.appendChild(wrapper);
            container.scrollTop = container.scrollHeight;
        } else if (result.error) {
            addAIMessage('system', '错误: ' + result.error);
        } else {
            addAIMessage('system', '未发现特别出彩的句子');
        }
    } catch (err) {
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '分析中...') m.remove(); });
        addAIMessage('system', '请求失败: ' + err.message);
    }
}

// ===== 标签推荐 =====
async function aiSuggestTags() {
    const title = document.getElementById('article-title').value.trim();
    const content = document.getElementById('article-content').value;
    if (!title && !content.trim()) { alert('请先写一些内容'); return; }

    addAIMessage('user', '[标签推荐] 分析文章中...');
    addAIMessage('system', '分析中...');

    try {
        const resp = await fetch('/api/ai/suggest-tags', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({title, content})
        });
        const result = await resp.json();

        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '分析中...') m.remove(); });

        if (result.tags && result.tags.length) {
            const tagsInput = document.getElementById('article-tags');
            const currentTags = tagsInput.value.split(',').map(t => t.trim()).filter(Boolean);

            const container = document.getElementById('ai-messages');
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-msg-wrapper';
            wrapper.innerHTML = `<div class="ai-msg assistant" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <span style="font-size:0.8rem;color:var(--text-muted);">推荐标签：</span>
                ${result.tags.map(t => {
                    const added = currentTags.includes(t);
                    return `<span class="ai-tag-chip${added ? ' added' : ''}" data-tag="${esc(t)}" onclick="addSuggestedTag(this)" style="
                        display:inline-block;padding:3px 12px;border-radius:14px;font-size:0.8rem;
                        font-family:var(--font-ui);cursor:pointer;transition:all 0.2s;
                        ${added
                            ? 'background:var(--accent);color:#fff;'
                            : 'background:var(--accent-light);color:var(--accent);border:1px solid transparent;'}
                    ">${esc(t)}${added ? ' ✓' : ' +'}</span>`;
                }).join('')}
            </div>`;
            container.appendChild(wrapper);
            container.scrollTop = container.scrollHeight;
        } else if (result.error) {
            addAIMessage('system', '错误: ' + result.error);
        } else {
            addAIMessage('system', '未能推荐标签');
        }
    } catch (err) {
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '分析中...') m.remove(); });
        addAIMessage('system', '请求失败: ' + err.message);
    }
}

// ===== 标题建议 =====
async function aiSuggestTitle() {
    const content = document.getElementById('article-content').value;
    if (!content.trim()) { alert('请先书写文章内容'); return; }

    updateAIReference();
    addAIMessage('user', '[标题建议] 分析文章中...');
    addAIMessage('system', '思考中...');

    try {
        const resp = await fetch('/api/ai/suggest-title', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({content})
        });
        const result = await resp.json();

        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '思考中...') m.remove(); });

        if (result.titles && result.titles.length) {
            const titleInput = document.getElementById('article-title');
            const container = document.getElementById('ai-messages');
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-msg-wrapper';
            wrapper.innerHTML = `<div class="ai-msg assistant" style="display:flex;flex-direction:column;gap:6px;">
                <span style="font-size:0.8rem;color:var(--text-muted);">推荐标题：</span>
                ${result.titles.map(t => `<button class="btn btn-sm" style="text-align:left;justify-content:flex-start;" onclick="applySuggestedTitle(this)">${esc(t)}</button>`).join('')}
            </div>`;
            container.appendChild(wrapper);
            container.scrollTop = container.scrollHeight;
        } else if (result.error) {
            addAIMessage('system', '错误: ' + result.error);
        } else {
            addAIMessage('system', '未能生成标题');
        }
    } catch (err) {
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '思考中...') m.remove(); });
        addAIMessage('system', '请求失败: ' + err.message);
    }
}

function applySuggestedTitle(btn) {
    const titleInput = document.getElementById('article-title');
    titleInput.value = btn.textContent;
    titleInput.focus();
}

function addSuggestedTag(chip) {
    const tag = chip.dataset.tag;
    const tagsInput = document.getElementById('article-tags');
    const currentTags = tagsInput.value.split(',').map(t => t.trim()).filter(Boolean);

    if (currentTags.includes(tag)) {
        // Remove tag
        const idx = currentTags.indexOf(tag);
        currentTags.splice(idx, 1);
        tagsInput.value = currentTags.join(', ');
        chip.classList.remove('added');
        chip.style.background = 'var(--accent-light)';
        chip.style.color = 'var(--accent)';
        chip.innerHTML = esc(tag) + ' +';
    } else {
        // Add tag
        currentTags.push(tag);
        tagsInput.value = currentTags.join(', ');
        chip.classList.add('added');
        chip.style.background = 'var(--accent)';
        chip.style.color = '#fff';
        chip.innerHTML = esc(tag) + ' ✓';
    }
}

// ===== 自定义模板 =====
let _allTemplates = [];
let _selectedTemplateId = '';

document.addEventListener('DOMContentLoaded', loadAITemplates);
async function loadAITemplates() {
    try {
        const resp = await fetch('/api/ai/templates', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const templates = await resp.json();
        const container = document.getElementById('ai-templates');
        if (!container) return;
        if (!templates.length) return;
        container.style.display = 'flex';
        _allTemplates = templates;

        const input = document.getElementById('ai-template-input');
        const dropdown = document.getElementById('ai-template-dropdown');
        if (input) {
            input.value = '';
            input.placeholder = '搜索模板...';
            input.addEventListener('input', filterTemplateDropdown);
            input.addEventListener('focus', () => { if (_allTemplates.length) filterTemplateDropdown(); });
            input.addEventListener('blur', () => { setTimeout(() => { if (dropdown) dropdown.style.display = 'none'; }, 150); });
        }
    } catch(e) {}
}

function filterTemplateDropdown() {
    const input = document.getElementById('ai-template-input');
    const dropdown = document.getElementById('ai-template-dropdown');
    if (!input || !dropdown) return;
    const q = input.value.trim().toLowerCase();

    const filtered = q ? _allTemplates.filter(t => t.name.toLowerCase().includes(q)) : _allTemplates;
    if (!filtered.length) {
        dropdown.innerHTML = '<div style="padding:6px 8px;font-size:0.75rem;color:var(--text-muted);">无匹配模板</div>';
    } else {
        dropdown.innerHTML = filtered.map(t =>
            `<div class="tpl-dropdown-item" data-id="${t.id}" style="padding:6px 8px;cursor:pointer;font-size:0.78rem;border-bottom:1px solid var(--border-light);" onmousedown="event.preventDefault();selectTemplateItem(this)">${esc(t.name)}</div>`
        ).join('');
    }
    dropdown.style.display = '';
}

function selectTemplateItem(el) {
    const id = el.dataset.id;
    const tpl = _allTemplates.find(t => t.id === id);
    if (!tpl) return;
    _selectedTemplateId = id;
    const input = document.getElementById('ai-template-input');
    const dropdown = document.getElementById('ai-template-dropdown');
    if (input) input.value = tpl.name;
    if (dropdown) dropdown.style.display = 'none';
}

async function aiUseTemplate() {
    const tplId = _selectedTemplateId;
    if (!tplId) { alert('请先搜索并选择一个模板'); return; }

    const textarea = document.getElementById('article-content');
    let selStart = textarea.selectionStart;
    let selEnd = textarea.selectionEnd;
    if (selStart === selEnd && savedSelStart !== savedSelEnd) {
        selStart = savedSelStart;
        selEnd = savedSelEnd;
    }
    const sel = textarea.value.substring(selStart, selEnd);
    const text = sel || textarea.value;
    if (!text.trim()) { alert('请先选中文字或书写内容'); return; }

    updateAIReference();
    const tplName = document.getElementById('ai-template-input').value || '自定义模板';
    addAIMessage('user', '[模板: ' + tplName + '] ' + (sel ? '处理选中文字...' : '处理全文...'));
    addAIMessage('system', '处理中...');

    try {
        const resp = await fetch('/api/ai/template/' + tplId, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({text})
        });
        const result = await resp.json();

        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '处理中...') m.remove(); });

        if (result.text) {
            const selData = sel ? {start: selStart, end: selEnd} : null;
            const container = document.getElementById('ai-messages');
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-msg-wrapper';
            const rid = ++_aiResultId;
            wrapper.dataset.resultId = rid;
            _aiResultData.set(rid, { text: result.text, sel: selData });
            const selLabel = selData ? '选中文字' : '全文';
            wrapper.innerHTML = `
                <div class="ai-msg assistant">${esc(result.text)}</div>
                <div class="ai-msg-actions">
                    <button class="btn btn-sm btn-primary" onclick="replaceAIResult(this)">替换${selLabel}</button>
                    <button class="btn btn-sm" onclick="insertAIResult(this)">追加到文末</button>
                </div>
            `;
            container.appendChild(wrapper);
            container.scrollTop = container.scrollHeight;
        } else if (result.error) {
            addAIMessage('system', '错误: ' + result.error);
        }
    } catch(err) {
        const msgs = document.querySelectorAll('#ai-messages .ai-msg.system');
        msgs.forEach(m => { if (m.textContent === '处理中...') m.remove(); });
        addAIMessage('system', '请求失败: ' + err.message);
    }
}

// ===== 文章自引 @ 自动完成 =====

let _refPopup = null;
let _refTimer = null;
let _refStart = -1;
let _refIdx = -1;
let _refResults = [];

function initArticleRef() {
    const ta = document.getElementById('article-content');
    if (!ta) return;

    _refPopup = document.createElement('div');
    _refPopup.id = 'article-ref-popup';
    _refPopup.className = 'article-ref-popup';
    _refPopup.style.display = 'none';
    _refPopup.innerHTML = '<div class="ref-popup-header">@引用文章（输入标题搜索）</div><div class="ref-popup-list" id="ref-popup-list"></div>';
    document.body.appendChild(_refPopup);

    ta.addEventListener('input', onRefInput);
    ta.addEventListener('keydown', onRefKeydown);
    ta.addEventListener('blur', () => setTimeout(closeRefPopup, 200));
    ta.addEventListener('scroll', positionRefPopupRef);
    window.addEventListener('scroll', positionRefPopupRef, true);
    document.addEventListener('click', (e) => {
        if (_refPopup && !_refPopup.contains(e.target) && e.target !== ta) closeRefPopup();
    });
}

function onRefInput(e) {
    const ta = e.target;
    const pos = ta.selectionStart;
    const text = ta.value;

    // 向前查找最近的 @（仅以换行为边界）
    let atPos = -1;
    for (let i = pos - 1; i >= 0; i--) {
        if (text[i] === '\n') break;
        if (text[i] === '@') {
            if (i === 0 || text[i-1] === ' ' || text[i-1] === '\n') {
                atPos = i;
                break;
            }
        }
    }

    if (atPos === -1) {
        closeRefPopup();
        return;
    }

    const query = text.substring(atPos + 1, pos);
    // 查询仅为空白字符时（如 @ 后只打了空格），关闭弹窗让用户保留原文 @
    if (/^\s+$/.test(query)) {
        closeRefPopup();
        return;
    }
    _refStart = atPos;
    _refIdx = -1;
    _refResults = [];

    // 同步显示弹窗并立即展示搜索状态
    openRefPopup(query ? '<div class="ref-popup-loading">搜索中...</div>' : '<div class="ref-popup-empty">输入关键词搜索文章，按 Esc 保留 @ 原文</div>');

    // 防抖搜索
    clearTimeout(_refTimer);
    _refTimer = setTimeout(() => searchRefArticles(query), 200);
}

function onRefKeydown(e) {
    if (!_refPopup || _refPopup.style.display === 'none') return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        _refIdx = Math.min(_refIdx + 1, _refResults.length - 1);
        updateRefActive();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _refIdx = Math.max(_refIdx - 1, 0);
        updateRefActive();
    } else if (e.key === 'Enter') {
        if (_refIdx >= 0 && _refResults[_refIdx]) {
            e.preventDefault();
            selectRefArticle(_refResults[_refIdx]);
        } else {
            closeRefPopup();
        }
    } else if (e.key === 'Escape') {
        closeRefPopup();
    } else if (e.key === ' ') {
        // 光标紧挨 @ 后且无查询内容时，空格退出引用（用户想输入原文 @）
        const ta = document.getElementById('article-content');
        if (ta && _refStart >= 0 && ta.selectionStart === _refStart + 1) {
            closeRefPopup();
        }
    }
}

function openRefPopup(html) {
    const list = document.getElementById('ref-popup-list');
    if (list) list.innerHTML = html;
    if (_refPopup) _refPopup.style.display = '';
    positionRefPopupRef();
}

function positionRefPopupRef() {
    if (!_refPopup || _refPopup.style.display === 'none') return;
    const ta = document.getElementById('article-content');
    if (!ta) return;

    const rect = ta.getBoundingClientRect();
    // 确保弹窗不超出视口
    let left = rect.left;
    let top = rect.bottom + 4;
    const width = Math.min(360, rect.width);
    if (left + width > window.innerWidth - 10) left = window.innerWidth - width - 10;
    if (left < 10) left = 10;
    if (top + 280 > window.innerHeight) top = rect.top - 284;
    _refPopup.style.left = left + 'px';
    _refPopup.style.top = top + 'px';
    _refPopup.style.width = width + 'px';
}

async function searchRefArticles(query) {
    const list = document.getElementById('ref-popup-list');
    if (!list) return;

    if (!query) {
        if (list) list.innerHTML = '<div class="ref-popup-empty">输入标题关键词搜索...</div>';
        return;
    }

    try {
        const resp = await fetch('/api/articles?scope=reference&search=' + encodeURIComponent(query) + '&per_page=8', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        _refResults = (data.articles || []).slice(0, 8);
        _refIdx = _refResults.length > 0 ? 0 : -1;
        if (list) renderRefList(list);
    } catch (e) {
        if (list) list.innerHTML = '<div class="ref-popup-empty">搜索失败，请重试</div>';
        _refResults = [];
        _refIdx = -1;
    }
}

function renderRefList(list) {
    if (!_refResults.length) {
        list.innerHTML = '<div class="ref-popup-empty">无匹配文章</div>';
        return;
    }
    list.innerHTML = _refResults.map((a, i) => `
        <button class="ref-popup-item${i === _refIdx ? ' active' : ''}" data-idx="${i}">
            <div class="ref-title">${esc(a.title || '无标题')}</div>
            <div class="ref-meta">${esc(a.author_display_name || a.author_name || '')} &middot; ${esc((a.created_at || '').substring(0, 10))}</div>
        </button>
    `).join('');

    list.querySelectorAll('.ref-popup-item').forEach(btn => {
        btn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            selectRefArticle(_refResults[parseInt(btn.dataset.idx)]);
        });
    });
}

function updateRefActive() {
    const list = document.getElementById('ref-popup-list');
    if (!list) return;
    list.querySelectorAll('.ref-popup-item').forEach((btn, i) => {
        btn.classList.toggle('active', i === _refIdx);
    });
    const active = list.querySelector('.ref-popup-item.active');
    if (active) active.scrollIntoView({ block: 'nearest' });
}

function selectRefArticle(article) {
    const ta = document.getElementById('article-content');
    if (!ta || !article) return;

    const before = ta.value.substring(0, _refStart);
    const pos = ta.selectionStart;
    const after = ta.value.substring(pos);

    const link = '[' + article.title + '](' + (window.basePath || '') + '/article/' + article.id + ') ';
    ta.value = before + link + after;

    const newPos = before.length + link.length;
    ta.setSelectionRange(newPos, newPos);
    ta.focus();

    closeRefPopup();
}

function closeRefPopup() {
    if (_refPopup) _refPopup.style.display = 'none';
    _refStart = -1;
    _refIdx = -1;
    _refResults = [];
    clearTimeout(_refTimer);
}

window.addEventListener('resize', () => {
    if (_refPopup && _refPopup.style.display !== 'none') positionRefPopupRef();
});

function toggleSyncScroll() {
    _previewSyncEnabled = document.getElementById('sync-scroll-toggle').checked;
}
