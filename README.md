# VODNOX — Cloud PBX / Contact Center SaaS (Foundation)

VODNOX is a multi-tenant Cloud PBX / Contact Center platform built on Laravel.
This repository contains the **technical foundation only**: backend scaffolding,
authentication, API structure, localization, queues, and integration plumbing.
No business modules, dashboards, or UI pages are included in this phase.

---

## 1. Technology Stack

| Layer | Technology |
|-------|-----------|
| Framework | Laravel 12 (latest 12.x) |
| PHP | 8.2 (fixed) |
| Database | MySQL (utf8mb4) |
| Frontend (later) | Blade Views + Bootstrap 5 (RTL/LTR) |
| Auth | Laravel UI (session-based, backend only) |
| Queues | Database driver |
| Localization | Arabic (default) + English |

**Forbidden in this phase:** React, Vue, Livewire, Alpine, jQuery, Tailwind,
Breeze, Jetstream, Sanctum, Passport, Redis, Asterisk, any business module.

---

## 2. Installation

### Prerequisites

- PHP 8.2+
- Composer 2.x
- MySQL 8+
- Node.js 20+ (for building frontend assets only — not required in production)

### Steps

```bash
# 1. Install PHP dependencies
composer install

# 2. Create environment file
cp .env.example .env

# 3. Generate application key
php artisan key:generate

# 4. Configure MySQL in .env:
#    DB_CONNECTION=mysql
#    DB_HOST=127.0.0.1
#    DB_PORT=3306
#    DB_DATABASE=vodnox
#    DB_USERNAME=root
#    DB_PASSWORD=

# 5. Run migrations
php artisan migrate

# 6. Install & build frontend assets (Bootstrap 5 LTR + RTL)
npm install
npm run build

# 7. Start the development server
php artisan serve
```

---

## 3. MySQL Setup

Create the database with `utf8mb4` charset (required for full Arabic/Unicode
support):

```sql
CREATE DATABASE vodnox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE vodnox_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The default connection in `config/database.php` already uses `utf8mb4` /
`utf8mb4_unicode_ci`.

---

## 4. Folder Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── ApiResponse.php          # Unified JSON response trait
│   │   │   └── V1/
│   │   │       ├── BaseApiController.php
│   │   │       └── HealthController.php
│   │   ├── Auth/
│   │   │   └── LoginController.php       # Backend-only session auth
│   │   ├── Web/                          # (reserved for future web controllers)
│   │   └── Controller.php
│   ├── Middleware/
│   │   └── SetLocale.php                 # ar/en from session (web) or Accept-Language (API)
│   ├── Requests/                         # (reserved for Form Requests)
│   └── Resources/                        # (reserved for API Resources)
├── Integrations/
│   └── BaseIntegration.php               # HTTP client, logging, secret redaction
├── Models/
├── Providers/
│   └── AppServiceProvider.php            # Rate limiters (login, api)
└── Services/
    └── BaseService.php                   # Business logic base class

config/
├── app.php              # APP_NAME=VODNOX, timezone=Asia/Baghdad, locale=ar
├── cors.php             # Restricted origins, no credentials
├── integrations.php     # External service credentials from .env
├── database.php         # MySQL utf8mb4
└── logging.php          # "integrations" channel

lang/
├── ar/                  # Arabic (default): auth, api, validation, pagination, passwords
└── en/                  # English (fallback): same keys

routes/
├── web.php              # /, /login, /logout (session auth, JSON responses)
├── api.php              # /api/v1/health (rate-limited)
└── console.php          # Scheduler (empty in this phase)

resources/
├── sass/
│   ├── _variables.scss
│   ├── app-ltr.scss     # Bootstrap 5 LTR
│   └── app-rtl.scss     # Bootstrap 5 RTL (pre-built)
└── js/
    ├── app.js
    └── bootstrap.js     # Bootstrap JS + axios
```

---

## 5. Localization (Arabic / English)

- **Default locale:** `ar` (Arabic)
- **Fallback locale:** `en` (English)
- **Web:** locale is read from the session key `locale`.
- **API:** locale is read from the `Accept-Language` header.
- Only `ar` and `en` are accepted; any other value falls back to the default.
- Translatable strings are stored in `lang/ar/` and `lang/en/` — never hard-coded
  in controllers.

### RTL / LTR for future views

Bootstrap 5 ships as two separate compiled CSS files:

| File | Usage |
|------|-------|
| `app-ltr-*.css` | English interface (set `<html dir="ltr">`) |
| `app-rtl-*.css` | Arabic interface (set `<html dir="rtl">`) |

