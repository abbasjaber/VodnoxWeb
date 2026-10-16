# تقرير الاختبارات (TEST REPORT)

> آخر تشغيل: **100 اختباراً ناجحاً (234 تأكيداً)** — 11 ملف اختبار.

```bash
$ php artisan test --compact
Tests:  100 passed (234 assertions)
Duration: ~4.5s
```

قاعدة الاختبار: `vodnox_test` (MySQL / utf8mb4) مع `RefreshDatabase`.

---

## 1. توزيع الاختبارات

| # | الملف | العدد | ما يغطّيه |
|---|-------|------|-----------|
| 1 | `tests/Feature/Public/PublicPagesTest.php` | 5 | R1: صفحات الموقع العام (200)، حفظ استفسار الاتصال، التحقق من الحقول/البريد |
| 2 | `tests/Feature/Auth/LoginTest.php` | 7 | R2: نجاح/فشل الدخول، الخروج POST، حماية المسارات، rate-limit (429)، التحقق (422)، توجيه بالدور |
| 3 | `tests/Feature/Api/ErrorHandlingTest.php` | 4 | R13: `/api/*` ترجع JSON عند 404/405/422/401 |
| 4 | `tests/Feature/Api/HealthTest.php` | 2 | `/api/v1/health` + غلاف الاستجابة |
| 5 | `tests/Feature/Api/TelephonyWebhookTest.php` | 8 | Webhooks: 503 غير مهيأ، بلا توقيع، توقيع خاطئ/صحيح، idempotency، `event_id` ناقص، `organization_id` |
| 6 | `tests/Feature/LocalizationTest.php` | 6 | ar/en، `setLocale`، غلاف API، RTL/LTR |
| 7 | `tests/Feature/Isolation/TenantIsolationTest.php` | 16 | R3: IDOR بين مؤسستين (عرض/تعديل/حذف عملاء)، ملاحظات/تفاعلات على عميل أجنبي، تزوير `organization_id`، مكالمات/تسجيلات، FORMS (builder/update/delete/submissions/export) |
| 8 | `tests/Feature/Subscriptions/FeatureSubscriptionTest.php` | 15 | R4/R5: gatingCRM/FORMS/Comms، التعليق/الانتهاء/البداية المستقبلية، Smart IVR، رفض دور agent |
| 9 | `tests/Feature/Forms/FormSubmissionTest.php` | 13 | R6: حصّة النموذج والمؤسسة، الإلزامي، الحقول المخفية شرطياً، تحقق الخيار/البريد، غير منشور، token، عدم كسر الردود القديمة، BOM وصيغ CSV |
| 10 | `tests/Feature/Routing/ScheduleMatcherTest.php` | 18 | R7/R8/R9: النهاية الحصرية، منتصف الليل، التداخل، التكرار، موظف مؤسسة أخرى/غير فعّال |
| 11 | `tests/Feature/Admin/BillingTest.php` | 6 | R10: إثبات الدفع لا يجعل الفاتورة paid، تأكيد دفعة كاملة/جزئية، سجل التدقيق، `paid_at` |

**المجموع: 100 اختبار — 234 تأكيد.**

---

## 2. تغطية معايير القبول

| المعيار | الحالة | الدليل |
|---------|--------|--------|
| **R1** صفحات ROUTE_MAP | ✅ | `PublicPagesTest` + فحص `route:list` (143 مساراً) + جدول المقابلة في `IMPLEMENTATION_MAP.md` |
| **R2** مصادقة username/CSRF/throttle/خروج POST | ✅ | `LoginTest` (7) |
| **R3** منع IDOR بين المؤسستين | ✅ | `TenantIsolationTest` (16) |
| **R4** الاتصالات/IVR متاحة دون اشتراك | ✅ | `FeatureSubscriptionTest` (حالة R4) |
| **R5** Smart IVR مخفي/ممنوع ما لم يكن active | ✅ | `FeatureSubscriptionTest` (حالة R5) |
| **R6** إرسالان متزامنان عند الحصة الأخيرة | ✅ | `FormSubmissionTest` (transaction + `lockForUpdate`) |
| **R7** الأحد 09:00–17:00 يطابق 09:00 لا 17:00 | ✅ | `ScheduleMatcherTest` |
| **R8** السبت 22:00–الأحد 02:00 يطابق الأحد 01:00 لا 02:00 | ✅ | `ScheduleMatcherTest` |
| **R9** رفض بداية=نهاية/يوم فارغ/موظف مكرر/أجنبي/غير فعّال | ✅ | `ScheduleMatcherTest` |
| **R10** الفاتورة لا تصبح paid تلقائياً عند الإثبات | ✅ | `BillingTest` (6) |
| **R11** يعمل بعد حذف `VODNOX-Full-Handoff` | ✅ | `ATTACHMENT_INDEPENDENCE_REPORT.md` |
| **R12** لا أسرار في Git/`.env.example` | ✅ | فحص `.env.example` + `hidden`/`encrypted` لبيانات الخادم |
| **R13** `/api/*` ترجع JSON عند الخطأ | ✅ | `ErrorHandlingTest` (4) + `TelephonyWebhookTest` |
| **R14** وجود `composer.lock`/`.env.example`/migrations/README/تقرير الاستقلالية | ✅ | موجودة في المستودع |
| **R15** `route:list` + migrations بلا أخطاء | ✅ | 143 مساراً، 17 ترحيلاً بحالة Ran |

---

## 3. أوامر التحقق

```bash
# الاختبارات
php artisan test

# المسارات
php artisan route:list --except-vendor

# الترحيلات
php artisan migrate:status

# التخزين المؤقت للقوالب
php artisan view:cache
```

---

## 4. اختبارات محلية مقابل الإنتاج

- بيانات الاختبار تُنشأ عبر `BuildsTenant` (مؤسسات/مستخدمون/اشتراكات) داخل
  `vodnox_test` فقط عبر `RefreshDatabase`.
- `DatabaseSeeder` لا يُنشئ أي بيانات دخول افتراضية — فقط الأدوار والصلاحيات
  و`features`. السوبر أدمن الإنتاجي يُنشأ بـ `php artisan vodnox:create-admin`.
- لم يُستخدم `migrate:fresh` على قاعدة قائمة.
