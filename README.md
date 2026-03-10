# EasyNote

A minimalist web notepad with API access, encryption support, and Markdown rendering.

## Features

- 📝 **Instant Notes** — Access any note by URL (`/my-note`)
- 🔌 **API Access** — JSON/text API for AI and programmatic access
- 🔒 **Encryption** — Per-note AES-256-CBC password protection
- 👁️ **Read-Only Mode** — Share notes that anyone can read, only password holders can edit
- 📖 **Markdown** — Toggle rendered Markdown preview
- 💾 **Auto-Save** — Content saved 1.5s after last keystroke
- ⌨️ **Shortcuts** — `Ctrl+S` save, `Ctrl+M` markdown, `Tab` indent
- 🌐 **i18n** — English and Simplified Chinese, switchable via UI
- 🎨 **Zen-iOS Hybrid UI** — Frosted glass, cold gray, tactile feedback
- 📦 **Zero Dependencies** — No database, no CDN, all assets localized

## Requirements

- PHP 7.4+ with OpenSSL extension
- Apache with `mod_rewrite` **or** Nginx
- **Or** Docker (recommended)

## Docker Deployment

### Quick Start

```bash
docker run -d --name easynote \
  -p 9933:80 \
  -v $(pwd)/data:/var/www/html/_notes \
  ghcr.io/wang4386/easynote:latest
```

Or use Docker Hub mirror:

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

Default port: `9933`. Notes are persisted in `./data/`.

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `EASYNOTE_TITLE` | `EasyNote` | Site title |
| `EASYNOTE_LANG` | `zh` | Default language (`en` / `zh`) |
| `EASYNOTE_API` | `true` | Enable/disable API access |

## Manual Installation

1. Clone or copy files to your web server directory
2. Ensure `_notes/` directory is writable: `chmod 755 _notes/`
3. Enable `mod_rewrite` (Apache) or configure URL rewriting (Nginx)
4. Visit your site!

### Nginx Configuration

```nginx
location / {
    try_files $uri $uri/ /index.php?note=$uri&$args;
}

location ~ ^/_notes/ {
    deny all;
}
```

## Usage

| Action | URL |
|--------|-----|
| Home page | `/` |
| Open/create note | `/my-note` |
| API read (JSON) | `/api/my-note` |
| API read (text) | `/api/my-note?raw=1` |
| API write | `POST /api/my-note` |

## API

### Read Note

```bash
# JSON response
curl https://your-site.com/api/my-note

# Plain text
curl https://your-site.com/api/my-note?raw=1

# Encrypted note
curl -H "X-Password: secret" https://your-site.com/api/my-note
```

**JSON Response:**
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

### Write Note

```bash
# JSON body
curl -X POST -H "Content-Type: application/json" \
  -d '{"content":"Hello from API"}' \
  https://your-site.com/api/my-note

# With encryption
curl -X POST -H "Content-Type: application/json" \
  -d '{"content":"Secret note","password":"my-pass"}' \
  https://your-site.com/api/my-note

# Raw text body
curl -X POST -d "Hello from API" \
  https://your-site.com/api/my-note
```

## Configuration

Edit `config.php`:

| Variable | Default | Description |
|----------|---------|-------------|
| `$data_dir` | `_notes/` | Notes storage directory |
| `$site_title` | `EasyNote` | Site title |
| `$default_lang` | `zh` | Default language (`en` or `zh`) |
| `$allow_api` | `true` | Enable/disable API |

### Language Switching

- Set `$default_lang` in `config.php` to configure the default language
- Users can switch languages via the globe button (🌐) in the UI
- Language preference is saved in a cookie for 30 days
- You can also switch via URL parameter: `?lang=en` or `?lang=zh`

### Note Protection

Click the lock icon (🔒) in the editor to choose a protection mode:

| Mode | Behavior |
|------|----------|
| **Encrypt** | Password required to view AND edit. Content is AES-256-CBC encrypted. |
| **Read-Only** | Anyone can view. Password required to edit. Content stored as plain text. |

- Read-only password is stored as a bcrypt hash in `_notes/{note}.meta`
- Visitors see a yellow banner and a locked editor; click the banner to enter the password
- After unlocking, the owner can edit and optionally remove the protection

## License

MIT

