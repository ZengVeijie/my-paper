<?php
$edit_mode = isset($edit_article);
$article = $edit_article ?? ['title' => '', 'content' => '', 'summary' => '', 'tags' => [], 'collection_ids' => [], 'visibility' => 'private'];
$article_id = $article['id'] ?? '';
$user = current_user();
$editor_mode = $user['editor_mode'] ?? 'default';
?>

<?php if ($editor_mode === 'minimal'): ?>
<!-- ===== 极简编辑器 ===== -->
<div class="editor-page editor-minimal" id="editor-page">
    <div class="minimal-topbar">
        <input type="text" id="article-title" class="minimal-title-input" placeholder="无标题" value="<?= h($article['title'] ?? '') ?>" autofocus>
        <div class="minimal-topbar-actions">
            <button class="minimal-btn minimal-meta-btn" id="minimal-meta-btn" title="文章选项">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
            </button>
            <button class="minimal-btn minimal-save-btn" id="save-btn" onclick="saveArticle()">保存</button>
            <?php if ($edit_mode): ?>
            <button class="minimal-btn" id="save-as-btn" onclick="saveAsArticle()">另存</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 文章选项抽屉 -->
    <div class="minimal-meta-drawer" id="minimal-meta-drawer" style="display:none">
        <div class="minimal-meta-inner">
            <label class="minimal-meta-label">
                <span>摘要</span>
                <textarea id="article-summary" rows="2" maxlength="200" placeholder="可选"><?= h($article['summary'] ?? '') ?></textarea>
            </label>
            <label class="minimal-meta-label">
                <span>标签</span>
                <input type="text" id="article-tags" placeholder="逗号分隔" value="<?= h(implode(',', $article['tags'] ?? [])) ?>">
            </label>
            <label class="minimal-meta-label">
                <span>可见性</span>
                <select id="article-visibility">
                    <option value="private" <?= ($article['visibility'] ?? 'private') === 'private' ? 'selected' : '' ?>>仅自己</option>
                    <option value="internal" <?= ($article['visibility'] ?? '') === 'internal' ? 'selected' : '' ?>>站内可见</option>
                </select>
            </label>
            <label class="minimal-meta-label">
                <span>情感</span>
                <select id="article-sentiment">
                    <option value="">未设置</option>
                    <?php foreach (['喜悦','忧伤','愤怒','焦虑','平静','兴奋','疲惫','感激'] as $m): ?>
                    <option value="<?= $m ?>" <?= ($article['sentiment']['mood'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <!-- 编辑/预览切换 -->
    <div class="minimal-view-toggle" id="minimal-view-toggle">
        <button class="minimal-toggle-btn active" data-mode="edit" id="minimal-toggle-edit">编辑</button>
        <button class="minimal-toggle-btn" data-mode="view" id="minimal-toggle-view">预览</button>
    </div>

    <!-- 编辑区 -->
    <div class="minimal-editor-area" id="minimal-editor-area">
        <textarea id="article-content" class="minimal-textarea" placeholder="开始写作..."><?= h($article['content'] ?? '') ?></textarea>
        <div class="minimal-preview rendered-content markdown-body" id="minimal-preview" style="display:none"></div>
    </div>

    <!-- AI 悬浮按钮 -->
    <button class="minimal-ai-fab" id="minimal-ai-fab" title="AI 助手">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a4 4 0 014 4c0 2-2 3-2 5h-4c0-2-2-3-2-5a4 4 0 014-4z"/><path d="M8 14h8"/><path d="M9 18h6"/></svg>
    </button>

    <!-- AI 迷你面板 -->
    <div class="minimal-ai-panel" id="minimal-ai-panel" style="display:none">
        <div class="minimal-ai-header">
            <span>AI 助手</span>
            <button class="minimal-ai-close" id="minimal-ai-close">&times;</button>
        </div>
        <div class="minimal-ai-actions" id="minimal-ai-actions">
            <button onclick="minimalAiAction('polish')">润色</button>
            <button onclick="minimalAiContinue()">续写</button>
            <button onclick="minimalAiAction('summary')">摘要</button>
            <button onclick="minimalAiAction('style')">风格</button>
        </div>
        <div class="minimal-ai-chat">
            <div class="minimal-ai-messages" id="minimal-ai-messages"></div>
            <div class="minimal-ai-input-row">
                <input type="text" id="minimal-ai-input" placeholder="输入问题..." onkeydown="if(event.key==='Enter')minimalAiChat()">
                <button onclick="minimalAiChat()">发送</button>
            </div>
        </div>
    </div>

    <!-- AI 结果弹窗 -->
    <div class="minimal-ai-result" id="minimal-ai-result" style="display:none">
        <div class="minimal-ai-result-header">
            <span id="minimal-ai-result-label">AI 结果</span>
            <button class="minimal-ai-close" onclick="closeMinimalAiResult()">&times;</button>
        </div>
        <div class="minimal-ai-result-body" id="minimal-ai-result-body"></div>
        <div class="minimal-ai-result-actions">
            <button class="btn btn-sm" onclick="replaceMinimalAiResult()">替换原文</button>
            <button class="btn btn-sm" onclick="insertMinimalAiResult()">追加到文末</button>
            <button class="btn btn-sm" onclick="closeMinimalAiResult()">关闭</button>
        </div>
    </div>

    <!-- 上传（隐藏） -->
    <input type="file" id="file-input" style="display:none" multiple accept="image/*,.pdf,.doc,.docx,.txt,.md,.zip">

    <script src="/public/js/editor.js"></script>
    <script>
    window.articleId = <?= json_encode($article_id) ?>;
    window.isEdit = <?= json_encode($edit_mode) ?>;
    window.editorMode = 'minimal';
    </script>
</div>

<?php else: ?>
<!-- ===== 标准编辑器 ===== -->
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
                <button type="button" data-action="checklist" title="任务清单">☑</button>
                <button type="button" data-action="due" title="为任务添加截止日期（支持多天范围）">截止日</button>
                <span class="toolbar-sep"></span>
                <button type="button" data-action="link" title="链接">&#x1f517;</button>
                <button type="button" data-action="image" title="图片">&#x1f5bc;</button>
                <button type="button" data-action="code" title="代码">&lt;/&gt;</button>
                <button type="button" data-action="table" title="表格（光标在表格内时打开编辑，否则新建）">&#x2637;</button>
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
                <div id="due-date-popover" style="display:none;position:absolute;z-index:1000;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:12px;box-shadow:0 4px 16px rgba(0,0,0,0.15);min-width:260px;">
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label style="display:flex;flex-direction:column;gap:2px;font-size:0.8rem;color:var(--text-muted);">
                            任务
                            <input type="text" id="due-task-name" placeholder="任务名称" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.85rem;font-family:var(--font-ui);">
                        </label>
                        <label style="display:flex;flex-direction:column;gap:2px;font-size:0.8rem;color:var(--text-muted);">
                            开始日期
                            <input type="date" id="due-date-start" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.85rem;font-family:var(--font-ui);">
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:var(--text-muted);cursor:pointer;">
                            <input type="checkbox" id="due-date-range-toggle" onchange="toggleDueDateRange()"> 跨天任务（设置结束日期）
                        </label>
                        <div id="due-date-end-wrap" style="display:none;">
                            <label style="display:flex;flex-direction:column;gap:2px;font-size:0.8rem;color:var(--text-muted);">
                                结束日期
                                <input type="date" id="due-date-end" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.85rem;font-family:var(--font-ui);">
                            </label>
                        </div>
                        <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:4px;">
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('due-date-popover').style.display='none'">取消</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="insertDueDate()">插入</button>
                        </div>
                    </div>
                </div>
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
                    <div class="ai-section">
                        <div class="ai-section-label">修改</div>
                        <div class="ai-section-btns">
                            <button class="btn btn-sm" onclick="aiAction('polish')" title="润色文字，使表达更流畅">润色</button>
                            <button class="btn btn-sm" onclick="aiAction('translate')" title="翻译为英语">翻译</button>
                            <button class="btn btn-sm" onclick="aiAction('style')" title="切换写作风格">风格</button>
                            <button class="btn btn-sm" onclick="aiAction('format')" title="将纯文本转换为结构化 Markdown">格式化</button>
                        </div>
                    </div>
                    <div class="ai-section">
                        <div class="ai-section-label">理解</div>
                        <div class="ai-section-btns">
                            <button class="btn btn-sm" onclick="aiAction('explain')" title="解释选中的专有名词和术语">解释</button>
                            <button class="btn btn-sm" onclick="aiAction('summary')" title="生成文章摘要">摘要</button>
                            <button class="btn btn-sm" onclick="aiHighlights()" title="提取文章金句">金句</button>
                        </div>
                    </div>
                    <div class="ai-section">
                        <div class="ai-section-label">辅助</div>
                        <div class="ai-section-btns">
                            <button class="btn btn-sm" onclick="aiContinue()" title="从光标处续写下一段">续写</button>
                            <button class="btn btn-sm" onclick="aiSuggestTitle()" title="AI 推荐标题">标题</button>
                            <button class="btn btn-sm" onclick="aiSuggestTags()" title="AI 推荐标签">标签</button>
                        </div>
                    </div>
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

    <!-- 表格编辑器弹窗 -->
    <div class="modal" id="table-editor-modal" style="display:none">
        <div class="modal-overlay" onclick="closeTableEditor()"></div>
        <div class="modal-card" style="max-width:95vw;width:auto;min-width:600px;">
            <h2>表格编辑器</h2>
            <div class="table-editor-toolbar" id="table-editor-toolbar">
                <button type="button" onclick="tblAddRow()" title="在当前行下方插入一行（未选中则在末尾追加）">+行</button>
                <button type="button" onclick="tblAddCol()" title="在当前列右侧插入一列（未选中则在末尾追加）">+列</button>
                <button type="button" onclick="tblDelRow()" title="删除当前行（未选中则删除最后一行）">-行</button>
                <button type="button" onclick="tblDelCol()" title="删除当前列（未选中则删除最后一列）">-列</button>
                <span class="toolbar-sep"></span>
                <button type="button" onclick="tblMergeCells()" title="合并选中单元格">合并</button>
                <button type="button" onclick="tblSplitCell()" title="拆分选中单元格">拆分</button>
                <span class="toolbar-sep"></span>
                <button type="button" onclick="tblToggleHeader()" title="切换表头行">表头</button>
                <button type="button" onclick="tblSetAlign('left')" title="左对齐">≡</button>
                <button type="button" onclick="tblSetAlign('center')" title="居中">≡</button>
                <button type="button" onclick="tblSetAlign('right')" title="右对齐">≡</button>
                <span class="toolbar-sep"></span>
                <button type="button" onclick="tblSelectCol()" title="全选当前列">选列</button>
                <button type="button" onclick="tblSelectRow()" title="全选当前行">选行</button>
            </div>
            <div class="table-editor-formula-bar">
                <span class="tbl-formula-label">fx</span>
                <input type="text" id="tbl-formula-input" placeholder="=SUM(A1:A4) 或输入自然语言，如『求这列的平均值』" onkeydown="if(event.key==='Enter')tblEvalFormula()">
                <button type="button" class="btn btn-sm btn-primary" onclick="tblEvalFormula()">计算</button>
                <span class="tbl-nl-hint" title="可直接用自然语言描述计算需求，如『算一下这列的总和』『这行最大的是多少』">自然语言可用</span>
                <span id="tbl-formula-result" style="font-size:0.78rem;color:var(--accent);margin-left:8px;display:none;"></span>
            </div>
            <div class="table-editor-grid-wrap" id="table-editor-grid-wrap">
                <table class="table-editor-grid" id="table-editor-grid"></table>
            </div>
            <div class="modal-actions" style="margin-top:12px;display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:4px;font-size:0.78rem;color:var(--text-muted);font-family:var(--font-ui);cursor:pointer;">
                    <input type="checkbox" id="tbl-striped" onchange="tblToggleStriped()"> 条纹行
                </label>
                <div style="flex:1;"></div>
                <button type="button" class="btn" onclick="closeTableEditor()">取消</button>
                <button type="button" class="btn btn-primary" onclick="insertTableFromEditor()">插入表格</button>
            </div>
        </div>
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
    window.editorMode = 'default';
    document.getElementById('article-content').addEventListener('mouseup', updateAIReference);
    document.getElementById('article-content').addEventListener('keyup', updateAIReference);
    </script>
</div>
<?php endif; ?>
