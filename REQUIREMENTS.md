# My Paper — 需求文档

## 1. 项目概述

**项目名称**：My Paper
**技术栈**：PHP + JSON + Markdown
**运行环境**：VSCode 内置终端，PHP 内置服务器（`php -S localhost:8000`）
**目标**：一个轻量、私密、支持多用户、AI 辅助写作的个人日记/随笔空间，兼顾桌面端与移动端体验。

---

## 2. 技术架构

### 2.1 后端
- **语言**：PHP 8.x（仅使用内置函数，无第三方框架依赖）
- **数据存储**：JSON 文件（扁平文件系统，无数据库依赖，便于备份和迁移）
- **路由**：单一入口 `index.php` + 简单 URI 路由解析
- **图片/文件上传**：`$_FILES` 处理，存储于 `data/uploads/` 目录
- **认证**：Session 机制 + 密码哈希（`password_hash` / `password_verify`）

### 2.2 前端
- **Markdown 渲染**：使用 marked.js（客户端渲染）
- **编辑器**：CodeMirror 6 作为编辑区，左侧编辑 + 右侧实时预览的分栏布局
- **代码高亮**：highlight.js
- **响应式布局**：纯 CSS（Flexbox + Grid + Media Queries），不依赖 UI 框架
- **无 emoji**：不引入 emoji 库，不使用 emoji 字符

### 2.3 目录结构（预期）
```
/
├── index.php                 # 入口 & 路由
├── config.php                # 站点配置（站点名、默认管理员等）
├── lib/                      # PHP 核心逻辑
│   ├── auth.php              # 登录认证、用户管理、邀请码
│   ├── router.php            # 路由解析
│   ├── articles.php          # 文章 CRUD + 留言
│   ├── backup.php            # 数据备份/导出 + 分享 + 合辑 PDF
│   ├── ai.php                # DeepSeek API 封装（AI 后台）
│   ├── insights_apps.php     # 洞见应用注册中心 & 工具函数
│   ├── auth.php              # 认证与用户管理
│   ├── notifications.php     # 通知系统
│   ├── router.php            # 路由类
│   └── helpers.php           # 通用工具函数
├── public/                   # 前端静态资源
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   ├── app.js            # 主逻辑
│   │   └── editor.js         # 编辑器 + AI 助手
│   └── components/           # HTML 片段（PHP include）
├── data/                     # JSON 数据存储
│   ├── users/                # 用户 JSON 文件
│   ├── articles/             # 文章 JSON 文件
│   ├── collections/          # 合辑 JSON 定义
│   ├── comments/             # 留言 JSON 文件
│   ├── invites/              # 邀请码 JSON 文件
│   ├── shares/               # 分享链接 JSON
│   ├── insights_apps/        # 自定义/AI 生成洞见应用 JSON
│   ├── config.json           # 站点可写配置
│   └── uploads/              # 上传文件存储
├── themes/                   # 渲染模板
└── export/                   # 临时导出目录
```

---

## 3. 功能模块

### 3.1 多用户登录系统

- [ ] **首次部署**：读取 `config.php` 中的预设管理员账号密码，首次运行时自动创建。登录后可修改密码。
- [ ] **用户角色**：
  - **管理员**（admin）：可管理所有用户、生成邀请码、查看全部文章
  - **普通用户**（user）：仅管理自己的内容
- [ ] **注册方式 — 邀请码制**：
  - 管理员在设置页/用户管理页生成邀请码
  - 邀请码可设置：有效期、最大使用次数、备注（发给谁的）
  - 新用户访问注册页，输入邀请码 + 用户名 + 密码完成注册
  - 邀请码列表可查看（已用/剩余次数、过期状态）
  - 不支持公开注册
- [ ] **登录页面**：简洁居中表单，用户名 + 密码
- [ ] **Session 管理**：登录态保持 7 天，支持手动登出
- [ ] **安全措施**：
  - 密码使用 bcrypt 哈希存储
  - 登录失败 5 次后临时锁定 15 分钟
  - 所有管理 API 校验 Session
  - 用户只能操作自己的文章、合辑、留言
- [ ] **用户管理（管理员）**：用户列表、启用/禁用账号、重置密码、删除用户

### 3.2 首页
- [x] **首页展示模式**：用户可在设置中选择三种展现形式：
  - 合辑 + 文章（默认）：合辑区在上，文章区在下
  - 仅展示合辑：隐藏文章区
  - 仅展示文章：隐藏合辑区，与传统首页一致