Both are produced by `npm run build` and registered in `public/build/manifest.json`.

---

## 6. Authentication (Backend Only)

Authentication is provided by **Laravel UI** with session-based guards.
**No login/register/dashboard views are shipped.** The login and logout
endpoints return JSON so the backend can be tested without a UI.

### Endpoints

| Method | Route | Description |
|--------|-------|-------------|
| POST | `/login` | Authenticate with `email` + `password` |
| POST | `/logout` | Invalidate session + regenerate CSRF token |

- Public **registration is disabled** (no register routes).
- Password reset and email verification are not enabled in this phase.
- Session is regenerated on login; invalidated + CSRF regenerated on logout.
- Login is rate-limited to **5 attempts/minute** per email+IP.
- Logout requires authentication and uses **POST** (not GET).

### Verifying Auth Without UI

```bash
# Start the server
php artisan serve

# Create a test user via tinker
php artisan tinker
>>> App\Models\User::factory()->create(['email' => 'a@b.c', 'password' => 'password123']);

# Get a session + XSRF cookie
curl -c cookies.txt http://127.0.0.1:8000/

# Extract and decode the XSRF token
XSRF=$(grep XSRF-TOKEN cookies.txt | awk '{print $7}' | python3 -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")

# Login
curl -b cookies.txt -c cookies.txt -X POST http://127.0.0.1:8000/login \
  -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $XSRF" \
  -d '{"email":"a@b.c","password":"password123"}'

# Logout (refresh XSRF token after login first)
XSRF=$(grep XSRF-TOKEN cookies.txt | awk '{print $7}' | python3 -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
curl -b cookies.txt -X POST http://127.0.0.1:8000/logout -H "X-XSRF-TOKEN: $XSRF"
```

### Automated tests

```bash
php artisan test
```

The test suite covers: login success/failure, logout, protected routes,
rate limiting (429), validation errors (422), and JSON error responses.

---

## 7. API

### Base URL

```
/api/v1
```

### Response Envelope

Every API response uses this unified structure:

```json
{
    "success": true,
    "message": "Service is running.",
    "data": { "status": "ok" },
    "errors": null
}
```

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | `true` on success, `false` on error |
| `message` | string | Human-readable message (localized) |
| `data` | mixed | Payload (null on error) |
| `errors` | object \| null | Validation error map (null otherwise) |

Paginated responses additionally include a `meta` object with `current_page`,
`per_page`, `total`, `last_page`, `from`, `to`.

### HTTP Status Codes

| Status | Meaning |
|--------|---------|
| 200 | Success |
| 201 | Created |
| 401 | Unauthenticated |
| 403 | Forbidden |
| 404 | Not found |
| 405 | Method not allowed |
| 422 | Validation error |
| 429 | Too many requests |

All `/api/*` errors return **JSON** (never HTML or a redirect to login).
Stack traces, SQL, and secrets are never exposed in responses.

### Health Endpoint

```
GET /api/v1/health
```

Returns `200` with `{"status": "ok"}`. Does not expose versions or internals.

### Rate Limiting

- API: 60 requests/minute per user (or IP if unauthenticated).
- Login: 5 attempts/minute per email+IP.

### CORS

Configured in `config/cors.php`. Allowed origins are read from
`CORS_ALLOWED_ORIGINS` (comma-separated). `supports_credentials` is **false**,
so credentials are never sent to arbitrary origins.

---

## 8. Queues & Scheduler

### Queues

- Driver: **database** (`QUEUE_CONNECTION=database`).
- The `jobs` table is created by the default migrations.
- Run a worker:

```bash
php artisan queue:work --tries=3
```

### Scheduler

- Registered in `routes/console.php` (empty in this phase).
- Production cron entry:

