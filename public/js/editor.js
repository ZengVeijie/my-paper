/**
 * 平静之心 - 编辑器 & AI 助手
 */

let _previewSyncEnabled = true;

// 本地 esc 回退（防止 app.js 未加载时的竞态）
var _escDiv;
function esc(s) {
    if (!_escDiv) _escDiv = document.createElement('div');
    _escDiv.textContent = s || '';
    return _escDiv.innerHTML;
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.editorMode === 'minimal') {
        initMinimalEditor();
        initUpload();
        initArticleRef();
        return;
    }
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
        case 'checklist':
            if (sel && sel.includes('\n')) {
                const lines = sel.split('\n');
                const prefixed = lines.map(l => {
                    const trimmed = l.trimStart();
                    const indent = l.slice(0, l.length - trimmed.length);
                    if (!trimmed) return l;
                    return indent + '- [ ] ' + trimmed;
                }).join('\n');
                doReplace(textarea, prefixed, start, end, start + prefixed.length);
                return;
            }
            before = '\n- [ ] ';
            break;
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
            openTableEditor(textarea);
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
        case 'due':
            showDuePicker(textarea);
            return;
    }

    applyToolbar(textarea, before, after, sel, start, end);
}

// ===== 截止日期选择器 =====

var _dueTextarea = null;

function getCurrentLine(textarea) {
    var cursor = textarea.selectionStart;
    var before = textarea.value.substring(0, cursor);
    var after = textarea.value.substring(cursor);
    var lineStart = before.lastIndexOf('\n') + 1;
    var lineEnd = after.indexOf('\n');
    if (lineEnd === -1) lineEnd = after.length;
    return textarea.value.substring(lineStart, cursor + lineEnd);
}

function showDuePicker(textarea) {
    _dueTextarea = textarea;
    var popover = document.getElementById('due-date-popover');
    var btn = textarea.parentNode.querySelector('[data-action="due"]');
    if (!popover || !btn) return;

    // Pre-fill with existing @due if cursor is on a checklist line with one
    var line = getCurrentLine(textarea);
    var m = line.match(/@due\((\d{4}-\d{2}-\d{2})/);
    var dateInput = document.getElementById('due-date-input');
    dateInput.value = m ? m[1] : '';

    // Position popover below the button
    var rect = btn.getBoundingClientRect();
    popover.style.top = (rect.bottom + 4) + 'px';
    popover.style.left = Math.min(rect.left, window.innerWidth - 320) + 'px';
    popover.style.display = 'block';

    dateInput.focus();
    dateInput.onkeydown = function(e) {
        if (e.key === 'Enter') insertDueDate();
        if (e.key === 'Escape') popover.style.display = 'none';
    };

    setTimeout(function() {
        document.addEventListener('click', closeDuePopoverOnClickOutside);
    }, 0);
}

function closeDuePopoverOnClickOutside(e) {
    var popover = document.getElementById('due-date-popover');
    if (!popover || popover.style.display === 'none') {
        document.removeEventListener('click', closeDuePopoverOnClickOutside);
        return;
    }
    if (!popover.contains(e.target) && e.target.getAttribute('data-action') !== 'due') {
        popover.style.display = 'none';
        document.removeEventListener('click', closeDuePopoverOnClickOutside);
    }
}

function insertDueDate() {
    var dateInput = document.getElementById('due-date-input');
    var dateStr = dateInput.value;
    if (!dateStr || !_dueTextarea) return;

    var ta = _dueTextarea;
    var cursor = ta.selectionStart;
    var before = ta.value.substring(0, cursor);
    var lastNL = before.lastIndexOf('\n');
    var lineStart = lastNL + 1;
    var lineEnd = ta.value.indexOf('\n', cursor);
    if (lineEnd === -1) lineEnd = ta.value.length;

    var line = ta.value.substring(lineStart, lineEnd);
    // Only operate on checklist lines
    if (!/^\s*- \[[ x]\]/.test(line)) {
        document.getElementById('due-date-popover').style.display = 'none';
        return;
    }

    var newLine;
    var newMarker = '@due(' + dateStr + ')';
    if (/@due\([^)]+\)/.test(line)) {
        newLine = line.replace(/@due\([^)]+\)/, newMarker);
    } else {
        // strip existing (完成于...) before appending
        var stripped = line.replace(/\s*\(完成于.*?\)$/, '');
        newLine = stripped + ' ' + newMarker;
    }

    var newVal = ta.value.substring(0, lineStart) + newLine + ta.value.substring(lineEnd);
    ta.value = newVal;
    ta.focus();
    ta.selectionStart = ta.selectionEnd = lineStart + newLine.length;
    document.getElementById('due-date-popover').style.display = 'none';
    _dueTextarea = null;
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
    setTimeout(() => document.addEventListener('click', closeCP), 150);
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
    setTimeout(() => document.addEventListener('click', closeCP), 150);
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

// ===== 极简编辑器 =====

let _minimalAiResultText = '';
let _mdBlocks = []; // {source, type} 逐行管理的 markdown 块

