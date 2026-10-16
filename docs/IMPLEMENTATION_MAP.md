# خريطة تنفيذ VODNOX (IMPLEMENTATION_MAP)

> الحالة: ✅ منفّذة ومختبرة | 🟡 منفّذة وغير مختبرة آلياً | ⛔ مؤجّلة بحسب المواصفة
> هذا الملف يعكس الوضع الفعلي للكود بعد التحقق (143 مساراً، 63 جدولاً، 100 اختبار).

## 1. تصنيف الوظائف

| # | الوحدة | الحالة | التغطية |
|---|--------|--------|---------|
| 2.1 | الموقع العام (الرئيسية، الخدمات، الأسعار، من نحن، اتصل بنا، الدخول) | ✅ | `PublicPagesTest` (5): الصفحات 200، حفظ الاستفسار، التحقق من الحقول والتحقق من البريد |
| 2.2 | المصادقة والأدوار (username/password، CSRF، throttle، redirect بالدور، خروج POST) | ✅ | `LoginTest` (7) |
| 2.3 | عزل المؤسسات (Multi-tenancy) على مستوى السجلات/الطلبات/الملفات/التصدير | ✅ | `TenantIsolationTest` (16) — IDOR بين مؤسستين + تزوير `organization_id` |
| 2.4 | السوبر أدمن (Overview، مؤسسات، باقات، اشتراكات، فوترة، استخدام، خوادم، تكاملات، فريق، سجل، إعدادات، إدارة إضافات) | ✅ | `BillingTest` (6) + `FeatureSubscriptionTest` (جزء) + فحص المسارات |
| 2.5 | مسؤول المؤسسة (موظفون، أدوار، أقسام، تحويلات/أجهزة، طوابير، IVR، أرقام، سجل، تسجيلات، تقارير، اشتراك، إعدادات، سجل نشاط) | ✅ | `TenantIsolationTest` + فحص المسارات |
| 2.6 | الموظف والاستقبال (لوحة، دليل، مفضّلة، سجل، بريد صوتي، incoming، reception، ملاحظات/شكاوى/متابعة/ملخص) | 🟡 | منفّذة بالكامل؛ تغطية آلية غير مباشرة (المسارات والكيانات مغطاة في اختبارات العزل) |
| 2.7 | CRM (عملاء، جهات اتصال، ملاحظات، تفاعلات، فرص/مراحل، مهام، شكاوى، تقارير) | ✅ | `FeatureSubscriptionTest` + `TenantIsolationTest` |
| 2.8 | VODNOX FORMS (منشئ 11 نوعاً، شروط، ترتيب، نشر، روابط عامة، ردود مركزية، تصدير CSV آمن) | ✅ | `FormSubmissionTest` (13) |
| 2.9 | إعدادات الاتصالات وIVR العادي (جداول بغداد، متزامن/متتابع، مسارات عدم الرد/الخارج، IVR) | ✅ | `ScheduleMatcherTest` (18) — إضافة إلى `FeatureSubscriptionTest` (R4) |
| 2.10 | المجيب الآلي الذكي (اشتراك مستقل، «قريباً»، منع الوصول المباشر) | ✅ | `FeatureSubscriptionTest` (R5) |
| 2.11 | بوابة الاتصالات (عقد، محاكي، Adapter، Provisioning Job، Webhooks موقّعة) | ✅ | `TelephonyWebhookTest` (8) |
| 3 | غير الوظيفية (Laravel 12/PHP 8.2/MySQL/Blade/Bootstrap 5/Vanilla JS، لا React/Vue، UTF8MB4، TZ، لا npm runtime) | ✅ | `LocalizationTest` (6) + `HealthTest` (2) + `ErrorHandlingTest` (4) |

---

## 2. تصنيف ROUTE_MAP (المرفقات) مقابل التنفيذ

