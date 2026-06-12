<?php require_admin(); ?>
<div class="page-header">
    <h1>用户管理</h1>
</div>

<div class="admin-tabs">
    <button class="tab-btn active" onclick="switchAdminTab('users')">用户列表</button>
    <button class="tab-btn" onclick="switchAdminTab('invites')">邀请码</button>
</div>

<!-- 用户列表 -->
<div class="admin-panel" id="admin-users">
    <table class="data-table" id="users-table">
        <thead>
            <tr><th>用户名</th><th>显示名称</th><th>角色</th><th>状态</th><th>注册时间</th><th>操作</th></tr>
        </thead>
        <tbody>
            <tr><td colspan="6">加载中...</td></tr>
        </tbody>
    </table>
</div>

<!-- 邀请码管理 -->
<div class="admin-panel" id="admin-invites" style="display:none">
    <div class="panel-actions">
        <button class="btn btn-primary" onclick="showCreateInvite()">生成邀请码</button>
    </div>
    <table class="data-table" id="invites-table">
        <thead>
            <tr><th>邀请码</th><th>备注</th><th>已用/总量</th><th>过期时间</th><th>状态</th><th>操作</th></tr>
        </thead>
        <tbody>
            <tr><td colspan="6">加载中...</td></tr>
        </tbody>
    </table>
</div>

<!-- 邀请码创建弹窗 -->
<div class="modal" id="invite-modal" style="display:none">
    <div class="modal-overlay" onclick="closeInviteModal()"></div>
    <div class="modal-card">
        <h2>生成邀请码</h2>
        <form id="invite-form" onsubmit="createInvite(event)">
            <label class="field">
                <span>最大使用次数</span>
                <input type="number" id="invite-max-uses" value="1" min="1" required>
            </label>
            <label class="field">
                <span>过期日期（可选）</span>
                <input type="date" id="invite-expires">
            </label>
            <label class="field">
                <span>备注</span>
                <input type="text" id="invite-note" placeholder="给谁的？">
            </label>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeInviteModal()">取消</button>
                <button type="submit" class="btn btn-primary">生成</button>
            </div>
        </form>
    </div>
</div>

<!-- 用户编辑弹窗 -->
<div class="modal" id="user-modal" style="display:none">
    <div class="modal-overlay" onclick="closeUserModal()"></div>
    <div class="modal-card">
        <h2>编辑用户</h2>
        <form id="user-form" onsubmit="updateUser(event)">
            <input type="hidden" id="edit-user-id">
            <label class="field">
                <span>显示名称</span>
                <input type="text" id="edit-user-display">
            </label>
            <label class="field">
                <span>角色</span>
                <select id="edit-user-role">
                    <option value="user">普通用户</option>
                    <option value="admin">管理员</option>
                </select>
            </label>
            <label class="field">
                <span>新密码（留空不改）</span>
                <input type="password" id="edit-user-password" minlength="6">
            </label>
            <label class="field">
                <span>状态</span>
                <select id="edit-user-enabled">
                    <option value="1">启用</option>
                    <option value="0">禁用</option>
                </select>
            </label>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeUserModal()">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>