- [x] **合辑展示**：首页顶部以卡片网格展示用户的合辑（自己创建 + 协作的），每张卡片显示封面、名称、描述摘要、文章数
- [x] **文章列表**：仅展示当前登录用户自己的文章，按时间倒序排列
- [x] **列表项信息**：标题、日期、摘要（前 200 字符）、标签、所属合辑、可见性标识
- [x] **搜索**：全文搜索（按标题、内容、标签关键字），合辑（按名称、描述），搜索框始终可见
- [ ] **筛选**：按合辑、标签、可见性状态筛选
- [x] **分页**：分页链接
- [ ] **置顶功能**：支持将重要文章置顶，置顶文章带视觉标识
- [ ] **快速操作**：每篇文章可快速切换可见性、添加到合辑、导出

### 3.3 文章编辑器
- [ ] **分栏实时预览**：左侧 CodeMirror 6 编辑区 + 右侧 marked.js 实时渲染预览，同步滚动
- [ ] **工具栏**（编辑区顶部）：
  - 标题层级（H1-H4）
  - 加粗 / 斜体 / 删除线
  - 无序列表 / 有序列表 / 任务列表
  - 引用块
  - 代码块（带语言选择）
  - 链接 / 图片插入
  - 分割线
  - 表格插入辅助
- [ ] **图片上传**：拖拽或粘贴上传，自动插入 Markdown 图片语法
- [ ] **文件上传**：支持 PDF、Word、TXT 等常见格式上传，文章中显示为可点击的下载/预览链接
- [ ] **文件预览**：
  - 图片：灯箱预览
  - PDF：内嵌预览或新标签打开
  - TXT/MD：内嵌阅读
  - 其他格式：提供下载链接
- [ ] **自动保存**：每 30 秒自动保存草稿到 localStorage，编辑器打开时提示恢复
- [ ] **元数据编辑**：标题、所属合辑、标签、可见性、创建日期
- [ ] **移动端适配**：小屏自动切换为单栏，编辑/预览通过 tab 切换

### 3.4 文章可见性（三态）
- [ ] **仅自己可见**（private）：默认状态，仅作者本人可见
- [ ] **站内可见**（internal）：所有已登录用户均可阅读，但不可被分享到站外
- [ ] **已分享**（shared）：文章已生成公开分享链接，任何人可通过链接访问
- [ ] **可见性切换**：文章列表和编辑器中可直接切换，API 校验仅作者可修改
- [ ] **列表标识**：不同可见性用不同图标/颜色标识（简洁文字，无 emoji）

### 3.5 文章留言（嵌套回复）
- [x] **留言区域**：文章详情页桌面端右侧侧边栏展示留言（320px 宽，sticky 定位），移动端在文章下方全宽展示
- [x] **发表留言**：登录用户可对任意可见文章发表留言；移动端留言输入框固定底部
- [x] **嵌套回复**：仅支持两层（顶级留言 + 一级回复），禁止对回复再回复；前端深度 >=1 隐藏回复按钮，后端 API 拒绝三层回复
- [x] **留言内容**：支持简易 Markdown（链接、粗体、斜体、代码片段）
- [x] **留言管理**：
  - 文章作者可删除文章下任意留言及子回复
  - 留言者仅可删除自己的留言
- [x] **留言通知**：他人在你的文章下留言或回复你的留言时，首页导航出现通知红点
- [x] **留言排序**：默认按时间正序，新回复的楼层会冒到顶部
- [ ] **@提及**：回复中支持 @用户名，被提及的用户收到通知

### 3.6 合辑 & 协作
- [ ] **合辑管理页面**：创建、编辑、删除合辑（仅合辑所有者可删除）
- [x] **合辑封面**：支持从本地上传封面图片，或输入 URL；封面在合辑详情页展示
- [ ] **合辑描述**：支持 Markdown 描述
- [ ] **排序**：合辑内文章支持拖拽排序 / 手动排序
- [ ] **合辑协作**：
  - 合辑所有者可在合辑设置中邀请其他用户协作
  - 协作者可将自己的文章加入合辑、调整排序
  - 协作者不可删除合辑或移除他人文章
  - 合辑列表显示协作者头像
