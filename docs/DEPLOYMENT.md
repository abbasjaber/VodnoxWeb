# دليل النشر (DEPLOYMENT)

دليل تشغيل VODNOX في بيئة الإنتاج.

---

## 1. متطلبات السيرفر

- PHP 8.2 (FPM) مع: `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype`, `fileinfo`, `bcmath`, `tokenizer`, `xml`.
- MySQL 8+ (utf8mb4).
- Composer 2.x (للتثبيت فقط).
- Nginx/Apache مع HTTPS.
- **لا حاجة لـ Node.js** في الإنتاج (الأصول مبنية ومُسلّمة في `public/`).

---

## 2. خطوات النشر

```bash
# 1) جلب الكود + الاعتماديات (بلا dev)
composer install --no-dev --optimize-autoloader

# 2) البيئة
cp .env.example .env
php artisan key:generate

# 3) إعداد الإنتاج في .env
#    APP_ENV=production
#    APP_DEBUG=false
#    APP_URL=https://<domain>
#    DB_* = بيانات الإنتاج
#    SESSION_SECURE_COOKIE=true
#    SESSION_SAME_SITE=lax

# 4) الترحيلات والبيانات المرجعية
php artisan migrate --force
php artisan db:seed --force        # أدوار/صلاحيات/features فقط

# 5) السوبر أدمن (تفاعلي — لا حساب افتراضي منشور)
php artisan vodnox:create-admin

# 6) التخزين المؤقت
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7) الصلاحيات
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## 3. الصلاحيات ومسارات الملفات

- **جذر الموقع (document root)** يجب أن يكون `public/`.
- `storage/` و`bootstrap/cache/` قابلان للكتابة.
- الملفات الخاصة (التسجيلات، إثباتات الدفع) تُخزّن ضمن `storage/app/` **خارج**
  `public/`، ويُوصَل إليها عبر مسار مُقيّد بالملكية فقط.
- رابط التخزين العام (إن لزم): `php artisan storage:link`.

---

## 4. الطوابير (Queues)

- المشغّل: `database`. جدول `jobs` يُنشأ بالترحيلات.
- شغّل عاملاً تحت مشرف عمليات (Supervisor/systemd):

```bash
php artisan queue:work --tries=3 --timeout=90
```

مثال Supervisor:

```ini
[program:vodnox-worker]
command=php /var/www/vodnox/artisan queue:work --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/vodnox/storage/logs/worker.log
stopwaitsecs=3600
```

- `ProvisionTelephonyJob` يحتاج عاملاً نشطاً لتزويد الاتصالات.

---

## 5. المجدول (Scheduler)

```cron
* * * * * cd /var/www/vodnox && php artisan schedule:run >> /dev/null 2>&1
```

المجدول مسجّل لكنه فارغ حالياً (لا مهام دورية مطلوبة في النطاق الحالي).

---

## 6. الأمان

- `APP_DEBUG=false` و`APP_ENV=production` إلزاميان.
- HTTPS + `SESSION_SECURE_COOKIE=true`.
- `CORS_ALLOWED_ORIGINS` = نطاقات الإنتاج فقط (لا `*`).
- `.env` خارج Git (مُستثنى في `.gitignore`).
- `TELEPHONY_WEBHOOK_SECRET` سرّي؛ أي Webhook وارد بلا توقيع صالح يُرفض.
- بيانات اعتماد الخادم (`ami_secret`) مشفّرة في القاعدة ومخفيّة من التسلسل.
- لا توجد بيانات دخول افتراضية ثابتة في الكود أو الـ seeder.

---

## 7. تحديث الإصدار (Deploy جديد)

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force        # ترحيلات إضافية غير مدمرة
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

> **ممنوع** استخدام `migrate:fresh` على قاعدة قائمة — تُفقد البيانات.

---

## 8. الإعداد في `config/app.php`

| الإعداد | القيمة |
|---------|--------|
| `name` | `VODNOX` |
| `timezone` | `Asia/Baghdad` |
| `locale` | `ar` |
| `fallback_locale` | `en` |

التخزين UTC، والعرض والجداول بتوقيت بغداد.

---

## 9. التحقق بعد النشر

```bash
php artisan about
php artisan route:list --except-vendor | tail -1
curl -s https://<domain>/api/v1/health
# {"success":true,"message":"الخدمة تعمل.","data":{"status":"ok"},"errors":null}
```

- تأكّد من أن `webhook` يُرجع `503` عند غياب السر (سلوك متوقّع) و`401` عند
  توقيع خاطئ.
- راقب `storage/logs/laravel.log` وقناة `integrations`.

---

## 10. الاستقلالية عن المرفقات

النشر لا يتطلّب مجلد `VODNOX-Full-Handoff/` إطلاقاً؛ يمكن حذفه بأمان.
راجع [ATTACHMENT_INDEPENDENCE_REPORT.md](../ATTACHMENT_INDEPENDENCE_REPORT.md).
