# url-to-pdf-api

A lightweight REST API that accepts a URL, renders the target web page to a PDF using **wkhtmltopdf**, stores the result temporarily, and returns a time-limited download URL. Built with PHP 8.1+ and Slim 4, designed for deployment on a **cPanel dedicated server**.

---

## Requirements

| Requirement | Version / Notes |
|---|---|
| PHP | 8.1 or higher |
| Composer | 2.x |
| wkhtmltopdf | 0.12.x (installed on the server) |
| Apache | mod_rewrite enabled |
| PHP extensions | `proc_open`, `dns_get_record`, APCu (optional), System V semaphores (`sem_get`) on Linux |

> **Windows note:** System V semaphores are Linux-only. The application degrades gracefully on Windows by using APCu counters only. APCu must be enabled in `php.ini` for rate limiting and concurrency tracking to work.

---

## Installation

### 1. Upload files

Upload the entire project to your server (e.g. `/home/username/public_html/api` or a subdomain directory).

### 2. Install dependencies

SSH into the server and run:

```bash
cd /path/to/project
composer install --no-dev --optimize-autoloader
```

For development (includes testing tools):

```bash
composer install
```

### 3. Create the storage directory

The PDF files and their metadata sidecars are stored in a writable directory. Create it and set permissions:

```bash
mkdir -p /home/username/storage/pdfs
chmod 755 /home/username/storage/pdfs
```

The directory must be writable by the PHP process (typically the cPanel user or `www-data`).

### 4. Create the log directory

```bash
mkdir -p /path/to/project/storage/logs
chmod 755 /path/to/project/storage/logs
```

---

## Configuration

You can configure the application using either **environment variables** (recommended) or a **PHP config file**. Environment variables always take precedence.

### Option A — PHP config file (recommended for cPanel shared hosting)

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php` and fill in your values:

```php
return [
    'port'             => 443,
    'wkhtmltopdfPath'  => '/usr/local/bin/wkhtmltopdf',
    'apiKeys'          => ['your-long-random-secret-key-here'],
    'storageDir'       => '/home/username/storage/pdfs',
    'baseUrl'          => 'https://api.yourdomain.com',

    // Optional — uncomment to override defaults
    // 'ttlSeconds'             => 3600,   // 1 hour
    // 'cleanupIntervalSeconds' => 60,
    // 'maxConcurrentRenderers' => 5,
    // 'renderTimeoutSeconds'   => 30,
    // 'maxStorageMb'           => null,   // null = disabled
    // 'rateLimitRpm'           => null,   // null = disabled
];
```

### Option B — Environment variables

Copy the example file and set your values:

```bash
cp .env.example .env
```

Then edit `.env`:

```dotenv
PORT=443
WKHTMLTOPDF_PATH=/usr/local/bin/wkhtmltopdf
API_KEYS=your-long-random-secret-key-here,optional-second-key
STORAGE_DIR=/home/username/storage/pdfs
BASE_URL=https://api.yourdomain.com
```

On cPanel you can also set environment variables via **cPanel → Software → PHP-FPM** pool configuration or the **Environment Variables** section.

### Configuration reference

| Variable | Config key | Required | Default | Range | Description |
|---|---|---|---|---|---|
| `PORT` | `port` | ✓ | — | 1–65535 | Listening port |
| `WKHTMLTOPDF_PATH` | `wkhtmltopdfPath` | ✓ | — | — | Absolute path to wkhtmltopdf binary |
| `API_KEYS` | `apiKeys` | ✓ | — | 1–100 keys | Comma-separated list of API keys |
| `STORAGE_DIR` | `storageDir` | ✓ | — | — | Writable directory for PDF storage |
| `BASE_URL` | `baseUrl` | ✓ | — | — | Base URL of this API (no trailing slash) |
| `TTL_SECONDS` | `ttlSeconds` | | 3600 | 60–86400 | PDF lifetime in seconds |
| `CLEANUP_INTERVAL_SECONDS` | `cleanupIntervalSeconds` | | 60 | 10–3600 | Cleanup job frequency |
| `MAX_CONCURRENT_RENDERERS` | `maxConcurrentRenderers` | | 5 | 1–50 | Max simultaneous wkhtmltopdf processes |
| `RENDER_TIMEOUT_SECONDS` | `renderTimeoutSeconds` | | 30 | 5–300 | Per-render timeout |
| `RENDERER_ENGINE` | `rendererEngine` | | wkhtmltopdf | wkhtmltopdf/chrome | Renderer backend |
| `CHROME_PATH` | `chromePath` | if using chrome | â€” | â€” | Absolute path to Chrome/Chromium |
| `MAX_STORAGE_MB` | `maxStorageMb` | | disabled | > 0 | Max storage size in MB |
| `RATE_LIMIT_RPM` | `rateLimitRpm` | | disabled | 1–1000 | Requests per minute per API key |

---

## Apache / cPanel Setup

### Option A — Subdomain with DocumentRoot pointing to `public/`

This is the cleanest setup. In cPanel, create a subdomain (e.g. `api.yourdomain.com`) and set its **Document Root** to:

```
/path/to/project/public
```

The `public/.htaccess` file handles all routing automatically. No further Apache config is needed.

### Option B — Subdirectory install (DocumentRoot is the project root)

If Apache serves the project root directly, the root `.htaccess` rewrites all requests to `public/index.php`. Ensure `mod_rewrite` is enabled.

In cPanel → **Apache Configuration** → **Include Editor**, or via `.htaccess`, make sure:

```apache
Options -Indexes
AllowOverride All
```

### Verify mod_rewrite is enabled

```bash
apache2ctl -M | grep rewrite
# should output: rewrite_module (shared)
```

---

## Installing wkhtmltopdf

### On cPanel / CentOS / RHEL

```bash
# Download the static binary (no Qt dependencies needed)
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox-0.12.6.1-3.almalinux9.x86_64.rpm
sudo rpm -ivh wkhtmltox-0.12.6.1-3.almalinux9.x86_64.rpm