- [ ] **导出为书（PDF）**：
  - 合辑详情页提供"导出为书"按钮
  - **封面**：合辑封面图 + 合辑名称 + 作者 + 日期，可预览和调整封面样式
  - **目录**：自动生成带页码的章节目录（以文章标题为章节）
  - **正文**：每篇文章独立成章，Markdown 渲染后排版，图片/图表正常展示
  - **页眉页脚**：页眉显示书名，页脚显示页码
  - **导出前预览**：在浏览器中预览整本书的排版效果（打印样式），确认后导出 PDF
  - **移动端**：仅提供下载，预览为简化版
- [ ] **分类标签**：每篇文章支持多个标签，标签云展示在侧边栏或首页

### 3.7 文章分享
- [x] **生成分享链接**：通过 API 创建分享，返回 `/share/{code}` 短链接，而非直接分享 `/article/{id}`（需要登录）
- [x] **分享页面**：
  - 独立于登录系统的公开页面，完全自包含（不依赖站点 CSS/JS）
  - 简洁的阅读布局（居中窄栏、衬线字体、暖白背景），不暴露后台功能
  - 留言区可选择是否对公开分享可见
  - 可设置密码保护（独立简洁的密码输入页）
  - 可设置过期时间（过期页面独立设计）
  - 文章正文使用 marked.js 渲染 Markdown
- [x] **批量分享**：选择多篇文章生成一个分享页面
- [x] **撤回分享**：在设置页管理并撤销分享链接；撤销后文章可见性自动恢复
- [x] **分享可见性联动**：生成分享链接后文章自动标记为"已分享"；撤回后恢复为之前的状态
- [x] **404 页面**：自包含设计，不依赖站点 CSS

### 3.8 DeepSeek API 集成
- [x] **API 配置**：管理员可配全局 Key，用户也可在设置页配个人 Key（优先使用个人 Key）
- [x] **AI 助手位置**：桌面端位于编辑器右侧（三栏结构：编辑器 | 预览 | AI），移动端位于编辑器下方，可折叠/展开
- [x] **功能列表**：
  - AI 面板按钮分为三个分区卡片：**修改**（润色/翻译/风格/格式化）、**理解**（解释/摘要/金句）、**辅助**（续写/标题/标签）
  - **翻译**（`/api/ai/translate`）：中→英翻译，结果展示后可选择替换选中文字或追加到文末
  - **润色**（`/api/ai/polish`）：优化文笔表达，先展示 AI 生成的结果，用户确认后再替换
  - **风格切换**（`/api/ai/style`）：支持文学优美、简洁精炼、学术严谨、随笔随性、口语化五种风格，弹窗选择
  - **文章摘要**（`/api/ai/summary`）：自动生成文章摘要，可点击按钮一键设为摘要
  - **MD 格式化**（`/api/ai/format`）：将纯文本（仅换行）自动转换为结构化 Markdown（章节标题、分段、列表、加粗、引用等）
  - **语义搜索**（`/api/ai/search`）：AI 根据自然语言描述从文章库中检索最相关的文章
  - **知识问答**（`/api/ai/chat`）：以当前文章内容为上下文，自由对话提问
- [x] **选中文字引用**：选中文字后 AI 面板顶部显示已选中字数及文字预览，操作明确针对选中内容
- [x] **结果预览确认**：AI 返回结果后先展示在对话区，用户查看后再选择"替换选中文字"、"替换全文"或"追加到文末"（而非盲选 confirm）
- [x] **文章阅读 AI 查询**：在文章详情页选中文字后，弹出 AI 查询窗口，可针对选中文字进行简单提问
- [x] **多篇文章 AI 问答**（`/api/ai/query-articles`）：在首页或合辑页勾选多篇文章后点击"AI 提问"，基于选中文章内容回答用户问题
- [x] **移动端选中文字保留**：切换标签页时保存 textarea 选区状态，避免切换后选中丢失
- [x] **调用方式**：
  - 选中文字后点击 AI 面板中的功能按钮（针对选中内容）
  - 全文操作（摘要、格式化等）直接作用于整篇文章
  - 自由对话框提问（知识问答、智能挑选）

### 3.9 备份与导出
- [ ] **手动备份入口**：位于设置页及文章列表页（支持勾选多篇文章后批量操作）
- [ ] **三种导出模式**：
  - **单篇导出**：文章详情页"导出 .md"按钮，直接下载单篇 Markdown 文件（文件名 `{文章标题}.md`）
  - **批量导出**：文章列表中勾选多篇后点击"导出选中"，打包为 ZIP 下载
  - **合辑导出为书**：合辑详情页"导出为书"按钮，生成带封面、目录、正文的 PDF（详见 3.6 节）
