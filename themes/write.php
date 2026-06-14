<?php
$edit_mode = isset($edit_article);
$article = $edit_article ?? ['title' => '', 'content' => '', 'summary' => '', 'tags' => [], 'collection_ids' => [], 'visibility' => 'private'];
$article_id = $article['id'] ?? '';
?>
<div class="editor-page">
    <div class="editor-header">
        <input type="text" id="article-title" class="title-input" placeholder="文章标题..." value="<?= h($article['title'] ?? '') ?>" autofocus>
        <div class="editor-header-actions">
            <button class="btn btn-primary" id="save-btn" onclick="saveArticle()">保存</button>
            <?php if ($edit_mode): ?>
            <button class="btn" id="save-as-btn" onclick="saveAsArticle()">另存为</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="editor-container three-col" id="editor-container">
        <!-- 左栏：编辑器 -->
        <div class="editor-pane" id="editor-pane">
            <div class="editor-meta-fields" id="editor-meta-fields">
                <button class="meta-toggle" id="meta-toggle" type="button">
                    文章选项 <span class="meta-toggle-arrow">▾</span>
                </button>
                <div class="meta-fields-inner" id="meta-fields-inner">
                    <div class="meta-row meta-row-summary">
                        <label>摘要</label>
                        <textarea id="article-summary" class="summary-input" rows="2" maxlength="200" placeholder="可选，留空自动截取正文前200字"><?= h($article['summary'] ?? '') ?></textarea>
                    </div>
                    <div class="meta-row meta-row-inline">
                        <label>标签</label>
                        <input type="text" id="article-tags" class="tags-input" placeholder="标签之间用逗号分隔" value="<?= h(implode(',', $article['tags'] ?? [])) ?>">
                        <label>可见性</label>
                        <select id="article-visibility" class="vis-select">
                            <option value="private" <?= ($article['visibility'] ?? 'private') === 'private' ? 'selected' : '' ?>>仅自己可见</option>
                            <option value="internal" <?= ($article['visibility'] ?? '') === 'internal' ? 'selected' : '' ?>>站内可见</option>
                        </select>
                        <label>情感</label>
                        <select id="article-sentiment" class="vis-select">
                            <option value="">未设置</option>
                            <?php
                            $moods = ['喜悦', '忧伤', '愤怒', '焦虑', '平静', '兴奋', '疲惫', '感激'];
                            $current_mood = $article['sentiment']['mood'] ?? '';
                            foreach ($moods as $m):
                                $sel = ($current_mood === $m) ? ' selected' : '';
                            ?>
                            <option value="<?= $m ?>"<?= $sel ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="editor-toolbar" id="editor-toolbar">
                <button type="button" data-action="heading" title="标题"><b>H</b></button>
                <button type="button" data-action="bold" title="粗体"><b>B</b></button>
                <button type="button" data-action="italic" title="斜体"><i>I</i></button>
                <button type="button" data-action="strikethrough" title="删除线"><s>S</s></button>
                <span class="toolbar-sep"></span>
                <button type="button" data-action="quote" title="引用">&ldquo;</button>
                <button type="button" data-action="ul" title="无序列表">&bull;</button>
                <button type="button" data-action="ol" title="有序列表">1.</button>
                <span class="toolbar-sep"></span>
                <button type="button" data-action="link" title="链接">&#x1f517;</button>
                <button type="button" data-action="image" title="图片">&#x1f5bc;</button>
                <button type="button" data-action="code" title="代码">&lt;/&gt;</button>
                <button type="button" data-action="table" title="表格">&#x2637;</button>
                <button type="button" data-action="hr" title="分割线">&mdash;</button>
                <button type="button" data-action="color" title="文字颜色" style="color:var(--accent);font-weight:bold;">A&#x25c9;</button>
                <button type="button" data-action="hex" title="取色器（插入 #RRGGBB）" style="font-weight:bold;">#</button>
                <span class="toolbar-sep"></span>
                <button type="button" data-action="latex-inline" title="行内公式">$x$</button>
                <button type="button" data-action="latex-block" title="块级公式">$$</button>
                <span class="toolbar-sep"></span>
                <button type="button" data-action="indent" title="段前缩进（两全角空格）">&raquo;</button>
                <span class="toolbar-sep"></span>
                <button type="button" id="crop-btn" title="裁剪图片">&nbsp;✂&nbsp;</button>
                <span class="toolbar-sep"></span>
                <button type="button" id="upload-btn" title="上传图片或文件（也可直接拖拽到编辑器或粘贴图片）" class="editor-toolbar-upload">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                </button>
                <input type="file" id="file-input" style="display:none" multiple accept="image/*,.pdf,.doc,.docx,.txt,.md,.zip">
                <span class="toolbar-sep"></span>
                <label class="sync-scroll-toggle-label" title="切换预览跟随滚动"><input type="checkbox" id="sync-scroll-toggle" checked onchange="toggleSyncScroll()"> 跟随</label>
            </div>
            <textarea id="article-content" class="editor-textarea" placeholder="在这里书写 Markdown..."><?= h($article['content'] ?? '') ?></textarea>
        </div>

        <div class="col-resize-handle" id="resize-handle-1"></div>

        <!-- 中栏：预览 -->
        <div class="preview-pane rendered-content" id="preview-pane">
            <div class="preview-empty" id="preview-empty">
                <svg class="preview-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span>书写左侧内容后将实时显示</span>
            </div>
        </div>

        <div class="col-resize-handle" id="resize-handle-2"></div>

        <!-- 右栏：AI 助手 -->
        <div class="ai-panel" id="ai-panel">
            <div class="ai-panel-header">
                <span>AI 助手</span>
                <button class="ai-panel-collapse" id="ai-collapse-btn">折叠</button>
            </div>
            <div class="ai-panel-body">
                <div class="ai-reference" id="ai-reference" style="display:none">
                    <span class="ref-label">当前操作：全文</span>
                </div>
                <div class="ai-actions">
                    <span class="ai-actions-label">修改</span>
                    <button class="btn btn-sm" onclick="aiAction('polish')" title="润色文字，使表达更流畅">润色</button>
                    <button class="btn btn-sm" onclick="aiAction('translate')" title="翻译为英语">翻译</button>
                    <button class="btn btn-sm" onclick="aiAction('style')" title="切换写作风格">风格</button>
                    <button class="btn btn-sm" onclick="aiAction('format')" title="将纯文本转换为结构化 Markdown">格式化</button>
                    <span class="ai-actions-sep"></span>
                    <span class="ai-actions-label">理解 & 辅助</span>
                    <button class="btn btn-sm" onclick="aiAction('explain')" title="解释选中的专有名词和术语">解释</button>
                    <button class="btn btn-sm" onclick="aiAction('summary')" title="生成文章摘要">摘要</button>
                    <button class="btn btn-sm" onclick="aiHighlights()" title="提取文章金句">金句</button>
                    <button class="btn btn-sm" onclick="aiContinue()" title="从光标处续写下一段">续写</button>
                    <button class="btn btn-sm" onclick="aiSuggestTitle()" title="AI 推荐标题">标题</button>
                    <button class="btn btn-sm" onclick="aiSuggestTags()" title="AI 推荐标签">标签</button>
                </div>
                <div class="ai-templates" id="ai-templates" style="display:none;padding:0 0 4px;margin-bottom:4px;position:relative;">
                    <input type="text" id="ai-template-input" placeholder="搜索模板..." autocomplete="off" style="flex:1;padding:4px 6px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font-ui);font-size:0.78rem;background:var(--bg-card);max-width:140px;">
                    <div id="ai-template-dropdown" style="display:none;position:absolute;top:100%;left:0;min-width:200px;max-height:180px;overflow-y:auto;background:var(--bg-card);border:1px solid var(--border);border-radius:0 0 var(--radius) var(--radius);box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:1200;"></div>
                    <button class="btn btn-sm" onclick="aiUseTemplate()" style="font-size:0.75rem;">使用</button>
                </div>
                <div class="ai-chat" id="ai-chat">
                    <div class="ai-messages" id="ai-messages"></div>
                    <div class="ai-input-row">
                        <input type="text" id="ai-input" placeholder="输入问题或指令..." onkeydown="if(event.key==='Enter')aiChat()">
                        <button class="btn btn-sm btn-primary" onclick="aiChat()">发送</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 移动端底部操作栏 -->
    <div class="mobile-editor-bar" id="mobile-editor-bar">
        <button class="meb-btn" id="meb-upload" title="上传文件">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
        </button>
        <div class="meb-tabs">
            <button class="meb-tab active" data-tab="editor">编辑</button>
            <button class="meb-tab" data-tab="preview">预览</button>
            <button class="meb-tab" data-tab="ai">AI</button>
        </div>
        <button class="meb-btn meb-save" id="meb-save" title="保存" onclick="saveArticle()">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        </button>
    </div>

    <!-- 图片裁剪弹窗 -->
    <div class="modal" id="crop-modal" style="display:none">
        <div class="modal-overlay" onclick="closeCropModal()"></div>
        <div class="modal-card" style="max-width:90vw;">
            <h2>裁剪图片</h2>
            <div style="display:flex;gap:12px;margin-bottom:12px;">
                <input type="file" id="crop-file-input" accept="image/*" onchange="loadCropImage(event)" style="flex:1;">
                <button class="btn btn-sm" onclick="document.getElementById('crop-file-input').click()">选择图片</button>
            </div>
            <div id="crop-canvas-wrap" style="position:relative;display:inline-block;max-width:100%;overflow:auto;background:var(--bg);border-radius:var(--radius);">
                <canvas id="crop-canvas" style="display:block;max-width:100%;cursor:crosshair;"></canvas>
            </div>
            <p style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">拖拽矩形框选择裁剪区域，拖动四角调整大小</p>
            <div class="modal-actions" style="margin-top:12px;">
                <button class="btn" onclick="closeCropModal()">取消</button>
                <button class="btn btn-primary" onclick="doCrop()">裁剪并插入</button>
            </div>
        </div>
    </div>

    <!-- AI 面板唤回按钮（桌面端面板折叠时显示） -->
    <button id="ai-reopen-btn" class="ai-reopen-btn" style="display:none" title="展开 AI 助手">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 100 20 10 10 0 000-20z"/><path d="M12 6v6l4 2"/></svg>
        <span>AI</span>
    </button>

    <script src="/public/js/editor.js"></script>
    <script>
    window.articleId = <?= json_encode($article_id) ?>;
    window.isEdit = <?= json_encode($edit_mode) ?>;
    document.getElementById('article-content').addEventListener('mouseup', updateAIReference);
    document.getElementById('article-content').addEventListener('keyup', updateAIReference);
    </script>
</div>
