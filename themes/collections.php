<div class="page-header">
    <h1>合辑</h1>
    <button class="btn btn-primary" onclick="showCreateCollection()">创建合辑</button>
</div>

<div class="collections-grid" id="collections-grid">
    <div class="empty-state"><p>加载中...</p></div>
</div>

<!-- 创建/编辑合辑弹窗 -->
<div class="modal" id="collection-modal" style="display:none">
    <div class="modal-overlay" onclick="closeCollectionModal()"></div>
    <div class="modal-card">
        <h2 id="coll-modal-title">创建合辑</h2>
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