- [ ] **批量导出可选范围**：
  - 勾选多篇文章（自由多选，跨合辑）
  - 导出指定合辑全部文章（可选择 .md ZIP 或 PDF 书形式）
  - 导出我的全部数据（文章、合辑、留言、上传文件）
  - 导出全部数据（所有用户数据，仅管理员）
- [ ] **导出格式**：
  - 文章：Markdown（.md），保留 YAML frontmatter
  - 合辑：可选 .md ZIP 包或 PDF 书
- [ ] **ZIP 打包结构**：按合辑分文件夹，未归类文章在根目录，图片路径自动调整为相对路径
- [ ] **导出文件命名**：`My Paper_export_{日期}.zip` 或 `{合辑名}.pdf`

### 3.10 待办清单系统
- [x] **编辑器清单按钮**：工具栏 `☑` 按钮，选中多行可批量转换为 `- [ ] ` 格式
- [x] **文章页核销交互**：仅文章作者可点击 checkbox 切换完成/未完成状态
  - 完成时自动追加 `(完成于 YYYY-MM-DD HH:mm)` 时间戳
  - 取消完成时移除时间戳
  - 行级精确匹配，通过 `/api/articles/{id}/toggle-task` API 更新
  - 非作者看到的 checkbox 为 disabled 状态
- [x] **文章卡片进度**：首页文章卡片显示任务进度条（n/m 任务 + 进度条），全部完成时显示"全部完成"
- [x] **待办纵览面板**：洞见页面内置应用，汇总所有文章中的待办事项，按文章分组显示，标注完成状态和日期

### 3.11 洞见应用系统
- [x] **动态应用架构**：洞见页面从 4 个硬编码 tab 重构为可扩展的应用面板系统
- [x] **内置应用**（8 个）：
  - 情感分析 — AI/手动标记文章情感基调，筛选和统计
  - 相关回顾 — 选择文章，AI 查找历史中主题相关的内容
  - 周月总结 — 选择时间范围，AI 生成回顾总结
  - 写作统计 — 总览/时段/月度/情感/标签等统计图表 + AI 洞察
  - 待办纵览 — 汇总所有文章待办事项
  - MBTI 分析 — AI 从四维度分析日记推断人格类型，引用证据
  - CBT 疗法 — AI 识别认知扭曲，给出 CBT 干预建议
  - 盲区探索 — AI 发现 3 个用户未意识的隐藏真相
- [x] **应用仓库**（设置页新增"App 仓库"标签）：
  - 启用/禁用应用（控制洞见页展示）
  - 拖拽排序（上下移动调整洞见页 tab 顺序）
  - 两级删除逻辑：
    - "从洞见移除"：从用户启用列表中移除，应用仍在仓库
    - "从仓库删除"：永久删除应用 JSON + 清理所有用户启用状态（仅自定义/AI 应用可删，内置应用不可删）
- [x] **AI 生成应用**：用户描述需求 → DeepSeek 生成完整应用定义（名称/描述/HTML+JS 模板）→ 存入仓库 → 可添加到洞见页
- [x] **渲染模式**：内置应用使用 PHP 模板（`render_type: php`），AI 应用使用存储的 HTML/JS 模板（`render_type: js`）

### 3.12 响应式适配
- [x] **桌面端（≥768px）**：
  - 左侧固定导航栏：首页 → 写文章 → 合辑 → 洞见 → 站内 → 收藏 → 用户管理（仅管理员）
  - 侧边栏底部显示用户名（可点击跳转设置页）和退出链接
  - 桌面端隐藏内容区页面标题 H1（侧边栏已有当前页高亮）
  - 编辑器分栏（左编辑 / 中预览 / 右 AI）
- [x] **移动端（<768px）**：
  - 底部导航栏（首页、写文章、合辑、设置）+ 汉堡菜单
  - 编辑器切换为单栏标签式（编辑 / 预览 / AI），底部固定操作栏（上传 + 标签切换 + 保存）
  - 编辑器工具栏水平可滚动，文章选项默认折叠
  - 合辑列表单列展示，文章详情两栏变上下堆叠
  - 留言输入框固定底部，适配 safe-area
  - 汉堡菜单不遮挡页面标题（打开时自动右移）
  - 编辑器标题下移避开汉堡按钮

---

## 4. UI/UX 设计原则

