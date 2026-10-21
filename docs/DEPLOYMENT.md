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
- `ProvisionTelephonyJob` يحتاج عاملاً نشطاً لتزويد الاتصالات.

**هذا الإنتاج على استضافة cPanel مشتركة:** لا root ولا systemd ولا Supervisor،
لذا يُدار العامل عبر **cPanel Cron** (سكربت جاهز في
[`deploy/cpanel/queue-worker.sh`](../deploy/cpanel/queue-worker.sh) مع تفاصيل
التركيب في [`deploy/cpanel/README.md`](../deploy/cpanel/README.md)).

سطر Cron (كل دقيقة) — استبدل المسار بالمسار المطلق الفعلي:

```cron
* * * * * /bin/bash /home/ed7sm1twhe8d/public_html/vodnox.com/deploy/cpanel/queue-worker.sh >> /dev/null 2>&1
```

يشغّل السكربت دورة محدودة المدة (`--max-time=55`) خلف قفل `flock` يمنع تداخل
العمال، بـ`--timeout=60` أقل من `retry_after=90`، وسجل خاص بـVODNOX
(`storage/logs/vodnox-queue-worker.log`). **`queue:restart` لا يشغّل عاملاً** —
هو فقط يطلب من العمال العاملين الخروج بعد مهمتهم الحالية.

التحقق:

```bash
php artisan vodnox:queue-status          # الاتصال + المنتظرة + الفاشلة (بلا أسرار)
cat storage/framework/vodnox-queue-worker.heartbeat
```

**على سيرفر VPS/root** (بديل، غير مستخدم في هذا الإنتاج): Supervisor/systemd
مع أمر قريب من:

```ini
[program:vodnox-worker]
command=php /var/www/vodnox/artisan queue:work --tries=3 --timeout=60
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/vodnox/storage/logs/vodnox-queue-worker.log
stopwaitsecs=3600
```

> يجب أن يبقى `--timeout` **أقل من** `retry_after` (90s) في الحالتين.

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

### 7.1 ترحيلات هذا الإصدار (توجيه الوارد للبوابات)

إصدار «فصل بوابات الاتصال عن الأرقام والتحويل» يضيف ترحيلاً **واحداً إضافياً
غير مدمر** (إنشاء جداول جديدة فقط، بلا تعديل على جداول قائمة):

```
2026_10_21_000001_create_gateway_line_routing_tables.php
```

يُنشئ جداول `gateway_line_routes` و`gateway_line_business_hours` و
`gateway_line_weekend_days` و`gateway_line_date_exceptions` — أي «وجهة الوارد +
ساعات العمل + عطلة الأسبوع + الاستثناءات بالتواريخ + التحويل المؤقت» لكل منفذ.

```bash
php artisan migrate --force
php artisan migrate:status | grep gateway_line_routing_tables   # المتوقّع: Ran
```

> **الترتيب إلزامي:** نفّذ `php artisan migrate --force` **قبل** تشغيل عامل
> الطابور (`queue:restart`) وقبل فتح أي منفذ من الواجهة. الترحيل لا يعتمد على
> وكيل الوسائط، لكن كود التطبيق الجديد يقرأ هذه الجداول، فتشغيله قبل الترحيل
> يُظهر أخطاء «جدول غير موجود» عند فتح صفحة الأرقام والتحويل.

**ترتيب النشر الكامل لهذا الإصدار:** `git pull` ← `composer install --no-dev`
← `php artisan migrate --force` ← `queue:restart`. أما تركيب وكيل الوسائط
(`1.3.0`) فهو **خطوة مشغّل منفصلة** منفّذة يدوياً على سيرفر الوسائط
(انظر [`deploy/telephony/README.md`](../deploy/telephony/README.md) §5.1)، وليست
جزءاً من نشر cPanel. حتى تنفيذها تظهر رسالة «خدمة الربط لا تدعم خطوط البوابة
بعد» — سلوك صادق مقصود.

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
