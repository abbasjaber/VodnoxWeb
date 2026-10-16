# VODNOX — Cloud PBX / Contact Center SaaS

VODNOX منصّة سحابية متعدّدة المؤسسات (Multi-tenant) لإدارة المقاسم الهاتفية
ومراكز الاتصال، مبنية على Laravel. يحتوي هذا المستودع على **التطبيق الكامل**:
الموقع العام، لوحة السوبر أدمن، لوحة مسؤول المؤسسة، واجهات الموظف والاستقبال،
وحدة CRM، وحدة VODNOX FORMS، إعدادات الاتصالات والتوجيه وIVR، وبوابة الاتصالات
(بعقد موثّق ومحاكاة صريحة).

> التطبيق يعمل بشكل كامل ومستقل عن مجلد مرفقات التصميم `VODNOX-Full-Handoff/`.
> راجع [ATTACHMENT_INDEPENDENCE_REPORT.md](ATTACHMENT_INDEPENDENCE_REPORT.md).

---

## 1. حزمة التقنيات

| الطبقة | التقنية |
|--------|---------|
| الإطار | Laravel 12 (12.x) |
| PHP | 8.2 (ثابت) |
| قاعدة البيانات | MySQL (utf8mb4 / utf8mb4_unicode_ci) |
| الواجهات | Blade Views (بدون Components) + Bootstrap 5 + JavaScript أصلي |
| المصادقة | Laravel UI (جلسات، username/password) |
| الطوابير | مشغّل database |
| اللغات | العربية (افتراضي) + الإنجليزية (بديل)، RTL/LTR |
| المنطقة الزمنية | التخزين UTC، العرض والجداول `Asia/Baghdad` |

**ممنوع في هذا المشروع:** React، Vue، Inertia، Livewire، Alpine، Tailwind،
jQuery، Breeze، Jetstream، Sanctum، Passport، Redis، Asterisk/SIP/WebRTC حقيقي،
أي ذكاء اصطناعي فعلي للمجيب الذكي.

---

## 2. المتطلبات

- PHP 8.2+ مع إضافات `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype`, `fileinfo`
- Composer 2.x
- MySQL 8+
- (اختياري للتطوير فقط) Node.js 20+ — الإنتاج لا يحتاج npm.

---

## 3. التثبيت

```bash
# 1) اعتماديات PHP
composer install

# 2) ملف البيئة
cp .env.example .env
php artisan key:generate

# 3) إعداد قاعدة البيانات في .env
#    DB_CONNECTION=mysql / DB_HOST / DB_PORT / DB_DATABASE=vodnox / DB_USERNAME / DB_PASSWORD

# 4) الترحيلات + البيانات المرجعية (أدوار/صلاحيات/Features — بلا بيانات دخول)
php artisan migrate
php artisan db:seed

# 5) إنشاء حساب السوبر أدمن الإنتاجي (تفاعلي — لا حساب افتراضي)
php artisan vodnox:create-admin

# 6) تشغيل السيرفر
php artisan serve
```

### إعداد قاعدة البيانات (utf8mb4 إلزامي للعربية)