### 4.1 风格定位
- **配色**：暖白背景（#faf9f6）+ 深灰文字（#2c2c2c），低饱和度强调色（墨绿 #5b7b6f / 灰蓝 #6b7d8e / 深棕 #8b7355），营造安静、克制的阅读氛围
- **字体**：衬线体（思源宋体 / Georgia）用于正文，无衬线体（思源黑体 / system-ui）用于 UI
- **排版**：宽松的行高（1.8-2.0）、充足留白、窄栏阅读区（max-width: 680px 正文区）
- **无 emoji**：所有交互提示使用纯文字或简洁 SVG 图标（推荐 Feather Icons 风格）

### 4.2 页面清单
| 页面 | 路由 | 说明 |
|------|------|------|
| 登录页 | `/login` | 居中表单，极简设计 |
| 注册页 | `/register` | 邀请码 + 用户名 + 密码，无邀请码无法注册 |
| 首页 | `/` | 仅展示当前用户自己的文章列表 |
| 编辑器 | `/write` 或 `/edit/{id}` | 三栏布局（编辑 | 预览 | AI），可折叠右侧面板 |
| 文章详情 | `/article/{id}` | 含嵌套留言区 |
| 合辑列表 | `/collections` | 我的合辑 + 我协作的合辑 |
| 合辑详情 | `/collection/{id}` | 合辑内文章列表，协作管理入口 |
| 设置页 | `/settings` | API Key、密码修改、备份导出 |
| 用户管理 | `/admin/users` | 用户列表 + 邀请码管理（仅管理员） |
| 分享页 | `/share/{code}` | 公开阅读页，无需登录 |

---

## 5. API 设计概览

所有 API 前缀 `/api/`，返回 JSON。

### 5.1 认证 & 用户
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/auth/login` | 登录 |
| POST | `/api/auth/logout` | 登出 |
| POST | `/api/auth/register` | 注册（需有效邀请码） |
| GET | `/api/auth/me` | 获取当前用户信息 |
| PUT | `/api/auth/password` | 修改密码 |
| PUT | `/api/auth/profile` | 修改个人信息（display_name 等） |
| GET | `/api/admin/users` | 用户列表（管理员） |
| PUT | `/api/admin/users/{id}` | 更新用户状态（管理员） |
| DELETE | `/api/admin/users/{id}` | 删除用户（管理员） |
| POST | `/api/admin/invites` | 生成邀请码（管理员） |
| GET | `/api/admin/invites` | 邀请码列表（管理员） |
| DELETE | `/api/admin/invites/{code}` | 作废邀请码（管理员） |

### 5.2 文章
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/articles` | 当前用户的文章列表（?page=&search=&collection=&tag=&visibility=） |
| GET | `/api/articles/{id}` | 文章详情（含留言列表） |
| POST | `/api/articles` | 创建文章 |
| PUT | `/api/articles/{id}` | 更新文章（含可见性切换） |
| DELETE | `/api/articles/{id}` | 删除文章 |

### 5.3 留言
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/articles/{id}/comments` | 获取文章留言（嵌套结构） |
| POST | `/api/articles/{id}/comments` | 发表留言（body 可选 parent_id 实现回复） |
| DELETE | `/api/comments/{id}` | 删除留言（级联删除子回复） |

### 5.4 合辑
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/collections` | 合辑列表（我的 + 我协作的） |
| POST | `/api/collections` | 创建合辑 |
| PUT | `/api/collections/{id}` | 更新合辑 |
| DELETE | `/api/collections/{id}` | 删除合辑（仅所有者） |
| POST | `/api/collections/{id}/collaborators` | 邀请协作者（body: {user_id}） |
| DELETE | `/api/collections/{id}/collaborators/{user_id}` | 移除协作者 |

### 5.5 上传 & 备份
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/upload` | 上传文件 |
| GET | `/api/export/{article_id}` | 导出单篇文章，直接返回 .md 文件下载 |
| POST | `/api/export/batch` | 批量导出（body: {article_ids: [...]}），返回 ZIP |
| POST | `/api/export/all` | 导出我的全部数据，返回 ZIP（管理员可选全部用户） |
| GET | `/api/export/collection/{id}/pdf` | 合辑导出为 PDF 书（封面+目录+正文） |
| GET | `/api/export/collection/{id}/preview` | 合辑 PDF 浏览器预览（HTML 打印样式） |

### 5.6 分享
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/share` | 生成分享链接（body: {type, target_ids, password, expires_at, show_comments}） |
| DELETE | `/api/share/{code}` | 撤销分享 |