# Verify
which wkhtmltopdf
wkhtmltopdf --version
```

### On Ubuntu / Debian

```bash
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb
sudo dpkg -i wkhtmltox_0.12.6.1-3.jammy_amd64.deb
sudo apt-get install -f
```

### If you don't have root access (cPanel shared hosting)

Download the static pre-built binary:

```bash
mkdir -p ~/bin
cd ~/bin
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox-0.12.6.1-3.almalinux8.x86_64.rpm
# Extract manually or ask your host to install it
```

Then set `WKHTMLTOPDF_PATH` to the full path of the binary, e.g. `/home/username/bin/wkhtmltopdf`.

---

## Setting Up the Cleanup Cron Job

The cleanup script deletes expired PDF files. Set it up in **cPanel → Cron Jobs**:

**Command:**
```bash
/usr/bin/php /path/to/project/bin/cleanup.php
```

**Schedule:** Every minute (`* * * * *`) is recommended. You can also use every 5 minutes (`*/5 * * * *`) for lower load.

The script exits with code `0` on success or `1` if any I/O errors occurred (useful for cron alerting).

---

## API Usage

### Authentication

Conversion requests require a Bearer token:

```
Authorization: Bearer your-api-key-here
```

### Convert a URL to PDF

**Request:**
```http
POST /api/convert
Content-Type: application/json
Authorization: Bearer your-api-key-here

{
  "url": "https://example.com/page"
}
```

**Success response (200):**
```json
{
  "download_url": "https://api.yourdomain.com/api/files/a3f9b2c1d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9.pdf",
  "expires_at": "2024-01-15T13:00:00Z"
}
```

### Download a generated PDF

**Request:**
```http
GET /api/files/{filename}
```

Generated PDF download URLs are public until they expire; no Authorization
header is required for this endpoint.

**Success response (200):**
- `Content-Type: application/pdf`
- `Content-Disposition: attachment; filename="abc123....pdf"`
- PDF binary in the response body

### Error responses

All errors return JSON with a `message` field:

```json
{ "message": "Human-readable error description" }
```

| HTTP Status | Meaning |
|---|---|
| 400 | Missing or malformed request field |
| 401 | Missing Authorization header |
| 403 | Invalid API key |
| 404 | File not found |
| 410 | File has expired |
| 422 | Invalid URL (bad format, non-http/https scheme, SSRF-blocked) |
| 429 | Rate limit exceeded |
| 502 | wkhtmltopdf render failed |
| 503 | Server busy (queue full or timeout) |
| 504 | Render timed out |
| 507 | Storage full |

---

## Generating API Keys

Generate a secure random key using one of these methods:

```bash
# PHP
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# OpenSSL
openssl rand -hex 32