```cron
* * * * * cd /path-to-vodnox && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. External Integrations

The `app/Integrations/` directory and `config/integrations.php` are prepared
for future integrations (Asterisk/AMI, payments, SMS, etc.).

### Patterns enforced by `BaseIntegration`

- Uses the **Laravel HTTP Client** with configurable `timeout` and
  `connect_timeout`.
- Reads all credentials from `config/integrations.php` (which reads `.env`) —
  **`env()` is never called directly** in services or controllers.
- Logs to the dedicated **`integrations`** channel (`storage/logs/integrations.log`).
- **Redacts** passwords, tokens, API keys, and `Authorization` headers before
  writing to logs.
- Provides `handleFailure()` and `handleConnectionError()` for consistent
  error handling.
- **Automatic retries are disabled by default** (`INTEGRATION_RETRY_TIMES=0`).
  Subclasses must explicitly enable retries only for **idempotent** operations.

### Webhooks (future)

When adding webhook endpoints:

1. Verify the request signature using the provider's signing secret.
2. Store processed event IDs in a database table to enforce
   **idempotency** (reject duplicate events).
3. Respond with `200` quickly and process the payload asynchronously via a
   queued job.
4. Log webhook events to the `integrations` channel with secrets redacted.

No webhooks or real integrations are implemented in this phase.

---

## 10. Multi-Tenancy (Future)

VODNOX will serve multiple companies (tenants) with strict data isolation.
This phase only prepares the architecture:

- Web/API controller separation is **not** a substitute for tenant isolation.
- Future tenant scoping will be applied at the query/model level (e.g., a
  `tenant_id` column on business tables + a global scope).
- Gates and Policies can be added later; no authorization package is installed.
- Asterisk distribution across tenants/servers is intentionally left
  undecided.

---

## 11. Production Checklist

- Set `APP_ENV=production` and `APP_DEBUG=false`.
- Serve over **HTTPS**; set `SESSION_SECURE_COOKIE=true` and
  `SESSION_SAME_SITE=lax`.
- Set `CORS_ALLOWED_ORIGINS` to the production frontend domains.
- Run `php artisan config:cache`, `route:cache`, `view:cache`.
- Keep `.env` out of Git (already in `.gitignore`).
- Run the queue worker with a process supervisor (e.g., Supervisor).
- Set the scheduler cron entry.

### Timezone

- Application timezone: `Asia/Baghdad` (set in `config/app.php`).
- For call-event timestamps (future), store in **UTC** and convert to
  `Asia/Baghdad` at the presentation layer.

---

## 12. Bootstrap 5 Frontend Assets

Assets are built with Vite during development. The compiled files are shipped
to `public/build/` so production does **not** need Node.js or npm.

```bash
# Development (hot reload)
npm run dev

# Production build
npm run build
```

Outputs:

- `public/build/assets/app-ltr-*.css` — Bootstrap 5 LTR
- `public/build/assets/app-rtl-*.css` — Bootstrap 5 RTL
- `public/build/assets/app-*.js` — Bootstrap JS + axios

Only Bootstrap 5 and axios (HTTP client) are included. No other UI libraries.

---

## 13. What Was Implemented

- Laravel 12 on PHP 8.2 with MySQL (utf8mb4).
- Laravel UI session authentication (login/logout, JSON, no views).
- Login rate limiting (5/min), session regeneration, CSRF rotation on logout.
- Web/API controller separation; `/api/v1` prefix.
- Unified JSON response envelope; proper HTTP status codes; JSON-only API
  error handling (404, 405, 422, 429, 401, 403).
- Arabic (default) + English localization with RTL/LTR Bootstrap 5.
- `SetLocale` middleware (session for web, `Accept-Language` for API).
- Health endpoint at `/api/v1/health`.
- CORS with restricted origins, no credentials.
- Database queue driver + scheduler scaffold.
- `BaseIntegration` with HTTP client, secret redaction, dedicated log channel.
- `BaseService` for future business logic.
- 19 passing tests (auth, API errors, localization, health).
- `.env.example` with all variables (no secrets).

## 14. What Is Deferred

- Login/register/dashboard UI pages and Blade layouts.
- Business modules (tenants, users, calls, extensions, billing, reports).
- Token-based auth for Flutter (Sanctum/Passport).
- Password reset and email verification flows.
- Actual Asterisk/PJSIP integration and SIP/WebRTC.
- Real external integrations and webhooks.
- Authorization (Gates/Policies) and multi-tenancy data scoping.
- Redis (not required; database queue driver is used).

---

## 15. Verification Commands & Results

```bash
$ php artisan about
Application Name: VODNOX
Laravel Version:  12.x
PHP Version:      8.2.x
Timezone:         Asia/Baghdad
Locale:           ar
Database:         mysql
Queue:            database
Session:          database

$ php artisan test
Tests:  19 passed (64 assertions)

$ curl http://127.0.0.1:8000/api/v1/health
{"success":true,"message":"الخدمة تعمل.","data":{"status":"ok"},"errors":null}

$ curl -H "Accept-Language: en" http://127.0.0.1:8000/api/v1/health
{"success":true,"message":"Service is running.","data":{"status":"ok"},"errors":null}
```