function initMinimalEditor() {
    var toggleEdit = document.getElementById('minimal-toggle-edit');
    var toggleView = document.getElementById('minimal-toggle-view');
    var textarea = document.getElementById('article-content');
    var preview = document.getElementById('minimal-preview');
    var editorArea = document.getElementById('minimal-editor-area');

    if (!textarea || !preview) return;

    // 按 marked lexer 拆分 Markdown 为块
    function splitToBlocks(md) {
        if (typeof marked !== 'undefined' && typeof marked.lexer === 'function') {
            var tokens = marked.lexer(md);
            var blocks = [];
            for (var i = 0; i < tokens.length; i++) {
                var t = tokens[i];
                if (t.type === 'space') continue;
                blocks.push({ source: t.raw, type: t.type });
            }
            return blocks;
        }
        var parts = md.split(/\n\n+/);
        var blocks = [];
        for (var j = 0; j < parts.length; j++) {
            var p = parts[j];
            if (p.trim()) blocks.push({ source: p, type: 'paragraph' });
        }
        return blocks;
    }

    function renderBlockPreview() {
        var md = textarea.value;
        if (!md.trim()) {
            preview.innerHTML = '<p style="color:var(--text-muted)">暂无内容</p>';
            _mdBlocks = [];
            return;
        }
        if (typeof marked === 'undefined') {
            preview.innerHTML = '<p style="color:var(--text-muted)">Markdown 渲染库加载中...</p>';
            return;
        }

        _mdBlocks = splitToBlocks(md);
        var html = '';
        for (var i = 0; i < _mdBlocks.length; i++) {
            var blockHtml;
            try {
                blockHtml = marked.parse(_mdBlocks[i].source);
            } catch (e) {
                blockHtml = '<p>' + esc(_mdBlocks[i].source) + '</p>';
            }
            html += '<div class="md-block" data-block-idx="' + i + '">' + blockHtml + '</div>';
        }
        preview.innerHTML = html;
    }

    function showEdit() {
        textarea.style.display = '';
        preview.style.display = 'none';
        if (toggleEdit) toggleEdit.classList.add('active');
        if (toggleView) toggleView.classList.remove('active');
        textarea.focus();
    }

    function showPreview() {
        renderBlockPreview();
        textarea.style.display = 'none';
        preview.style.display = '';
        if (toggleView) toggleView.classList.add('active');
        if (toggleEdit) toggleEdit.classList.remove('active');
    }
    if (toggleEdit) toggleEdit.addEventListener('click', showEdit);
    if (toggleView) toggleView.addEventListener('click', showPreview);

    // Tab 键缩进
    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            if (start !== end) {
                var before = textarea.value.substring(0, start);
                var sel = textarea.value.substring(start, end);
                var after = textarea.value.substring(end);
                var lines = sel.split('\n');
                var indented = lines.map(function(l) { return '    ' + l; }).join('\n');
                doReplace(textarea, indented, start, end, start + indented.length);
            } else {
                doReplace(textarea, '    ', start, end, start + 4);
            }
        }
    });

    // marked 加载等待
    if (typeof marked === 'undefined') {
        var retries = 0;
        var waitMarked = setInterval(function() {
            retries++;
            if (typeof marked !== 'undefined') {
                clearInterval(waitMarked);
                if (preview.style.display !== 'none') renderBlockPreview();
            } else if (retries > 30) {
                clearInterval(waitMarked);
            }
        }, 200);
    }

    // 文章选项抽屉
    var metaBtn = document.getElementById('minimal-meta-btn');
    var metaDrawer = document.getElementById('minimal-meta-drawer');
    if (metaBtn && metaDrawer) {
        metaBtn.addEventListener('click', function() {
            metaDrawer.style.display = metaDrawer.style.display === 'none' ? '' : 'none';
        });
    }

    // AI FAB → 打开/关闭 AI 面板
    var aiFab = document.getElementById('minimal-ai-fab');
    var aiPanel = document.getElementById('minimal-ai-panel');
    var aiClose = document.getElementById('minimal-ai-close');
    if (aiFab && aiPanel) {
        aiFab.addEventListener('click', function() {
            if (aiPanel.style.display === 'none' || !aiPanel.style.display) {
                aiPanel.style.display = '';
                var msgs = document.getElementById('minimal-ai-messages');
                if (msgs) msgs.scrollTop = msgs.scrollHeight;
            } else {
                aiPanel.style.display = 'none';
            }
        });
    }
    if (aiClose && aiPanel) {
        aiClose.addEventListener('click', function() {
            aiPanel.style.display = 'none';
        });
    }

    // 自动保存 & 草稿恢复
    restoreDraft();
    setInterval(function() {
        if (editorDirty && textarea.value !== lastAutoSaveContent) {
            saveDraft();
            lastAutoSaveContent = textarea.value;
            editorDirty = false;
        }
    }, 30000);
    window.addEventListener('beforeunload', function() { saveDraft(); });
    textarea.addEventListener('input', function() { editorDirty = true; });

    // 拖拽上传
    var dragCounter = 0;
    editorArea.addEventListener('dragenter', function(e) {
        e.preventDefault();
        dragCounter++;
        editorArea.style.background = 'var(--accent-light)';
    });
    editorArea.addEventListener('dragleave', function() {
        dragCounter--;
        if (dragCounter <= 0) {
            dragCounter = 0;
            editorArea.style.background = '';
        }
    });
    editorArea.addEventListener('dragover', function(e) { e.preventDefault(); });
    editorArea.addEventListener('drop', function(e) {
        e.preventDefault();
        dragCounter = 0;
        editorArea.style.background = '';
        uploadFiles(Array.from(e.dataTransfer.files));
    });

    // ===== 右键上下文菜单 =====
    var ctxMenu = document.createElement('div');
    ctxMenu.className = 'minimal-ctx-menu';
    ctxMenu.id = 'minimal-ctx-menu';
    ctxMenu.style.display = 'none';
    document.body.appendChild(ctxMenu);

    var ctxActions = [
        { label: '粗体', action: 'bold', kbd: 'B' },
        { label: '斜体', action: 'italic', kbd: 'I' },
        { label: '删除线', action: 'strikethrough', kbd: 'S' },
        null,
        { label: '标题', action: 'heading', kbd: 'H2' },
        { label: '引用', action: 'quote' },
        null,
        { label: '无序列表', action: 'ul' },
        { label: '有序列表', action: 'ol' },
        { label: '任务清单', action: 'checklist' },
        null,
        { label: '链接', action: 'link' },
        { label: '图片', action: 'image' },
        { label: '代码', action: 'code' },
        { label: '表格', action: 'table' },
        { label: '分割线', action: 'hr' },
        null,
        { label: '缩进', action: 'indent' },
        { label: '文字颜色', action: 'color' },
    ];

    function buildCtxMenu() {
        var html = '';
        for (var i = 0; i < ctxActions.length; i++) {
            var item = ctxActions[i];
            if (!item) { html += '<div class="ctx-sep"></div>'; continue; }
            html += '<button class="ctx-item" data-action="' + item.action + '">' +
                '<span>' + item.label + '</span>' +
                (item.kbd ? '<kbd>' + item.kbd + '</kbd>' : '') +
            '</button>';
        }
        ctxMenu.innerHTML = html;

        // 绑事件
        var btns = ctxMenu.querySelectorAll('.ctx-item');
        for (var b = 0; b < btns.length; b++) {
            btns[b].addEventListener('mousedown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                handleCtxAction(this.dataset.action);
            });
        }
    }
    buildCtxMenu();

    function showCtxMenu(x, y) {
        // 确保菜单不超出视口
        var mw = 200;
        var mh = ctxActions.length * 34;
        if (x + mw > window.innerWidth) x = window.innerWidth - mw - 8;
        if (y + mh > window.innerHeight) y = window.innerHeight - mh - 8;
        if (x < 8) x = 8;
        if (y < 8) y = 8;
        ctxMenu.style.left = x + 'px';
        ctxMenu.style.top = y + 'px';
        ctxMenu.style.display = '';
    }

    function hideCtxMenu() {
        ctxMenu.style.display = 'none';
    }

    function handleCtxAction(action) {
        hideCtxMenu();
        // 预览模式：切到编辑模式再执行
        if (preview.style.display !== 'none') {
            preview.style.display = 'none';
            textarea.style.display = '';
            if (toggleEdit) toggleEdit.classList.add('active');
            if (toggleView) toggleView.classList.remove('active');
        }
        textarea.focus();
        toolbarAction(textarea, action);
        textarea.dispatchEvent(new Event('input'));
    }

    // 右键事件
    textarea.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        showCtxMenu(e.clientX, e.clientY);
    });

    preview.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        // 确保右键目标在内容块上时保留原生选区
        showCtxMenu(e.clientX, e.clientY);
    });

    // 点击其他区域关闭
    document.addEventListener('click', function(e) {
        if (!ctxMenu.contains(e.target)) hideCtxMenu();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') hideCtxMenu();
    });
}