# Python
python3 -c "import secrets; print(secrets.token_hex(32))"
```

Add the key to `API_KEYS` in your config. Multiple keys are supported (comma-separated in env vars, array in config file) — useful for key rotation without downtime.

---

## PHP Extensions

Ensure the following are enabled in your `php.ini` or PHP-FPM pool config:

```ini
; Required
extension=openssl

; Required for concurrency tracking (rate limiting + queue depth)
extension=apcu
apc.enabled=1
apc.enable_cli=1   ; needed for CLI (cleanup cron)

; System V semaphores — Linux only, for cross-process concurrency control
; Usually available by default on Linux PHP builds
; extension=sysvsem
```

Check what's enabled:

```bash
php -m | grep -E "apcu|sysvsem|openssl"
```

---

## Running Tests

```bash
# Unit tests only (fast, no external dependencies)
vendor/bin/phpunit --testsuite Unit --no-coverage

# Property-based tests
vendor/bin/phpunit --testsuite Property --no-coverage

# All tests
vendor/bin/phpunit --no-coverage

# With HTML coverage report (requires Xdebug or PCOV)
vendor/bin/phpunit --coverage-html coverage/
```

---

## Project Structure

```
/
├── public/
│   ├── index.php          # Front controller (Slim app bootstrap)
│   └── .htaccess          # Routing for public/ as DocumentRoot
├── src/
│   ├── Config/            # ConfigLoader
│   ├── Controller/        # ConvertController, FileController
│   ├── Middleware/        # AuthMiddleware, RateLimitMiddleware, RequestLogMiddleware
│   ├── Service/           # InputValidator, SsrfGuard, RendererService, StorageService,
│   │                      #   ConcurrencyGuard, RateLimiter
│   ├── Job/               # CleanupJob
│   ├── Model/             # Config, StoredFile, CleanupResult
│   ├── Exception/         # All custom exception classes
│   └── Handler/           # JsonErrorHandler
├── bin/
│   └── cleanup.php        # CLI entry point for cron job
├── config/
│   └── config.example.php # Copy to config.php and fill in values
├── storage/
│   ├── pdfs/              # Generated PDF files (create + make writable)
│   └── logs/              # Application logs (auto-created)
├── tests/
│   ├── Unit/              # PHPUnit unit tests
│   ├── Property/          # Eris property-based tests
│   └── Integration/       # Integration tests
├── .env.example           # Copy to .env and fill in values
├── .htaccess              # Root-level rewrite (if DocumentRoot = project root)
├── composer.json
└── phpunit.xml
```

---

## Troubleshooting

**The app returns 500 on every request**
- Check `storage/logs/app.log` for the startup error.
- Confirm `wkhtmltopdf` is executable: `ls -la $(which wkhtmltopdf)`
- Confirm the storage directory exists and is writable: `ls -la /path/to/storage/pdfs`

**PDF renders are empty or corrupt**
- Test wkhtmltopdf directly: `wkhtmltopdf https://example.com /tmp/test.pdf && ls -lh /tmp/test.pdf`
- The renderer enables JavaScript with a short delay and preserves CSS backgrounds for closer browser parity.

**Always getting 422 SSRF error**
- The URL's hostname must resolve to a public IP. Private IPs (10.x.x.x, 192.168.x.x, 127.x.x.x, etc.) are blocked by design.

**APCu not available / rate limiting disabled**
- Add `extension=apcu` and `apc.enabled=1` to `php.ini`.
- Restart PHP-FPM: `sudo systemctl restart php8.1-fpm`

**mod_rewrite not working**
- Ensure `AllowOverride All` is set for the directory in Apache's config.
- Check `apache2ctl -M | grep rewrite` to confirm the module is loaded.

**Cleanup cron not running**
- Test manually: `/usr/bin/php /path/to/project/bin/cleanup.php; echo "Exit: $?"`
- Check cPanel cron logs in **cPanel → Cron Jobs → View Cron Job Logs**.

---

## Security Notes

- API keys are validated using `hash_equals()` (constant-time) to prevent timing attacks.
- API key values are **never** written to logs.
- URLs submitted to the API are DNS-resolved and checked against private/loopback CIDRs before being passed to wkhtmltopdf (SSRF protection).
- For closer browser fidelity, set `RENDERER_ENGINE=chrome` and `CHROME_PATH` to a Chrome/Chromium binary.
- Generated filenames use 160 bits of cryptographic randomness (`random_bytes(20)`), preventing enumeration.
- Stack traces are never exposed in production error responses.

---

## License

MIT