| صفحة المرفقات | المسار المقترح | التنفيذ في التطبيق | ملاحظة |
|----------------|----------------|--------------------|--------|
| `index.html` | `/` | ✅ `home` | |
| `about/index.html` | `/about` | ✅ `about` | |
| `services/index.html` | `/services` | ✅ `services` | |
| `pricing/index.html` | `/pricing` | ✅ `pricing` | |
| `contact/index.html` | `/contact` | ✅ `contact` + `contact.store` | يحفظ في `contact_inquiries` |
| `login/index.html` | `/login` | ✅ `login` | |
| `admin/index.html` | `/admin` | ✅ `admin.index` | |
| `admin/crm.html` | `/admin/crm` | ✅ `admin.crm.index` | إدارة اشتراك CRM |
| `admin/forms.html` | `/admin/forms` | ✅ `admin.forms.index` | إدارة اشتراك FORMS |
| `admin/smart-ivr.html` | `/admin/smart-ivr` | ✅ `admin.smart-ivr.index` | إدارة اشتراك Smart IVR |
| `admin/communications.html` | `/admin/communications` | ✅ مُستوعب في `admin.servers` + `admin.integrations` | المواصفة 2.4 تضع للـ super_admin «خوادم الاتصالات والتكاملات»؛ وجداول التوجيه ملك للمؤسسة (R4) |
| `company/index.html` | `/company` | ✅ `company.index` | |
| `company/communications.html` | `/company/communications` | ✅ `company.communications.*` | إنشاء/تعديل/حذف/اختبار |
| `company/crm.html` | `/company/crm` | ✅ `company.crm.*` | بتفعيل الاشتراك |
| `company/forms.html` | `/company/forms` | ✅ `company.forms.*` | بتفعيل الاشتراك |
| `company/smart-ivr.html` | `/company/smart-ivr` | ✅ `company.smart-ivr.index` | يظهر عند `active` فقط |
| `user/index.html` | `/user` | ✅ `user.index` | |
| `user/incoming.html` | `/user/incoming` | ✅ `user.incoming.*` | |
| `user/reception.html` | `/user/reception` | ✅ `user.reception.*` | |
| `forms/index.html` | `/forms` (snapshot في fragment) | ✅ `/f/{token}` | المواصفة 2.8 تفرض **Permanent opaque tokens** من الخادم بدل snapshot في الـ fragment |

---

## 3. تفصيل الحماية على مستوى السجلات (ليس Middleware فقط)

- **اشتقاق المؤسسة**: `ResolvesTenant::organization()` تشتق المؤسسة من المستخدم
  المسجّل وتُرجع `403` عند غيابها؛ ولا تُقرأ من الطلب.
- **ملكية السجل**: `authorizeTenant($org, $model)` تُرجع `404` للسجل الأجنبي
  (تمنع تسريب الوجود).
- **تزوير الطلب**: اختبار `test_store_ignores_spoofed_organization_id_in_request_body`
  يثبت أن `organization_id` القادم في الـ body يُتجاهل.
- **القوائم**: كل استعلام مُقيّد بـ `where('organization_id', $org->id)`.
- **الملفات**: التسجيلات تُخزّن خارج `public/` ومسارها مُقيّد بالملكية.
- **التصدير**: `company.forms.submissions.export` يُصدّر ردود نموذج المؤسسة فقط
  (مُثبت في اختبار العزل بأن التصدير لا يحتوي بيانات المؤسسة الأخرى).

---

## 4. الفجوات الحقيقية المتبقية

لا توجد وظائف جوهرية غير منفّذة ضمن نطاق المواصفة. المتبقّي هو:

| البند | الحالة | السبب |
|-------|--------|-------|
| تشغيل فعلي لـ Asterisk/SIP/WebRTC | ⛔ | خارج النطاق صراحةً (محاكاة فقط) حتى توفّر بيانات الربط |
| ذكاء اصطناعي للمجيب الذكي | ⛔ | خارج النطاق («قريباً» فقط) |
| بوابة دفع فعلية | ⛔ | خارج النطاق (تسجيل يدوي للمدفوعات) |
| رفع ملفات في النماذج، ربط تلقائي FORMS↔CRM | ⛔ | خارج النطاق بحسب spec §1 (Non-goals) |
| توكن Flutter (Sanctum/Passport) | ⛔ | خارج النطاق وممنوع |

---

## 5. نتائج التحقق

```bash
php artisan test          # 100 passed
php artisan route:list    # 143 routes (except-vendor)
php artisan view:cache    # cached successfully
```