function minimalAddMsg(role, content) {
    var container = document.getElementById('minimal-ai-messages');
    if (!container) return;
    var div = document.createElement('div');
    div.className = 'ai-msg ' + role;
    div.textContent = content;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

// 获取当前应发给 AI 的文本：选区优先（预览选区或 textarea 选区），否则全文
function getMinimalAiText() {
    var preview = document.getElementById('minimal-preview');
    // 1. 预览可见 → 检查预览中的选区
    if (preview && preview.style.display !== 'none') {
        var sel = window.getSelection();
        if (sel && sel.rangeCount > 0 && sel.toString().trim()) {
            var range = sel.getRangeAt(0);
            if (preview.contains(range.commonAncestorContainer)) {
                return { text: sel.toString(), hasSelection: true };
            }
        }
    }
    // 2. 编辑模式 → 检查 textarea 中的选区
    var textarea = document.getElementById('article-content');
    if (textarea) {
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        if (start !== end) {
            var selText = textarea.value.substring(start, end);
            if (selText.trim()) {
                return { text: selText, hasSelection: true };
            }
        }
        return { text: textarea.value, hasSelection: false };
    }
    return { text: '', hasSelection: false };
}

function minimalShowResult(label, text) {
    _minimalAiResultText = text;
    document.getElementById('minimal-ai-result-label').textContent = label || 'AI 结果';
    document.getElementById('minimal-ai-result-body').textContent = text;
    document.getElementById('minimal-ai-result').style.display = '';
}

async function minimalAiAction(action) {
    var ctx = getMinimalAiText();
    if (!ctx.text.trim()) { alert('请先书写内容'); return; }

    var body = { text: ctx.text };
    var label = action;
    var scopeLabel = ctx.hasSelection ? '选中文字（' + ctx.text.length + ' 字）' : '全文';

    if (action === 'style') {
        var style = prompt('请选择写作风格：\n\n可选：文学优美、简洁精炼、学术严谨、随笔随性、口语化', '文学优美');
        if (!style) return;
        body = { text: ctx.text, style: style.trim() };
        label = '风格切换（' + style.trim() + '）';
    } else if (action === 'summary') {
        label = '生成摘要';
    } else if (action === 'polish') {
        label = '润色';
    }

    minimalAddMsg('user', '[' + label + '] ' + scopeLabel + '...');
    minimalAddMsg('system', '处理中...');

    try {
        var resp = await fetch('/api/ai/' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        });
        var result = await resp.json();

        var sysMsgs = document.querySelectorAll('#minimal-ai-messages .ai-msg.system');
        sysMsgs.forEach(function(m) { if (m.textContent === '处理中...') m.remove(); });

        if (result.text || result.result) {
            var output = result.text || result.result;
            minimalAddMsg('assistant', output);
            minimalShowResult(label, output);
        } else if (result.error) {
            minimalAddMsg('system', '错误: ' + result.error);
        }
    } catch (err) {
        var errMsgs = document.querySelectorAll('#minimal-ai-messages .ai-msg.system');
        errMsgs.forEach(function(m) { if (m.textContent === '处理中...') m.remove(); });
        minimalAddMsg('system', '请求失败: ' + err.message);
    }
}

async function minimalAiContinue() {
    var ctx = getMinimalAiText();
    if (!ctx.text.trim()) { alert('请先书写内容'); return; }

    var msgs = document.getElementById('minimal-ai-messages');
    var wrapper = document.createElement('div');
    wrapper.className = 'ai-msg-wrapper';
    wrapper.id = 'minimal-continue-prompt';
    wrapper.innerHTML = '<div class="ai-msg assistant">' +
        '<div style="font-weight:500;margin-bottom:6px;">续写方向：</div>' +
        '<div style="display:flex;gap:6px;flex-wrap:wrap;">' +
            '<button class="btn btn-sm btn-primary" onclick="minimalDoContinue(\'继续写下去\')">继续写下去</button>' +
            '<button class="btn btn-sm" onclick="minimalDoContinue(\'换个角度写\')">换个角度写</button>' +
            '<button class="btn btn-sm" onclick="minimalDoContinue(\'总结收尾\')">总结收尾</button>' +
        '</div>' +
    '</div>';
    msgs.appendChild(wrapper);
    msgs.scrollTop = msgs.scrollHeight;
}

async function minimalDoContinue(direction) {
    var promptEl = document.getElementById('minimal-continue-prompt');
    if (promptEl) promptEl.remove();

    var ctx = getMinimalAiText();
    var scopeLabel = ctx.hasSelection ? '选中文字（' + ctx.text.length + ' 字）' : '全文';

    minimalAddMsg('user', '[续写] ' + direction + ' · ' + scopeLabel);
    minimalAddMsg('system', '续写中...');

    try {
        var resp = await fetch('/api/ai/continue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ context: ctx.text, direction: direction })
        });
        var result = await resp.json();

        var sysMsgs = document.querySelectorAll('#minimal-ai-messages .ai-msg.system');
        sysMsgs.forEach(function(m) { if (m.textContent === '续写中...') m.remove(); });

        if (result.text) {
            minimalAddMsg('assistant', result.text);
            minimalShowResult('续写（' + direction + '）', result.text);
        } else if (result.error) {
            minimalAddMsg('system', '错误: ' + result.error);
        }
    } catch (err) {
        var errMsgs = document.querySelectorAll('#minimal-ai-messages .ai-msg.system');
        errMsgs.forEach(function(m) { if (m.textContent === '续写中...') m.remove(); });
        minimalAddMsg('system', '请求失败: ' + err.message);
    }
}

async function minimalAiChat() {
    var input = document.getElementById('minimal-ai-input');
    var question = input.value.trim();
    if (!question) return;

    var ctx = getMinimalAiText();
    var scopeNote = ctx.hasSelection ? ' [选中 ' + ctx.text.length + ' 字]' : '';

    minimalAddMsg('user', question + scopeNote);
    input.value = '';
    minimalAddMsg('system', '思考中...');

    try {
        var resp = await fetch('/api/ai/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ question: question, article_content: ctx.text })
        });
        var result = await resp.json();

        var sysMsgs = document.querySelectorAll('#minimal-ai-messages .ai-msg.system');
        sysMsgs.forEach(function(m) { if (m.textContent === '思考中...') m.remove(); });

        if (result.text || result.answer) {
            var reply = result.text || result.answer;
            minimalAddMsg('assistant', reply);
        } else if (result.error) {
            minimalAddMsg('system', '错误: ' + result.error);
        }
    } catch (err) {
        var errMsgs = document.querySelectorAll('#minimal-ai-messages .ai-msg.system');
        errMsgs.forEach(function(m) { if (m.textContent === '思考中...') m.remove(); });
        minimalAddMsg('system', '请求失败: ' + err.message);
    }
}

function replaceMinimalAiResult() {
    if (!_minimalAiResultText) return;
    var textarea = document.getElementById('article-content');
    textarea.value = _minimalAiResultText;
    textarea.dispatchEvent(new Event('input'));
    closeMinimalAiResult();
}

function insertMinimalAiResult() {
    if (!_minimalAiResultText) return;
    var textarea = document.getElementById('article-content');
    textarea.value = textarea.value + '\n\n' + _minimalAiResultText;
    textarea.dispatchEvent(new Event('input'));
    closeMinimalAiResult();
}

function closeMinimalAiResult() {
    document.getElementById('minimal-ai-result').style.display = 'none';
}

// ===== 表格编辑器 =====

var _tbl = { rows: 5, cols: 5, hasHeader: true, striped: false, formulas: {}, textarea: null, selCells: [], mergedCells: [], history: [], historyIdx: -1, _historyMax: 80 };

function colLetter(i) { var s = ''; while (i >= 0) { s = String.fromCharCode(65 + (i % 26)) + s; i = Math.floor(i / 26) - 1; } return s; }

function cellRef(r, c) { return colLetter(c) + (r + 1); }

function parseCellRef(ref) {
    var m = /^([A-Z]+)(\d+)$/.exec(ref.toUpperCase());
    if (!m) return null;
    var c = 0;
    for (var i = 0; i < m[1].length; i++) c = c * 26 + (m[1].charCodeAt(i) - 64);
    return { r: parseInt(m[2]) - 1, c: c - 1 };
}

function tblSnapshotFull() {
    var snap = tblSnapshot();
    return {
        rows: _tbl.rows,
        cols: _tbl.cols,
        hasHeader: _tbl.hasHeader,
        striped: _tbl.striped,
        cells: snap.cells,
        formulas: JSON.parse(JSON.stringify(_tbl.formulas)),
        mergedCells: _tbl.mergedCells.slice(),
        selCells: _tbl.selCells.slice()
    };
}

