    </main>

    <!-- 分享弹窗 -->
    <div class="modal" id="share-modal" style="display:none">
        <div class="modal-overlay" onclick="closeShareModal()"></div>
        <div class="modal-card">
            <h2>生成分享链接</h2>
            <form id="share-form" onsubmit="createShare(event)">
                <input type="hidden" id="share-ids">
                <label class="field">
                    <span>访问密码（可选）</span>
                    <input type="text" id="share-password" placeholder="留空则无需密码">
                </label>
                <label class="field">
                    <span>过期时间（可选）</span>
                    <input type="datetime-local" id="share-expires">
                </label>
                <label class="field">
                    <span style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" id="share-comments"> 公开留言
                    </span>
                </label>
                <div class="modal-actions">
                    <button type="button" class="btn" onclick="closeShareModal()">取消</button>
                    <button type="submit" class="btn btn-primary">生成链接</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= u('/public/js/app.js') ?>"></script>
</body>
</html>