### 5.7 AI
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/ai/chat` | AI 自由对话（body: {question, article_content?}） |
| POST | `/api/ai/polish` | AI 润色（body: {text}） |
| POST | `/api/ai/translate` | AI 翻译为英语（body: {text}） |
| POST | `/api/ai/style` | AI 风格切换（body: {text, style}） |
| POST | `/api/ai/format` | AI MD 格式化（body: {text}） |
| POST | `/api/ai/summary` | AI 生成摘要（body: {text}） |
| POST | `/api/ai/search` | AI 语义搜索文章（body: {query}） |
| POST | `/api/ai/query-articles` | AI 多篇文章问答（body: {article_ids, question}） |

### 5.8 待办清单
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/articles/{id}/toggle-task` | 切换单行清单状态（仅作者），body: {line_index, checked} |

### 5.9 洞见应用系统
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/insights/apps` | 获取所有可用应用（内置 + 自定义/AI） |
| PUT | `/api/insights/apps/reorder` | 更新用户启用的应用列表和排序，body: {ids: [...]} |
| POST | `/api/insights/apps/generate` | AI 生成新应用，body: {description}，返回完整应用定义 |
| DELETE | `/api/insights/apps/{id}` | 从仓库永久删除应用（仅自定义/AI），同时清理所有用户引用 |
| POST | `/api/insights/mbti` | MBTI 人格分析，body: {scope}，返回 {type, reasoning, confidence} |
| POST | `/api/insights/cbt` | CBT 认知行为疗法分析，body: {scope}，返回 {distortions, summary} |
| POST | `/api/insights/blindspot` | 盲区探索，body: {scope}，返回 {blindspots, summary} |

---

## 6. 数据模型（JSON 结构）

### 6.1 用户 (data/users/{id}.json)
```json
{
  "id": "uuid",
  "username": "weijie",
  "password_hash": "$2y$10$...",
  "display_name": "伟杰",
  "role": "admin",
  "enabled": true,
  "deepseek_api_key": "",
  "ai_max_tokens": 2048,
  "insights_apps": ["sentiment", "related", "summary", "stats"],
  "ai_templates": [],
  "favorite_article_ids": [],
  "homepage_mode": "both",
  "unread_notifications": 0,
  "created_at": "2026-06-09T12:00:00+08:00"
}
```

### 6.2 邀请码 (data/invites/{code}.json)
```json
{
  "code": "abc123def456",
  "created_by": "admin-uuid",
  "max_uses": 5,
  "used_count": 2,
  "expires_at": "2026-12-31T23:59:59+08:00",
  "note": "给小王",
  "enabled": true,
  "created_at": "2026-06-09T12:00:00+08:00"
}
```

### 6.3 文章 (data/articles/{id}.json)
```json
{
  "id": "uuid",
  "user_id": "user-uuid",
  "title": "文章标题",
  "content": "Markdown 正文",
  "summary": "摘要（可手动或AI生成）",
  "tags": ["标签1", "标签2"],
  "collection_ids": ["collection-uuid-1"],
  "visibility": "private",
  "pinned": false,
  "comment_count": 3,
  "created_at": "2026-06-09T12:00:00+08:00",
  "updated_at": "2026-06-09T12:00:00+08:00"
}
```

**visibility 取值**：`private`（仅自己可见）、`internal`（站内可见）、`shared`（已公开分享）

### 6.4 留言 (data/comments/{id}.json)
```json
{
  "id": "uuid",
  "article_id": "article-uuid",
  "parent_id": null,
  "user_id": "user-uuid",
  "user_name": "伟杰",
  "content": "这里补充一下当时的想法...",
  "created_at": "2026-06-09T12:00:00+08:00"
}
```

### 6.5 合辑 (data/collections/{id}.json)
```json
{
  "id": "uuid",
  "user_id": "user-uuid",
  "name": "合辑名称",
  "description": "合辑描述 (Markdown)",
  "cover": "uploads/cover.jpg",
  "article_ids": ["article-uuid-1", "article-uuid-2"],
  "sort_order": [2, 1],
  "collaborator_ids": ["user-uuid-2"],
  "created_at": "2026-06-09T12:00:00+08:00"
}
```

### 6.6 分享 (data/shares/{code}.json)
```json
{
  "code": "a1b2c3",
  "user_id": "user-uuid",
  "type": "article",
  "target_ids": ["article-uuid-1"],
  "password_hash": null,
  "show_comments": false,
  "expires_at": null,
  "created_at": "2026-06-09T12:00:00+08:00"
}
```

### 6.7 站点配置 (data/config.json)
```json
{
  "site_name": "My Paper",
  "global_deepseek_api_key": "",
  "deepseek_model": "deepseek-chat"
}
```

### 6.8 洞见应用 (data/insights_apps/{id}.json)
```json
{
  "id": "uuid",
  "name": "应用名称",
  "description": "功能说明",
  "icon": "🧠",
  "source": "ai",
  "render_type": "js",
  "template": "<div id='app-xxx'>...</div><script>...</script>",
  "api_spec": null,
  "user_id": "creator-uuid",
  "created_at": "2026-06-28T12:00:00+08:00"
}
```
**source 取值**：`builtin`（内置，不可删除）、`ai`（AI 生成）

**render_type 取值**：`php`（PHP 模板渲染）、`js`（存储的 HTML/JS 模板）

### 6.9 通知 (data/notifications/{user_id}.json)
```json
{
  "user_id": "user-uuid",
  "items": [
    {
      "id": "uuid",
      "type": "comment_reply",
      "message": "张三 回复了你在《xxx》的留言",
      "link": "/article/article-uuid#comment-comment-uuid",
      "read": false,
      "created_at": "2026-06-09T12:00:00+08:00"
    }
  ]
}
```

---

## 7. 开发阶段建议

### Phase 1 — 基础框架
- 项目骨架搭建、路由系统、JSON 读写工具
- 配置预设管理员初始化
- 多用户系统（登录、邀请码注册、Session 管理）
- 首页（仅展示自己的文章列表）

### Phase 2 — 编辑与内容
- 文章编辑器（CodeMirror 6 分栏实时预览）
- 图片/文件上传、文件预览
- 文章 CRUD + 三态可见性
- 嵌套留言系统 + 通知

### Phase 3 — 合辑、分享 & 导出
- 合辑管理 + 协作者邀请
- 文章/合辑分享（含密码、过期时间）
- 备份导出（单篇 .md + 批量 ZIP）

### Phase 4 — AI 集成（已完成）
- DeepSeek API 接入（`lib/ai.php`，使用 `file_get_contents` + stream context，兼容无 cURL 环境）
- AI 助手面板：桌面端右侧三栏布局，移动端下方内联，可折叠
- 翻译/润色/风格切换/摘要/MD 格式化/语义搜索/对话问答
- AI 结果预览确认机制（替换 / 追加，非盲选 confirm）
- 选中文字引用指示器
- 文章阅读页选中文字 AI 查询弹窗

### Phase 5 — 移动适配 & 打磨（进行中）
- [x] 移动端编辑器标签式切换（编辑 / 预览 / AI），底部固定操作栏
- [x] 文章详情页留言区右侧栏布局（桌面端），移动端全宽 + 固定输入框
- [x] 留言两层限制（前端 + 后端双重约束）
- [x] 文章分享按钮（Web Share API + 剪贴板回退）
- [x] 文章详情页返回按钮（根据 referrer 自动显示"返回首页"/"返回合辑"）
- [x] 文件下载修复（Content-Disposition: attachment，支持 doc/docx/xlsx/txt/webp 等格式）
- [x] 合辑封面上传（本地图片上传 + URL 输入）
- [x] 多篇文章 AI 问答（首页和合辑页批量选择文章提问）
- [x] 上传按钮改为附件图标（回形针），工具栏风格统一
- [x] 分享链接改为通过 API 创建 /share/{code}，而非直接分享需要登录的 /article/{id}
- [x] 分享页、密码页、过期页、404 页改为完全自包含设计（不依赖站点 CSS/JS）
- [x] 项目名称改为 My Paper
- [x] 数据导出 ZIP 支持纯 PHP 回退方案（无需 php_zip 扩展）
- [x] 合辑 PDF 书分页引擎：动态纸张切换 + 字号调节 + 客户端分页 + 页码标注
- [x] 静态文件 Content-Type 修复（PHP 8.4 header_remove 处理）
- [x] 分享链接修复（API 返回完整 URL，JS 不再重复拼接 origin）
- [x] 分享按钮改为直接复制链接（不再触发浏览器分享弹窗）
- [x] 导出下载改为 fetch+blob URL 方式（绕过 IDM 拦截）
- [x] 下载体验优化：加载遮罩 + 旋转指示器 + Toast 通知替代 alert 弹窗
- [x] 写作页预览占位文字改为浅灰色，预览区背景与页面背景统一
- [x] 文章摘要输入框改为 textarea（约 200 字空间）
- [x] 标签和可见性合并为同一行（桌面端），移动端保持分行
- [x] 设置页新增"页面设置"标签页，首页展示模式从个人设置移入独立面板
- [x] AI 面板按钮重组为三区卡片：修改/理解/辅助
- [x] 桌面端隐藏内容区 H1 标题（侧边栏已有当前页高亮标识）
- [x] 侧边栏用户名改为可点击链接，跳转设置页
- [x] 首页搜索框上移至标题与合辑区之间
- [x] 删除站内页和收藏页的栏目说明文字
- [x] 首页搜索同时匹配文章和合辑（名称 + 描述）
- [x] 文章待办清单系统（编辑器插入/文章页核销/卡片进度/洞见纵览）
- [x] 洞见应用系统重构（8 个内置应用 + 动态面板 + AI 生成应用）
- [x] 设置页 App 仓库（启用/禁用/排序/删除/AI 生成）
- [x] MBTI 分析 / CBT 疗法 / 盲区探索 三个 AI 应用
- [ ] 性能优化
- [ ] UI 打磨

---

## 8. 已确认的设计决策

| 事项 | 决策 |
|------|------|
| 多用户支持 | 支持，管理员 + 普通用户角色 |
| 注册方式 | 邀请码制，管理员生成，可设有效期和使用次数 |
| 首次部署 | 读取 config.php 预设管理员账号自动创建 |
| 首页可见范围 | 仅展示自己的文章 |
| 编辑器 | 分栏实时预览（左侧 CodeMirror 6 + 右侧 marked.js） |
| 文章可见性 | 三态：仅自己可见 / 站内可见 / 已分享，默认仅自己可见 |
| 留言结构 | 嵌套回复，2 层展开，超出折叠 |
| 合辑协作 | 支持邀请其他用户协作，协作者可添加自己的文章 |
| AI 助手位置 | 桌面端编辑器右侧三栏布局（编辑 | 预览 | AI），列宽可拖拽调节并记忆；移动端下方内联，可折叠 |
| 编辑器布局 | 三栏 Flex 弹性布局，填满可用宽度，栏间可拖拽调节比例，调节无跳变，比例保存到 localStorage |
| 备份导出 | 单篇 .md 直出、多选打包 ZIP、合辑可导出为 PDF 书（封面+目录+正文） |
| 自动备份 | 不做 |
| DeepSeek 用量 | 不做限制/费用展示（后续可加） |
| 前端风格 | 无 emoji，暖白+深灰+低饱和度，衬线正文，纯文字+SVG 图标 |
| 留言层级 | 仅两层（顶级留言 + 一级回复），禁止三层嵌套 |
| 留言位置 | 桌面端文章右侧侧边栏 (320px sticky)，移动端文章下方全宽 + 固定输入框 |
| 文章分享 | 使用浏览器原生 Web Share API，桌面端回退为复制链接 |
| 文章返回 | 智能识别 referrer 来源（首页/合辑），显示对应返回按钮 |
| 移动端编辑器 | 底部标签栏切换面板，仅显示当前面板，标题避开汉堡菜单 |
| AI 多篇问答 | 首页/合辑勾选多篇文章后点击"AI 提问"，弹出新窗口展示回答 |
| 文件下载 | 图片/视频/音频/字体在内联预览，其余格式 (PDF/Office/ZIP/MD) 触发下载 |
| 合辑封面 | 支持本地上传或 URL 输入，封面在详情页展示 |
| 清单核销 | 仅文章作者可切换 checkbox，行级精确匹配原始 Markdown，完成时自动附时间戳 |
| 洞见应用架构 | 动态面板系统，内置应用使用 PHP 模板渲染，AI 应用使用存储的 HTML/JS 模板 |
| 应用仓库 | 两级删除逻辑：从洞见移除（软删除，仅改用户偏好）vs 从仓库删除（硬删除，不可恢复） |
| AI 应用生成 | 用户描述需求 → DeepSeek 生成完整应用定义 → 存入仓库 → 可添加到洞见页 |
| 应用排序 | 用户 `insights_apps` 有序数组控制洞见页 tab 顺序，API 整体覆盖更新 |