function tblRestoreSnapshot(snap) {
    _tbl.rows = snap.rows;
    _tbl.cols = snap.cols;
    _tbl.hasHeader = snap.hasHeader;
    _tbl.striped = snap.striped;
    _tbl.formulas = JSON.parse(JSON.stringify(snap.formulas));
    _tbl.mergedCells = snap.mergedCells.slice();
    _tbl.selCells = snap.selCells.slice();
    document.getElementById('tbl-striped').checked = _tbl.striped;
    renderTblGridWithData(snap.cells);
    updateTblSelHighlight();
    updateTblSelInfo();
}

function tblPushHistory() {
    // 截断 redo 历史
    if (_tbl.historyIdx < _tbl.history.length - 1) {
        _tbl.history = _tbl.history.slice(0, _tbl.historyIdx + 1);
    }
    _tbl.history.push(tblSnapshotFull());
    if (_tbl.history.length > _tbl._historyMax) _tbl.history.shift();
    _tbl.historyIdx = _tbl.history.length - 1;
}

function tblUndo() {
    if (_tbl.historyIdx <= 0) return;
    _tbl.historyIdx--;
    tblRestoreSnapshot(_tbl.history[_tbl.historyIdx]);
}

function tblRedo() {
    if (_tbl.historyIdx >= _tbl.history.length - 1) return;
    _tbl.historyIdx++;
    tblRestoreSnapshot(_tbl.history[_tbl.historyIdx]);
}

// 判断选中是否构成完整的行/列
function tblIsFullRowSel() {
    if (_tbl.selCells.length < _tbl.cols) return false;
    var coords = _tbl.selCells.map(function(ref) { return parseCellRef(ref); }).filter(Boolean);
    if (coords.length < _tbl.cols) return false;
    var row = coords[0].r;
    for (var i = 0; i < coords.length; i++) {
        if (coords[i].r !== row) return false;
    }
    // 检查是否覆盖了整行的所有格（无hidden）
    var seen = {};
    for (var i = 0; i < coords.length; i++) { seen[coords[i].c] = true; }
    for (var c = 0; c < _tbl.cols; c++) {
        var td = document.querySelector('#table-editor-grid [data-ref="' + cellRef(row, c) + '"]');
        if (td && td.getAttribute('data-hidden') !== '1' && !seen[c]) return false;
    }
    return true;
}

function tblIsFullColSel() {
    if (_tbl.selCells.length < _tbl.rows) return false;
    var coords = _tbl.selCells.map(function(ref) { return parseCellRef(ref); }).filter(Boolean);
    if (coords.length < _tbl.rows) return false;
    var col = coords[0].c;
    for (var i = 0; i < coords.length; i++) {
        if (coords[i].c !== col) return false;
    }
    var seen = {};
    for (var i = 0; i < coords.length; i++) { seen[coords[i].r] = true; }
    for (var r = 0; r < _tbl.rows; r++) {
        var td = document.querySelector('#table-editor-grid [data-ref="' + cellRef(r, col) + '"]');
        if (td && td.getAttribute('data-hidden') !== '1' && !seen[r]) return false;
    }
    return true;
}

// 获取按行列排序后的选中格，剔除 hidden
function tblSortedSelCells() {
    var list = [];
    for (var i = 0; i < _tbl.selCells.length; i++) {
        var ref = _tbl.selCells[i];
        var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
        if (td && td.getAttribute('data-hidden') === '1') continue;
        var p = parseCellRef(ref);
        if (p) list.push({ ref: ref, r: p.r, c: p.c });
    }
    list.sort(function(a, b) { return a.r !== b.r ? a.r - b.r : a.c - b.c; });
    return list;
}

function tblCopySelection() {
    var sorted = tblSortedSelCells();
    if (!sorted.length) return;

    var isFullCol = tblIsFullColSel();
    var isFullRow = tblIsFullRowSel();
    var lines;

    if (isFullRow) {
        // 整行：Tab 分隔
        lines = [];
        var rowCells = sorted; // already filtered and sorted
        var line = rowCells.map(function(s) { return getTblCellValue(s.ref); }).join('\t');
        lines.push(line);
    } else if (isFullCol) {
        // 整列：每行一行
        var colCells = sorted;
        lines = colCells.map(function(s) { return getTblCellValue(s.ref); });
    } else {
        // 非整行整列：按选中格范围形成矩形
        if (sorted.length === 1) {
            lines = [getTblCellValue(sorted[0].ref)];
        } else {
            var minR = sorted[0].r, maxR = sorted[sorted.length - 1].r;
            var minC = 999, maxC = 0;
            for (var i = 0; i < sorted.length; i++) {
                if (sorted[i].c < minC) minC = sorted[i].c;
                if (sorted[i].c > maxC) maxC = sorted[i].c;
            }
            lines = [];
            for (var r = minR; r <= maxR; r++) {
                var rowVals = [];
                for (var c = minC; c <= maxC; c++) {
                    rowVals.push(getTblCellValue(cellRef(r, c)));
                }
                lines.push(rowVals.join('\t'));
            }
        }
    }

    navigator.clipboard.writeText(lines.join('\n')).catch(function() {
        // Fallback: use textarea
        var ta = document.createElement('textarea');
        ta.value = lines.join('\n');
        ta.style.position = 'fixed'; ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    });
}

async function tblPasteFromClipboard() {
    var text;
    try {
        text = await navigator.clipboard.readText();
    } catch(e) {
        return;
    }
    if (!text) return;

    var lines = text.split(/\r?\n/);
    // 判断是 TSV 还是纯文本
    var hasTab = false;
    for (var i = 0; i < lines.length; i++) {
        if (lines[i].indexOf('\t') !== -1) { hasTab = true; break; }
    }

    if (!hasTab && lines.length === 1) {
        // 纯文本单行 → 贴入当前选中格
        for (var i = 0; i < _tbl.selCells.length; i++) {
            var td = document.querySelector('#table-editor-grid [data-ref="' + _tbl.selCells[i] + '"]');
            if (td) td.textContent = lines[0];
        }
        return;
    }

    // TSV 或多行 → 以第一个选中格为起始点展开粘贴
    if (!_tbl.selCells.length) return;
    var baseP = parseCellRef(_tbl.selCells[0]);
    if (!baseP) return;
    var startR = baseP.r, startC = baseP.c;

    tblPushHistory();

    for (var r = 0; r < lines.length; r++) {
        var cols = hasTab ? lines[r].split('\t') : [lines[r]];
        for (var c = 0; c < cols.length; c++) {
            var ref = cellRef(startR + r, startC + c);
            // 超出表格范围则自动扩展
            if (startR + r >= _tbl.rows) _tbl.rows = startR + r + 1;
            if (startC + c >= _tbl.cols) _tbl.cols = startC + c + 1;
            var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
            if (td && td.getAttribute('data-hidden') !== '1') {
                td.textContent = cols[c] || '';
            }
        }
    }

    // 如果行列数变了，重建以更新 data-ref
    if (startR + lines.length > _tbl.rows - 1 || startC + (lines[0].split('\t').length) > _tbl.cols - 1) {
        // 已经在上面的循环中更新了 _tbl.rows/_tbl.cols
        var snap = tblSnapshot();
        _tbl.rows = Math.max(_tbl.rows, startR + lines.length);
        _tbl.cols = Math.max(_tbl.cols, startC + (hasTab ? lines[0].split('\t').length : 1));
        tblRestoreWithShift(snap, -1, -1, 0, false); // just re-render with correct dimensions
        // Actually let's just rebuild
        renderTblGridWithData(snap.cells);
    }
}