```sql
CREATE DATABASE vodnox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE vodnox_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

> **ملاحظة:** لا تستخدم `migrate:fresh` على قاعدة قائمة. الترحيلات كلها إضافية
> وغير مدمرة.

---

## 4. متغيرات البيئة الأساسية

| المتغير | الوصف |
|---------|-------|
| `APP_NAME=VODNOX` | اسم التطبيق |
| `APP_LOCALE=ar` / `APP_FALLBACK_LOCALE=en` | اللغة والبديل |
| `DB_*` | اتصال MySQL |
| `SESSION_DRIVER=database` | الجلسات |
| `QUEUE_CONNECTION=database` | الطوابير |
| `CORS_ALLOWED_ORIGINS` | أصول مسموحة (بلا `*` مع credentials) |
| `TELEPHONY_WEBHOOK_SECRET` | سر توقيع Webhooks الاتصالات (HMAC-SHA256) — فارغ حتى توفّر بيانات الربط |
| `ASTERISK_*` | إعدادات AMI (مؤجّلة) |

`.env.example` **لا يحتوي أي أسرار** (R12). كل بيانات الاعتماد تُقرأ عبر
`config/*` ولا يُستدعى `env()` مباشرة داخل الخدمات أو المتحكّمات.

---

## 5. البنية

```
app/
├── Console/Commands/        # vodnox:create-admin
├── Contracts/Telephony/     # TelephonyGateway (العقد الموثّق)
├── Http/
│   ├── Controllers/
│   │   ├── Admin/           # 12 متحكّم — لوحة السوبر أدمن
│   │   ├── Company/         # 22 متحكّم — لوحة المؤسسة (اتصالات/CRM/FORMS)
│   │   ├── User/            # 7 متحكّمات — واجهات الموظف والاستقبال
│   │   ├── Public/          # الموقع العام + النماذج العامة
│   │   ├── Auth/            # تسجيل الدخول
│   │   ├── Api/V1/          # Health + Telephony Webhook
│   │   └── Concerns/        # ResolvesTenant (عزل المؤسسة)
│   ├── Middleware/          # SetLocale, EnsureActiveOrganization, RoleMiddleware, EnsureFeatureSubscription
│   └── Requests/            # Form Requests (Admin/Company/Crm/Forms/Portal)
├── Jobs/                    # ProvisionTelephonyJob (retry/backoff/idempotency)
├── Models/                  # 55 موديلاً
├── Services/
│   ├── Forms/               # FormDefinitionValidator, FormPublishService, FormSubmissionService
│   ├── Routing/             # ScheduleMatcher (توقيت بغداد)
│   ├── Telephony/           # TelephonyWebhookService (HMAC + idempotency)
│   ├── AuditService.php, SettingsService.php, SubscriptionService.php
├── Telephony/               # SimulatedTelephonyGateway, HttpTelephonyGateway
resources/views/             # 83 ملف Blade (بدون Components)
routes/web.php               # 143 مساراً (يستثني الحزم)
routes/api.php               # /api/v1
lang/{ar,en}/                # ترجمات كاملة
```

---

## 6. الأدوار والصلاحيات

| الدور | النطاق | الوجهة بعد الدخول |
|-------|--------|-------------------|
| `super_admin` | المنصة (بلا مؤسسة) | `/admin` |
| `organization_admin` | مؤسسته | `/company` |
| `supervisor` | مؤسسته | `/company` |
| `agent` | مؤسسته | `/user` |
| `viewer` | قراءة فقط | `/user` |

- العزل مطبّق على مستوى **السجلات والطلبات والملفات والتصدير** عبر
  `ResolvesTenant` (اشتقاق المؤسسة من المستخدم، وعدم الثقة بـ `organization_id`
  القادم من الطلب) وليس Middleware الدور فقط.
- المصادقة: username/password، CSRF، تجديد الجلسة، خروج POST،
  rate-limit 5 محاولات/دقيقة لكل بريد+IP. لا تسجيل عام.

---

## 7. الوحدات

### 7.1 الموقع العام
الرئيسية، الخدمات، الأسعار، من نحن، اتصل بنا، الدخول.
نموذج «اتصل بنا» يحفظ في `contact_inquiries`. الأسعار قابلة للضبط من السوبر أدمن.

### 7.2 السوبر أدمن (`/admin`)
Overview، المؤسسات (CRUD + تفاصيل بتبويبات: معلومات، اشتراك، مستخدمون،
تحويلات/أرقام، IVR/طوابير، استخدام، تسجيلات، فواتير، نشاط)، الاشتراكات،
الباقات والحدود، الفواتير والمدفوعات، الاستخدام، خوادم الاتصالات، التكاملات،
الفريق والصلاحيات، سجل النشاط، إعدادات المنصة، وإدارة اشتراكات
CRM / FORMS / Smart IVR.

### 7.3 مسؤول المؤسسة (`/company`)
Overview، الموظفون والأدوار، الأقسام، الأجهزة/التحويلات، طوابير الاتصال، IVR،
الأرقام ومسارات الاتصال، سجل المكالمات، التسجيلات، تقارير الأداء، اشتراك
المؤسسة والفواتير، الإعدادات، سجل النشاط.

### 7.4 الموظف والاستقبال (`/user`)
لوحة الاتصال، دليل المؤسسة والمفضّلة، السجل الشخصي، البريد الصوتي، المهام،
الحساب، صفحتا `incoming` و`reception` مع حفظ الملاحظات والشكاوى والمتابعة
وملخّص ما بعد المكالمة. لا بدء استقبال جديد قبل حفظ الملخّص، والمسودة لا تُفقد.

### 7.5 CRM (باشتراك مستقل)
العملاء وملفاتهم، جهات الاتصال، الملاحظات، التفاعلات، الفرص والمراحل، المهام،
الشكاوى، التقارير. التحقق من التفعيل والفترة والحدود خادمياً عند كل عملية؛
التعليق/الانتهاء يحجب العمل دون مسح البيانات.

### 7.6 VODNOX FORMS (باشتراك مستقل)
منشئ نماذج بـ 11 نوع حقل، ترتيب، شروط ظهور، تسمية/مساعدة/إلزام، حدود، هوية،
نشر، رسالة شكر. روابط عامة بـ **Permanent opaque tokens** (`/f/{token}`) من
الخادم — لا snapshot في الـ fragment. الردود تُحفظ مركزياً مع تحقق خادمي من
الاشتراك والنشر والمهلة والحصّة والحقول والشروط، وحماية الحصّة المتزامنة عبر
transaction + `lockForUpdate`، وإصدار schema عند النشر، وتصدير CSV آمن
(BOM + حماية الصيغ).

### 7.7 إعدادات الاتصالات وIVR العادي (مضمّنان تلقائياً)
متاحان تلقائياً لكل مؤسسة مفعّلة **دون اشتراك إضافة**، ولا تظهر للموظفين.
جداول بأيام/أوقات بغداد لموظف واحد أو اثنين، متزامن أو متتابع، رنين 5–120ث،
منع التكرار والتداخل وبداية=النهاية، دعم منتصف الليل والنهاية الحصرية.
مسارات عدم الرد/الافتراضي/خارج الدوام: بريد صوتي، رسالة ثم إنهاء، إنهاء،
موظف بديل (مرة واحدة 20ث ثم بريد صوتي — لا دوران). IVR: ترحيب AR/EN، أزرار
0–9 فريدة، timeout 3–30، 0–3 إعادة، مساري نفاد. أداة اختبار المسار محاكاة فقط
ولا تُنشئ مكالمات أو usage.

### 7.8 المجيب الآلي الذكي (قريباً)
اشتراك مستقل بحالات inactive/active/suspended يفعّله السوبر أدمن. عند `active`
يظهر قسم «قريباً» لمسؤول المؤسسة فقط؛ عند التعليق يختفي ويُمنع الوصول المباشر
خادمياً. لا AI ولا مفاتيح ولا محادثة.

---

## 8. بوابة الاتصالات

- العقد الموثّق: [TelephonyGateway](file:///Users/hayderalkhafaji/Documents/trae_projects/vodnox/app/Contracts/Telephony/TelephonyGateway.php).
- المحاكي: `SimulatedTelephonyGateway` (افتراضي — لا اتصال شبكي).
- الـ Adapter الحقيقي: `HttpTelephonyGateway` يُربط فقط عند وجود خادم
  بحالة `connected` في جدول `telephony_servers`.
- Provisioning عبر `ProvisionTelephonyJob` مع retry/backoff/idempotency.
- Webhooks موقّعة HMAC-SHA256 + idempotency بـ `event_id`، وترجع JSON دائماً.
- التفاصيل الكاملة في [docs/TELEPHONY_API_CONTRACT.md](docs/TELEPHONY_API_CONTRACT.md).

---

## 9. الاختبارات

```bash
php artisan test
```

النتيجة الحالية: **95 اختباراً ناجحاً (217 تأكيداً)** في 10 ملفات تغطي:
المصادقة، الأخطاء API، الترجمة، الصحة، **العزل بين مؤسستين (IDOR)**،
**حدود الاشتراكات**، **شروط النماذج وحصص الردود**، **جداول التوجيه الليلية
والتداخل**، **الفوترة**، و**Webhooks الاتصالات**.

التفاصيل في [docs/TEST_REPORT.md](docs/TEST_REPORT.md).

---

## 10. النشر

دليل النشر الكامل (بيئة الإنتاج، التخزين المؤقت، الطوابير، المجدول، الأمان)
في [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

---

## 11. فهرس التوثيق

| المستند | المحتوى |
|---------|---------|
| [docs/IMPLEMENTATION_MAP.md](docs/IMPLEMENTATION_MAP.md) | تصنيف الوظائف: منفذة ومختبرة / جزئية / غير منفذة |
| [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) | مخطط قاعدة البيانات (63 جدولاً) |
| [docs/TELEPHONY_API_CONTRACT.md](docs/TELEPHONY_API_CONTRACT.md) | عقد بوابة الاتصالات والـ Webhooks |
| [docs/TEST_REPORT.md](docs/TEST_REPORT.md) | تقرير الاختبارات |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | دليل النشر والتشغيل |
| [ATTACHMENT_INDEPENDENCE_REPORT.md](ATTACHMENT_INDEPENDENCE_REPORT.md) | إثبات الاستقلالية عن المرفقات |

---

## 12. نتائج التحقّق

```bash
$ php artisan test
Tests:  95 passed (217 assertions)

$ php artisan route:list --except-vendor | tail -1
Showing [143] routes

$ php artisan view:cache
Blade templates cached successfully.
```

- المنطقة الزمنية: `Asia/Baghdad`، اللغة: `ar`، الاتصال: `mysql`، الجلسات والطوابير: `database`.
- `TELEPHONY_WEBHOOK_SECRET` فارغ ⇒ أي Webhook وارد يُرفض بـ `503` مع JSON
  (لا HTML ولا redirect) حتى توفّر بيانات الربط.
