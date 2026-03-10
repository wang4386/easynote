# EasyNote

一个极简的在线记事本，支持 API 访问、加密保护和 Markdown 渲染。

## 功能特性

- 📝 **即刻笔记** — 通过 URL 访问任意笔记 (`/my-note`)
- 🔌 **API 接口** — JSON/纯文本 API，方便 AI 或程序化调用
- 🔒 **加密保护** — 逐条笔记 AES-256-CBC 密码加密
- 👁️ **只读模式** — 分享笔记给他人阅读，仅密码持有者可编辑
- 📖 **Markdown** — 一键切换 Markdown 渲染预览
- 💾 **自动保存** — 停止键入 1.5 秒后自动保存
- ⌨️ **快捷键** — `Ctrl+S` 保存 / `Ctrl+M` Markdown / `Tab` 缩进
- 🌐 **多语言** — 支持中文和英文界面切换
- 🎨 **Zen-iOS 混合 UI** — 磨砂玻璃、冷灰色调、触觉反馈
- 📦 **零依赖** — 无需数据库、无需 CDN，所有资源本地化

## 系统要求

- PHP 7.4+ 且启用 OpenSSL 扩展
- Apache 启用 `mod_rewrite` **或** Nginx
- **或** Docker（推荐）

## Docker 部署

### 快速启动

```bash
docker run -d --name easynote \
  -p 9933:80 \
  -v $(pwd)/data:/var/www/html/_notes \
  ghcr.io/wang4386/easynote:latest
```

或使用 Docker Hub 镜像：

```bash
docker run -d --name easynote \
  -p 9933:80 \
  -v $(pwd)/data:/var/www/html/_notes \
  qninq/easynote:latest
```

### Docker Compose

```bash
curl -O https://raw.githubusercontent.com/wang4386/easynote/main/docker-compose.yml
docker compose up -d
```

默认端口：`9933`。笔记数据持久化在 `./data/` 目录。

### 环境变量

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `EASYNOTE_TITLE` | `EasyNote` | 站点标题 |
| `EASYNOTE_LANG` | `zh` | 默认语言（`en` / `zh`） |
| `EASYNOTE_API` | `true` | 启用/禁用 API 访问 |

## 手动安装

1. 将文件克隆或复制到 Web 服务器目录
2. 确保 `_notes/` 目录可写：`chmod 755 _notes/`
3. 启用 `mod_rewrite`（Apache）或配置 URL 重写（Nginx）
4. 访问你的站点即可使用！

### Nginx 配置

```nginx
location / {
    try_files $uri $uri/ /index.php?note=$uri&$args;
}

location ~ ^/_notes/ {
    deny all;
}
```

## 使用方式

| 操作 | URL |
|------|-----|
| 首页 | `/` |
| 打开/创建笔记 | `/my-note` |
| API 读取（JSON） | `/api/my-note` |
| API 读取（纯文本） | `/api/my-note?raw=1` |
| API 写入 | `POST /api/my-note` |

## API 接口

### 读取笔记

```bash
# JSON 格式
curl https://your-site.com/api/my-note

# 纯文本
curl https://your-site.com/api/my-note?raw=1

# 加密笔记
curl -H "X-Password: secret" https://your-site.com/api/my-note
```

**JSON 响应示例：**
```json
{
  "note": "my-note",
  "content": "Hello, World!",
  "exists": true,
  "encrypted": false,
  "length": 13,
  "modified": "2025-01-01T12:00:00+00:00"
}
```

### 写入笔记

```bash
# JSON 请求体
curl -X POST -H "Content-Type: application/json" \
  -d '{"content":"通过 API 写入"}' \
  https://your-site.com/api/my-note

# 带加密
curl -X POST -H "Content-Type: application/json" \
  -d '{"content":"加密笔记","password":"my-pass"}' \
  https://your-site.com/api/my-note

# 纯文本请求体
curl -X POST -d "通过 API 写入" \
  https://your-site.com/api/my-note
```

## 配置说明

编辑 `config.php`：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `$data_dir` | `_notes/` | 笔记存储目录 |
| `$site_title` | `EasyNote` | 站点标题 |
| `$default_lang` | `zh` | 默认语言 (`en` 或 `zh`) |
| `$allow_api` | `true` | 启用/禁用 API 访问 |

### 语言切换

- 在 `config.php` 中设置 `$default_lang` 指定默认语言
- 用户可通过页面上的语言切换按钮（🌐）手动切换
- 语言偏好通过 Cookie 保存 30 天
- 也可通过 URL 参数切换：`?lang=en` 或 `?lang=zh`

### 笔记保护

点击编辑器中的锁图标（🔒）选择保护方式：

| 模式 | 行为 |
|------|------|
| **加密** | 需要密码才能查看和编辑。内容以 AES-256-CBC 加密存储。 |
| **只读锁定** | 任何人可查看，需要密码才能编辑。内容以明文存储。 |

- 只读密码以 bcrypt 哈希存储在 `_notes/{note}.meta` 文件中
- 访客看到黄色提示条和锁定的编辑器，点击提示条输入密码即可解锁
- 解锁后可编辑内容，也可再次点击锁图标移除只读保护

## 开源协议

MIT