// 键盘快捷键
function tblOnKeyDown(e) {
    var ctrl = e.ctrlKey || e.metaKey;

    if (ctrl && e.key === 'z' && !e.shiftKey) {
        e.preventDefault();
        tblUndo();
        return;
    }
    if (ctrl && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
        e.preventDefault();
        tblRedo();
        return;
    }
    if (ctrl && e.key === 'c') {
        // 如果焦点在公式栏，不拦截
        if (document.activeElement === document.getElementById('tbl-formula-input')) return;
        if (_tbl.selCells.length > 0) {
            e.preventDefault();
            tblCopySelection();
            return;
        }
    }
    if (ctrl && e.key === 'v') {
        if (document.activeElement === document.getElementById('tbl-formula-input')) return;
        if (_tbl.selCells.length > 0) {
            e.preventDefault();
            tblPasteFromClipboard();
            return;
        }
    }
}

function openTableEditor(textarea) {
    _tbl.textarea = textarea;
    _tbl.selCells = [];
    _tbl.mergedCells = [];
    _tbl.editRange = null;
    _tbl.history = [];
    _tbl.historyIdx = -1;
    _tbl._cellEditTimeout = null;
    document.getElementById('tbl-formula-input').value = '';
    document.getElementById('tbl-formula-result').style.display = 'none';

    // 检测光标是否在已有表格内 → 载入编辑
    var existing = findTableAtCursor(textarea);
    if (existing) {
        if (!parseTableFromHTML(existing.html)) {
            initFreshTable();
        } else {
            _tbl.editRange = { start: existing.start, end: existing.end };
        }
    } else {
        initFreshTable();
    }

    document.getElementById('table-editor-modal').style.display = 'flex';
    document.getElementById('tbl-striped').checked = _tbl.striped;

    // 挂载键盘快捷键
    var modal = document.getElementById('table-editor-modal');
    modal.removeEventListener('keydown', tblOnKeyDown);
    modal.addEventListener('keydown', tblOnKeyDown);

    renderTblGrid();
    tblPushHistory(); // 初始快照，作为 undo 底
}

function initFreshTable() {
    _tbl.rows = 5;
    _tbl.cols = 5;
    _tbl.hasHeader = true;
    _tbl.striped = false;
    _tbl.formulas = {};
    _tbl.editRange = null;
}

function findTableAtCursor(textarea) {
    var val = textarea.value;
    var pos = textarea.selectionStart;
    // 向前查找 <table
    var before = val.substring(0, pos);
    var after = val.substring(pos);
    var tableStart = before.lastIndexOf('<table');
    if (tableStart === -1) return null;
    // 确保光标不在 <table 之前（即不跨越其他标签）
    var between = before.substring(tableStart);
    if (between.indexOf('</table>') !== -1) return null; // 光标前已有闭合
    // 向后查找 </table>
    var tableEnd = after.indexOf('</table>');
    if (tableEnd === -1) return null;
    tableEnd = pos + tableEnd + '</table>'.length;
    // 确保没有在闭合前又开了新 table
    var betweenEnd = val.substring(pos, tableEnd);
    if (betweenEnd.indexOf('<table') !== -1) return null; // 嵌套或相邻，保守放弃

    var html = val.substring(tableStart, tableEnd);
    return { start: tableStart, end: tableEnd, html: html };
}

function parseTableFromHTML(html) {
    try {
        var div = document.createElement('div');
        div.innerHTML = html;
        var table = div.querySelector('table');
        if (!table) return false;

        // 读取 metadata
        var meta = table.getAttribute('data-table');
        var formulas = {};
        var hasHeader = true;
        var striped = false;
        if (meta) {
            try {
                var parsed = JSON.parse(meta);
                formulas = parsed.formulas || {};
                hasHeader = parsed.hasHeader !== false;
                striped = parsed.striped || false;
            } catch(e) {}
        }

        // 解析行和单元格
        var rows = table.querySelectorAll('tr');
        var maxCols = 0;
        var cellData = {};
        var mergedCells = [];

        for (var r = 0; r < rows.length; r++) {
            var cells = rows[r].querySelectorAll('th, td');
            var c = 0;
            // 跳过被 rowspan 占位的列
            for (var ci = 0; ci < cells.length; ci++) {
                var cell = cells[ci];
                // 找到当前实际列位置（跳过被上面 rowspan 占用的）
                while (cellData[cellRef(r, c)] !== undefined && cellData[cellRef(r, c)].skip) c++;

                var ref = cellRef(r, c);
                var text = (cell.textContent || '').trim();
                var colspan = parseInt(cell.getAttribute('colspan')) || 1;
                var rowspan = parseInt(cell.getAttribute('rowspan')) || 1;

                cellData[ref] = { text: text };

                // 标记被合并覆盖的格子为 skip
                for (var rr = r; rr < r + rowspan; rr++) {
                    for (var cc = c; cc < c + colspan; cc++) {
                        if (rr === r && cc === c) continue;
                        cellData[cellRef(rr, cc)] = { skip: true };
                    }
                }

                if (colspan > 1 || rowspan > 1) {
                    mergedCells.push({ r: r, c: c, rs: rowspan, cs: colspan });
                }

                c += colspan;
                if (c > maxCols) maxCols = c;
            }
        }

        // 装入 _tbl
        _tbl.rows = rows.length;
        _tbl.cols = maxCols;
        _tbl.hasHeader = hasHeader;
        _tbl.striped = striped;
        _tbl.formulas = formulas;
        _tbl.mergedCells = mergedCells;

        // 保存 cellData 供 renderTblGridWithData 使用
        _tbl._parsedCellData = cellData;
        // Override renderTblGrid for this session
        var origRender = renderTblGrid;
        renderTblGrid = function() {
            renderTblGridWithData(buildCellDataFromParsed());
            renderTblGrid = origRender;
        };

        return true;
    } catch(e) {
        return false;
    }
}

function buildCellDataFromParsed() {
    var data = {};
    var parsed = _tbl._parsedCellData || {};
    var keys = Object.keys(parsed);
    for (var i = 0; i < keys.length; i++) {
        var ref = keys[i];
        var info = parsed[ref];
        if (info.skip) continue;
        // 优先显示公式计算值（如果有公式且结果存在）
        if (_tbl.formulas[ref] !== undefined && info.text) {
            data[ref] = info.text;
        } else {
            data[ref] = info.text || '';
        }
    }
    // 清理
    _tbl._parsedCellData = null;
    return data;
}

function closeTableEditor() {
    document.getElementById('table-editor-modal').style.display = 'none';
}

function renderTblGrid() {
    var grid = document.getElementById('table-editor-grid');
    var html = '';
    for (var r = 0; r < _tbl.rows; r++) {
        html += '<tr>';
        for (var c = 0; c < _tbl.cols; c++) {
            var ref = cellRef(r, c);
            var tag = (_tbl.hasHeader && r === 0) ? 'th' : 'td';
            var cls = '';
            if (_tbl.striped && r % 2 === 1 && !(_tbl.hasHeader && r === 0)) cls += ' striped';
            var val = (_tbl.formulas[ref] !== undefined) ? _tbl.formulas[ref] : '';
            html += '<' + tag + ' class="' + cls + '" contenteditable="true" data-ref="' + ref + '" onfocus="tblOnCellFocus(this)" onblur="tblOnCellBlur(this)" onmousedown="tblOnCellMouseDown(event, this)">' + esc(val) + '</' + tag + '>';
        }
        html += '</tr>';
    }
    grid.innerHTML = html;
    _tbl.selCells = [];
    updateTblSelInfo();
}

function tblOnCellFocus(td) {
    _tbl._cellEditOrig = td.textContent;
    var ref = td.dataset.ref;
    var input = document.getElementById('tbl-formula-input');
    var formula = _tbl.formulas[ref];
    if (formula !== undefined) {
        input.value = formula;
    } else {
        input.value = td.textContent;
    }
    document.getElementById('tbl-formula-result').style.display = 'none';
}

