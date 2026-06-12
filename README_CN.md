# My Paper

> [English](README.md)

轻量级自托管个人日记与写作平台。纯文件存储，无需数据库。

## 功能特性

- **零数据库** — 所有数据以 JSON 文件存储，仅需 PHP 即可部署
- **Markdown 编辑器** — 三栏布局，实时预览，工具栏辅助
- **AI 助手** — 集成 DeepSeek，支持润色、翻译、解释、摘要、续写等
- **文章自引** — 输入 `@` 快速搜索并引用自己的文章
- **合辑** — 将文章归入合辑，支持邀请协作者共同管理
- **导出** — 单篇或批量导出为 Markdown / ZIP，图片可一并打包
- **分享** — 生成分享链接，支持密码保护
- **洞见** — AI 驱动的写作分析：情感追踪、周期性总结、写作习惯统计
- **多用户** — 邀请码注册，管理员可管理用户
- **LaTeX 支持** — 使用 KaTeX 渲染数学公式
- **图片工具** — 内置裁剪、粘贴上传、拖拽上传
- **草稿** — 服务端每 30 秒自动保存
- **移动端适配** — 响应式设计，移动端标签切换

## 界面展示

### 首页
![首页](public/img/screenshots/首页.png)

### 编辑器
![编辑器](public/img/screenshots/文章编辑页.png)

### 文章内容
![文章内容](public/img/screenshots/文章内容页.png)

### 站内
![站内](public/img/screenshots/站内页.png)

### 洞见分析
![洞见分析](public/img/screenshots/洞见分析页.png)

### 导出
![导出](public/img/screenshots/书本导出.png)

### 设置
![设置](public/img/screenshots/设置页.png)

### 用户管理
![用户管理](public/img/screenshots/用户管理页.png)

## 环境要求

- PHP 7.4 或更高版本
- PHP 扩展：`json`、`mbstring`、`zip`（导出功能可选）
- 支持 URL 重写的 Web 服务器（Apache mod\_rewrite 或 Nginx）
- [DeepSeek API Key](https://platform.deepseek.com/)（AI 功能可选）

## 快速开始

1. 将项目复制到 Web 服务器目录：

```bash
git clone https://github.com/ZengVeijie/my-paper.git
```

2. 确保 `data/` 和 `export/` 目录对 Web 服务器可写：

```bash
chmod -R 755 data export
```

3. 编辑 `config.php`，设置管理员账号和 DeepSeek API Key：

```php
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', 'admin123'); // 请修改此密码！
define('DEEPSEEK_API_KEY', '');                // 可选
```

4. 配置 Web 服务器将所有请求路由到 `index.php`。

### Apache

项目内置路由系统。确保启用 `mod_rewrite` 并设置 `AllowOverride All`。

### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

5. 打开网站，使用管理员账号登录。首次访问时管理员账号会自动创建。

## 子目录部署

如需部署在子目录下（如 `https://example.com/mypaper/`），无需额外配置——应用会自动从 `SCRIPT_NAME` 检测基路径。

## 项目结构

```
mypaper/
├── config.php          # 站点配置
├── index.php           # 入口文件 & 路由
├── router.php          # 路由定义
├── lib/                # 后端逻辑
│   ├── ai.php          # DeepSeek AI 集成
│   ├── articles.php    # 文章增删改查
│   ├── auth.php        # 认证与用户管理
│   ├── backup.php      # 导出 & 分享
│   ├── helpers.php     # 工具函数
│   ├── notifications.php
│   └── router.php      # 路由类
├── themes/             # PHP 视图模板
├── public/             # 静态资源
│   ├── css/
│   └── js/
├── data/               # JSON 数据存储（自动创建）
│   ├── articles/
│   ├── users/
│   ├── collections/
│   ├── uploads/
│   └── ...
└── export/             # 导出文件
```

## 开源协议

MIT