function tblOnCellBlur(td) {
    if (_tbl._cellEditTimeout) clearTimeout(_tbl._cellEditTimeout);
    var newVal = td.textContent;
    if (_tbl._cellEditOrig !== undefined && _tbl._cellEditOrig !== newVal) {
        // 延迟 push，避免连续编辑产生过多历史
        _tbl._cellEditTimeout = setTimeout(function() {
            tblPushHistory();
            _tbl._cellEditTimeout = null;
        }, 400);
    }
    _tbl._cellEditOrig = undefined;
}

function tblOnCellMouseDown(e, td) {
    if (e.shiftKey || e.ctrlKey || e.metaKey) {
        e.preventDefault();
        var ref = td.dataset.ref;
        var idx = _tbl.selCells.indexOf(ref);
        if (idx >= 0) {
            _tbl.selCells.splice(idx, 1);
        } else {
            _tbl.selCells.push(ref);
        }
        updateTblSelHighlight();
        updateTblSelInfo();
    } else {
        _tbl.selCells = [td.dataset.ref];
        updateTblSelHighlight();
        updateTblSelInfo();
    }
}

function updateTblSelHighlight() {
    var cells = document.querySelectorAll('#table-editor-grid td, #table-editor-grid th');
    cells.forEach(function(c) {
        c.classList.toggle('tbl-selected', _tbl.selCells.indexOf(c.dataset.ref) >= 0);
    });
}

function updateTblSelInfo() {
    var info = document.getElementById('tbl-formula-result');
    if (_tbl.selCells.length > 1) {
        info.textContent = '已选 ' + _tbl.selCells.length + ' 个单元格';
        info.style.display = '';
    } else if (_tbl.selCells.length === 0) {
        info.style.display = 'none';
    }
}

function getTblCellValue(ref) {
    var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
    if (!td) return '';
    return td.textContent.trim();
}

function tblGetRangeValues(rangeExpr) {
    var m = /^([A-Z]+\d+):([A-Z]+\d+)$/.exec(rangeExpr.trim());
    if (!m) return [];
    var start = parseCellRef(m[1]);
    var end = parseCellRef(m[2]);
    if (!start || !end) return [];
    var vals = [];
    for (var r = Math.min(start.r, end.r); r <= Math.max(start.r, end.r); r++) {
        for (var c = Math.min(start.c, end.c); c <= Math.max(start.c, end.c); c++) {
            vals.push(getTblCellValue(cellRef(r, c)));
        }
    }
    return vals;
}

function tblLocalCalc(formula) {
    var expr = formula.replace(/^\s*=\s*/, '').trim();
    // SUM
    var m = /^SUM\((.+)\)$/i.exec(expr);
    if (m) {
        var vals = tblGetRangeValues(m[1]);
        var nums = vals.map(parseFloat).filter(function(v) { return !isNaN(v); });
        return nums.reduce(function(a, b) { return a + b; }, 0);
    }
    // AVG / AVERAGE
    m = /^AVG\((.+)\)$|^AVERAGE\((.+)\)$/i.exec(expr);
    if (m) {
        vals = tblGetRangeValues(m[1] || m[2]);
        nums = vals.map(parseFloat).filter(function(v) { return !isNaN(v); });
        if (nums.length === 0) return 0;
        return nums.reduce(function(a, b) { return a + b; }, 0) / nums.length;
    }
    // COUNT
    m = /^COUNT\((.+)\)$/i.exec(expr);
    if (m) {
        vals = tblGetRangeValues(m[1]);
        return vals.filter(function(v) { return v !== ''; }).length;
    }
    // MAX
    m = /^MAX\((.+)\)$/i.exec(expr);
    if (m) {
        vals = tblGetRangeValues(m[1]);
        nums = vals.map(parseFloat).filter(function(v) { return !isNaN(v); });
        if (nums.length === 0) return 0;
        return Math.max.apply(null, nums);
    }
    // MIN
    m = /^MIN\((.+)\)$/i.exec(expr);
    if (m) {
        vals = tblGetRangeValues(m[1]);
        nums = vals.map(parseFloat).filter(function(v) { return !isNaN(v); });
        if (nums.length === 0) return 0;
        return Math.min.apply(null, nums);
    }
    // Simple arithmetic: =A1+B2, =A1*2, etc.
    try {
        var resolved = expr.replace(/([A-Z]+\d+)/gi, function(ref) {
            var v = getTblCellValue(ref);
            var n = parseFloat(v);
            return isNaN(n) ? '0' : n;
        });
        // Sanity check - only allow safe characters
        if (/^[\d\s+\-*/().,%^]+$/.test(resolved)) {
            var result = Function('"use strict"; return (' + resolved + ')')();
            if (typeof result === 'number' && !isNaN(result)) return Math.round(result * 100) / 100;
        }
    } catch(e) {}
    return null;
}

function tblSelectCol() {
    if (_tbl.selCells.length !== 1) { alert('请先选中该列中的任意一个单元格'); return; }
    var p = parseCellRef(_tbl.selCells[0]);
    if (!p) return;
    _tbl.selCells = [];
    for (var r = 0; r < _tbl.rows; r++) {
        _tbl.selCells.push(cellRef(r, p.c));
    }
    updateTblSelHighlight();
    updateTblSelInfo();
}

function tblSelectRow() {
    if (_tbl.selCells.length !== 1) { alert('请先选中该行中的任意一个单元格'); return; }
    var p = parseCellRef(_tbl.selCells[0]);
    if (!p) return;
    _tbl.selCells = [];
    for (var c = 0; c < _tbl.cols; c++) {
        _tbl.selCells.push(cellRef(p.r, c));
    }
    updateTblSelHighlight();
    updateTblSelInfo();
}

// 对公式中的单元格引用进行偏移调整，如 =A1+B1 偏移1行后 → =A2+B2
function tblAdjustFormulaRefs(formula, dr, dc) {
    if (!dr && !dc) return formula;
    return formula.replace(/([A-Z]+)(\d+)/gi, function(match, col, row) {
        var c = 0;
        for (var i = 0; i < col.length; i++) c = c * 26 + (col.toUpperCase().charCodeAt(i) - 64);
        var newC = c + dc;
        var newR = parseInt(row) + dr;
        if (newC < 1 || newR < 1) return match; // 不偏移到无效位置
        return colLetter(newC - 1) + newR;
    });
}

// 对单个格子的公式求值（先本地，再 LLM）
async function tblApplyToCell(formula, ref, tableDataForLLM) {
    var resultEl = document.getElementById('tbl-formula-result');
    // 公式（= 开头）：先尝试本地计算
    if (/^=/.test(formula)) {
        var localResult = tblLocalCalc(formula);
        if (localResult !== null) {
            _tbl.formulas[ref] = formula;
            var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
            if (td) td.textContent = String(localResult);
            resultEl.textContent = ref + ' = ' + localResult;
            resultEl.style.display = '';
            return;
        }
    }

    // 本地算不出来或自然语言 → LLM
    resultEl.textContent = '计算中...';
    resultEl.style.display = '';

    var tableData = tableDataForLLM || tblGatherTableData();
    try {
        var resp = await fetch('/api/table/calc', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ table: tableData, formula: formula, cell: ref, has_header: _tbl.hasHeader })
        });
        var result = await resp.json();
        if (result.value !== undefined) {
            _tbl.formulas[ref] = formula;
            var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
            if (td) td.textContent = String(result.value);
            resultEl.textContent = ref + ' = ' + result.value;
        } else if (result.error) {
            resultEl.textContent = result.error;
        }
    } catch (err) {
        resultEl.textContent = '计算失败: ' + err.message;
    }
}

function tblGatherTableData() {
    var tableData = [];
    for (var r = 0; r < _tbl.rows; r++) {
        var row = [];
        for (var c = 0; c < _tbl.cols; c++) {
            row.push(getTblCellValue(cellRef(r, c)));
        }
        tableData.push(row);
    }
    return tableData;
}

// 对多个选中格批量套用公式，自动偏移引用
async function tblBulkApply(formula) {
    if (_tbl.selCells.length < 2) return;
    tblPushHistory();
    var baseRef = _tbl.selCells[0];
    var baseP = parseCellRef(baseRef);
    if (!baseP) return;

    var resultEl = document.getElementById('tbl-formula-result');
    var tableData = tblGatherTableData();
    var applied = 0;

    for (var i = 0; i < _tbl.selCells.length; i++) {
        var ref = _tbl.selCells[i];
        var p = parseCellRef(ref);
        if (!p) continue;
        var dr = p.r - baseP.r;
        var dc = p.c - baseP.c;
        var adjusted = tblAdjustFormulaRefs(formula, dr, dc);

        // 尝试本地计算
        var localResult = tblLocalCalc(adjusted);
        if (localResult !== null) {
            _tbl.formulas[ref] = adjusted;
            var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
            if (td) td.textContent = String(localResult);
            applied++;
            continue;
        }

        // 本地不行 → LLM 单独算
        resultEl.textContent = '计算中 (' + (i+1) + '/' + _tbl.selCells.length + ')...';
        resultEl.style.display = '';
        try {
            var resp = await fetch('/api/table/calc', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ table: tableData, formula: adjusted, cell: ref, has_header: _tbl.hasHeader })
            });
            var result = await resp.json();
            if (result.value !== undefined) {
                _tbl.formulas[ref] = adjusted;
                var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
                if (td) td.textContent = String(result.value);
                applied++;
            }
        } catch (err) {
            // 继续下一个
        }
    }

    resultEl.textContent = '已计算 ' + applied + ' 个单元格';
    resultEl.style.display = '';
}

async function tblEvalFormula() {
    tblPushHistory();
    var input = document.getElementById('tbl-formula-input');
    var formula = input.value.trim();
    if (!formula) return;

    if (_tbl.selCells.length === 0) {
        alert('请先选中至少一个单元格（可按住 Ctrl 多选，或用工具栏「选列」「选行」）');
        return;
    }

    // 多格 + 公式（= 开头）→ 批量偏移套用
    if (_tbl.selCells.length > 1 && /^=/.test(formula)) {
        tblBulkApply(formula);
        return;
    }

    // 多格 + 自然语言 → 一次送给 LLM，让 LLM 看到全部选中格
    if (_tbl.selCells.length > 1 && !/^=/.test(formula)) {
        var resultEl = document.getElementById('tbl-formula-result');
        resultEl.textContent = '计算中...';
        resultEl.style.display = '';
        var tableData = tblGatherTableData();
        try {
            var resp = await fetch('/api/table/calc', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    table: tableData,
                    formula: formula,
                    cell: _tbl.selCells[0],
                    cells: _tbl.selCells,
                    has_header: _tbl.hasHeader
                })
            });
            var result = await resp.json();
            if (result.values && typeof result.values === 'object') {
                var keys = Object.keys(result.values);
                for (var k = 0; k < keys.length; k++) {
                    var ref = keys[k];
                    _tbl.formulas[ref] = formula;
                    var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
                    if (td) td.textContent = String(result.values[ref]);
                }
                resultEl.textContent = '已计算 ' + keys.length + ' 个单元格';
            } else if (result.value !== undefined) {
                // LLM returned single value — apply to first selected cell only
                var ref0 = _tbl.selCells[0];
                _tbl.formulas[ref0] = formula;
                var td0 = document.querySelector('#table-editor-grid [data-ref="' + ref0 + '"]');
                if (td0) td0.textContent = String(result.value);
                resultEl.textContent = ref0 + ' = ' + result.value;
            } else if (result.error) {
                resultEl.textContent = result.error;
            }
        } catch (err) {
            resultEl.textContent = '计算失败: ' + err.message;
        }
        return;
    }

    // 单格 → 直接套用
    await tblApplyToCell(formula, _tbl.selCells[0]);
}

function tblSnapshot() {
    var snap = { cells: {}, formulas: {} };
    for (var r = 0; r < _tbl.rows; r++) {
        for (var c = 0; c < _tbl.cols; c++) {
            var ref = cellRef(r, c);
            snap.cells[ref] = getTblCellValue(ref);
            if (_tbl.formulas[ref] !== undefined) snap.formulas[ref] = _tbl.formulas[ref];
        }
    }
    return snap;
}

function tblRestoreWithShift(snap, delR, delC, delCount, isAdd) {
    // isAdd=true: inserting at delR/delC, shift existing cells away
    // isAdd=false: deleting at delR/delC, shift existing cells toward
    var newFormulas = {};
    var newCells = {};
    var oldKeys = Object.keys(snap.cells);

    for (var i = 0; i < oldKeys.length; i++) {
        var ref = oldKeys[i];
        var p = parseCellRef(ref);
        if (!p) continue;

        var newR = p.r, newC = p.c;

        if (isAdd) {
            // inserting: cells at or after insert point shift away
            if (delR >= 0 && p.r >= delR) newR = p.r + 1;
            if (delC >= 0 && p.c >= delC) newC = p.c + 1;
        } else {
            // deleting: cells after delete point shift toward; deleted cells dropped
            if (delR >= 0 && p.r === delR) continue;
            if (delC >= 0 && p.c === delC) continue;
            if (delR >= 0 && p.r > delR) newR = p.r - 1;
            if (delC >= 0 && p.c > delC) newC = p.c - 1;
        }

        var newRef = cellRef(newR, newC);
        newCells[newRef] = snap.cells[ref];
        if (snap.formulas[ref] !== undefined) newFormulas[newRef] = snap.formulas[ref];
    }

    _tbl.formulas = newFormulas;
    // Render with pre-filled values
    renderTblGridWithData(newCells);
}

function renderTblGridWithData(cellData) {
    var grid = document.getElementById('table-editor-grid');
    var html = '';
    for (var r = 0; r < _tbl.rows; r++) {
        html += '<tr>';
        for (var c = 0; c < _tbl.cols; c++) {
            var ref = cellRef(r, c);
            var tag = (_tbl.hasHeader && r === 0) ? 'th' : 'td';
            var cls = '';
            if (_tbl.striped && r % 2 === 1 && !(_tbl.hasHeader && r === 0)) cls += ' striped';
            var val = cellData[ref] !== undefined ? cellData[ref] : '';
            html += '<' + tag + ' class="' + cls + '" contenteditable="true" data-ref="' + ref + '" onfocus="tblOnCellFocus(this)" onblur="tblOnCellBlur(this)" onmousedown="tblOnCellMouseDown(event, this)">' + esc(val) + '</' + tag + '>';
        }
        html += '</tr>';
    }
    grid.innerHTML = html;
    _tbl.selCells = [];
    updateTblSelInfo();
}

function tblAddRow() {
    tblPushHistory();
    var snap = tblSnapshot();
    // 有选中格 → 在其所在行下方插入；否则在末尾追加
    var insertAfter = _tbl.rows - 1; // default: end
    if (_tbl.selCells.length > 0) {
        var p = parseCellRef(_tbl.selCells[0]);
        if (p) insertAfter = p.r;
    }
    _tbl.rows++;
    tblRestoreWithShift(snap, insertAfter + 1, -1, 1, true);
}

function tblAddCol() {
    tblPushHistory();
    var snap = tblSnapshot();
    var insertAfter = _tbl.cols - 1;
    if (_tbl.selCells.length > 0) {
        var p = parseCellRef(_tbl.selCells[0]);
        if (p) insertAfter = p.c;
    }
    _tbl.cols++;
    tblRestoreWithShift(snap, -1, insertAfter + 1, 1, true);
}

function tblDelRow() {
    if (_tbl.rows <= 1) return;
    tblPushHistory();
    var target = _tbl.rows - 1; // default: last row
    if (_tbl.selCells.length > 0) {
        var p = parseCellRef(_tbl.selCells[0]);
        if (p) target = p.r;
    }
    var snap = tblSnapshot();
    _tbl.rows--;
    tblRestoreWithShift(snap, target, -1, 1, false);
}

function tblDelCol() {
    if (_tbl.cols <= 1) return;
    tblPushHistory();
    var target = _tbl.cols - 1;
    if (_tbl.selCells.length > 0) {
        var p = parseCellRef(_tbl.selCells[0]);
        if (p) target = p.c;
    }
    var snap = tblSnapshot();
    _tbl.cols--;
    tblRestoreWithShift(snap, -1, target, 1, false);
}

function renderTblGrid() {
    var snap = tblSnapshot();
    renderTblGridWithData(snap.cells);
}

function tblToggleHeader() {
    tblPushHistory();
    _tbl.hasHeader = !_tbl.hasHeader;
    renderTblGrid();
}

function tblToggleStriped() {
    _tbl.striped = document.getElementById('tbl-striped').checked;
    var rows = document.querySelectorAll('#table-editor-grid tr');
    rows.forEach(function(tr, i) {
        var cells = tr.querySelectorAll('td');
        cells.forEach(function(td) {
            td.classList.toggle('striped', _tbl.striped && i % 2 === 1 && !(_tbl.hasHeader && i === 0));
        });
    });
}

function tblMergeCells() {
    if (_tbl.selCells.length < 2) { alert('请按住 Ctrl/Shift 多选至少 2 个相邻单元格'); return; }
    tblPushHistory();

    // Parse refs into coordinates
    var coords = [];
    for (var i = 0; i < _tbl.selCells.length; i++) {
        var p = parseCellRef(_tbl.selCells[i]);
        if (p) coords.push(p);
    }
    if (coords.length < 2) return;

    // Find bounding box
    var minR = Math.min.apply(null, coords.map(function(c) { return c.r; }));
    var maxR = Math.max.apply(null, coords.map(function(c) { return c.r; }));
    var minC = Math.min.apply(null, coords.map(function(c) { return c.c; }));
    var maxC = Math.max.apply(null, coords.map(function(c) { return c.c; }));

    var rowspan = maxR - minR + 1;
    var colspan = maxC - minC + 1;

    // Set colspan/rowspan on top-left cell
    var topLeftRef = cellRef(minR, minC);
    var tl = document.querySelector('#table-editor-grid [data-ref="' + topLeftRef + '"]');
    if (tl) {
        tl.setAttribute('colspan', colspan);
        tl.setAttribute('rowspan', rowspan);
        tl.setAttribute('data-merged', '1');
        tl.style.background = '#faf9f6';
    }
    // Hide merged cells
    for (var r = minR; r <= maxR; r++) {
        for (var c = minC; c <= maxC; c++) {
            if (r === minR && c === minC) continue;
            var td = document.querySelector('#table-editor-grid [data-ref="' + cellRef(r, c) + '"]');
            if (td) { td.style.display = 'none'; td.setAttribute('data-hidden', '1'); }
        }
    }
    _tbl.mergedCells.push({ r: minR, c: minC, rs: rowspan, cs: colspan });
    _tbl.selCells = [];
    updateTblSelHighlight();
}

function tblSplitCell() {
    if (_tbl.selCells.length !== 1) { alert('请选中一个已合并的单元格进行拆分'); return; }
    tblPushHistory();
    var ref = _tbl.selCells[0];
    var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
    if (!td) return;

    var rs = parseInt(td.getAttribute('rowspan')) || 1;
    var cs = parseInt(td.getAttribute('colspan')) || 1;
    if (rs === 1 && cs === 1) return;

    td.removeAttribute('colspan');
    td.removeAttribute('rowspan');
    td.removeAttribute('data-merged');
    td.style.background = '';

    // Restore hidden cells in range
    var p = parseCellRef(ref);
    if (!p) return;
    for (var r = p.r; r < p.r + rs; r++) {
        for (var c = p.c; c < p.c + cs; c++) {
            if (r === p.r && c === p.c) continue;
            var ct = document.querySelector('#table-editor-grid [data-ref="' + cellRef(r, c) + '"]');
            if (ct) { ct.style.display = ''; ct.removeAttribute('data-hidden'); }
        }
    }
    _tbl.mergedCells = _tbl.mergedCells.filter(function(m) { return !(m.r === p.r && m.c === p.c); });
    _tbl.selCells = [];
    updateTblSelHighlight();
}

function tblSetAlign(align) {
    if (_tbl.selCells.length === 0) { alert('请先选中单元格'); return; }
    tblPushHistory();
    for (var i = 0; i < _tbl.selCells.length; i++) {
        var td = document.querySelector('#table-editor-grid [data-ref="' + _tbl.selCells[i] + '"]');
        if (td) td.style.textAlign = align;
    }
}

function insertTableFromEditor() {
    // Build HTML table from grid state
    var html = '<table data-table=\'{"formulas":' + JSON.stringify(_tbl.formulas) + ',"hasHeader":' + _tbl.hasHeader + ',"striped":' + _tbl.striped + '}\'>\n';
    for (var r = 0; r < _tbl.rows; r++) {
        html += '<tr>\n';
        for (var c = 0; c < _tbl.cols; c++) {
            var ref = cellRef(r, c);
            var td = document.querySelector('#table-editor-grid [data-ref="' + ref + '"]');
            if (td && td.getAttribute('data-hidden') === '1') continue;

            var tag = (_tbl.hasHeader && r === 0) ? 'th' : 'td';
            var attrs = '';
            if (td) {
                var colspan = td.getAttribute('colspan');
                var rowspan = td.getAttribute('rowspan');
                if (colspan && parseInt(colspan) > 1) attrs += ' colspan="' + colspan + '"';
                if (rowspan && parseInt(rowspan) > 1) attrs += ' rowspan="' + rowspan + '"';
                if (td.style.textAlign) attrs += ' style="text-align:' + td.style.textAlign + '"';
            }
            var val = getTblCellValue(ref);
            html += '<' + tag + attrs + '>' + esc(val) + '</' + tag + '>\n';
        }
        html += '</tr>\n';
    }
    html += '</table>';

    var textarea = _tbl.textarea || document.getElementById('article-content');
    if (!textarea) { closeTableEditor(); return; }

    textarea.focus();

    if (_tbl.editRange) {
        // 编辑已有表格 → 原地替换
        var before = textarea.value.substring(0, _tbl.editRange.start);
        var after = textarea.value.substring(_tbl.editRange.end);
        // 保留前后换行
        if (before.length > 0 && before[before.length - 1] === '\n') before = before.slice(0, -1);
        else if (before.length > 0 && before[before.length - 1] !== '\n') html = '\n' + html;
        if (after.length > 0 && after[0] === '\n') after = after.substring(1);
        else if (after.length > 0 && after[0] !== '\n') html = html + '\n';
        textarea.value = before + '\n' + html + '\n' + after;
        var newPos = before.length + html.length + 2;
        textarea.setSelectionRange(newPos, newPos);
    } else {
        // 新建表格 → 插入光标处
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        doReplace(textarea, '\n' + html + '\n', start, end, start + html.length + 2);
    }

    textarea.dispatchEvent(new Event('input'));
    closeTableEditor();
}

