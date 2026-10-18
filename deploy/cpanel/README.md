# VODNOX — عامل الطابور على استضافة cPanel

هذا المجلد يحوي طريقة تشغيل طابور VODNOX (`QUEUE_CONNECTION=database`) على
استضافة cPanel/GoDaddy المشتركة، حيث **لا يوجد root ولا systemd ولا Supervisor**،
ولا يمكن إبقاء عملية `queue:work` طويلة الأمد حيّة.

الحل: **cPanel Cron كل دقيقة** يشغّل عاملاً محدود المدة، مع قفل يمنع تداخل
العمال.

| الملف | الوظيفة |
| --- | --- |
| `queue-worker.sh` | يشغّل `queue:work` مرة واحدة محدودة المدة خلف قفل، بمسار مطلق ولوج خاص بـVODNOX. |

---

## 1. التركيب (يدوي — لا يمكن تنفيذه من CI)

> إنشاء الملف داخل Git **لا يعتبر تركيباً**. التركيب يتم من لوحة cPanel.

1. cPanel → **Cron Jobs** → Add New Cron Job.
2. اختر **Once Per Minute** (`* * * * *`).
3. في خانة Command ضع السطر التالي (استبدل مسار المشروع بالمسار المطلق الفعلي):

```cron
* * * * * /bin/bash /home/ed7sm1twhe8d/public_html/vodnox.com/deploy/cpanel/queue-worker.sh >> /dev/null 2>&1
```

المسار الافتراضي المتوقع على استضافة فودنوكس:

```
/home/ed7sm1twhe8d/public_html/vodnox.com/deploy/cpanel/queue-worker.sh
```

4. إذا كان PHP العامل غير الذي في `PATH` (شائع على cPanel)، مرّره صراحةً:

```cron
* * * * * VODNOX_PHP=/opt/cpanel/ea-php82/root/usr/bin/php /bin/bash /home/ed7sm1twhe8d/public_html/vodnox.com/deploy/cpanel/queue-worker.sh >> /dev/null 2>&1
```

5. احفظ. لا حاجة إلى `queue:restart` — هذا السطر هو الذي يشغّل العامل فعلاً.

> **لا تنشر مساراً عاماً على الويب لتشغيل العامل.** التشغيل عبر Cron فقط.

---

## 2. التحقق

```bash
# 1) حالة الطابور: الاتصال + المنتظرة + الفاشلة (بلا أسرار أو payloads)
php artisan vodnox:queue-status
php artisan vodnox:queue-status --json

# 2) آخر تشغيل ناجح للعامل (يُحدَّث كل دقيقة عند نجاح Cron)
cat storage/framework/vodnox-queue-worker.heartbeat

# 3) مخرجات العامل (أسماء المهام فقط — بلا payloads)
tail -n 40 storage/logs/vodnox-queue-worker.log
```

- **heartbeat يتقدم كل دقيقة** ⇒ Cron يعمل والعامل ينهي دورته بنجاح.
- **heartbeat ثابت** ⇒ Cron غير مضاف أو PHP غير موجود أو المشروع غير قابل للكتابة.
- **`pending` لا ينزل** ⇒ العامل لا يستهلك الطابور: راجع `worker.log`.

---

## 3. الضبط (متغيّرات بيئة في سطر Cron)

| المتغيّر | الافتراضي | المعنى |
| --- | --- | --- |
| `VODNOX_APP_DIR` | جذر المشروع (يُشتق من موقع السكربت) | مسار مطلق لمشروع Laravel. |
| `VODNOX_PHP` | يُكتشف تلقائياً | مسار PHP التنفيذي المطلق. |
| `VODNOX_QUEUE` | `default` | اسم الطابور. |
| `VODNOX_JOB_TIMEOUT` | `60` | مهلة المهمة الواحدة. **يجب أن تبقى < `retry_after`.** |
| `VODNOX_QUEUE_RETRY_AFTER` | `90` | نسخة من `DB_QUEUE_RETRY_AFTER` في `config/queue.php`؛ تُستخدم للتحقق. |
| `VODNOX_TRIES` | `3` | عدد المحاولات قبل نقل المهمة إلى `failed_jobs`. |
| `VODNOX_MAX_TIME` | `55` | أقصى مدة للدورة الواحدة (ثوانٍ). |
| `VODNOX_SLEEP` | `3` | مهلة الانتظار بين الاستطلاعات. |
| `VODNOX_WORKER_LOG` | `storage/logs/vodnox-queue-worker.log` | سجل VODNOX الخاص. |
| `VODNOX_WORKER_LOG_MAX_BYTES` | `5242880` | عند تجاوزه يُدوَّر السجل إلى `.1`. |

**علاقة `retry_after` بالمهل:** `retry_after` (90s) هو وقت إعادة إتاحة المهمة
المحجوزة. لذلك `--timeout` (60s) يبقى أقل منه حتى لا تُعاد المهمة لعامل آخر
وهي ما تزال قيد التنفيذ (ما يسبب معالجة مزدوجة). السكربت **يرفض** العمل إن كان
`VODNOX_JOB_TIMEOUT >= VODNOX_QUEUE_RETRY_AFTER`.

**المهام الفاشلة:** تُسجَّل عبر `queue.failed` كـ`database-uuids` في جدول
`failed_jobs`؛ يظهر عددها في `vodnox:queue-status`. أعد تشغيلها عند الحاجة بـ
`php artisan queue:retry all` (لا تُشغَّل تلقائياً).

---

## 4. قيود مقصودة

- **لا `pkill`، ولا إشارات لأي عملية أخرى.** السكربت لا يمسّ عمال أي مشروع آخر.
- **لا مسار ويب لتشغيل العامل**؛ Cron فقط.
- **بلا أسرار في السجل:** `queue:work` يطبع أسماء أصناف المهام فقط، لا `payload`.
- **لا ادّعاء بتشغيل دائم بعد إغلاق الجلسة:** هذا نموذج cron فلا يوجد «خدمة»
  دائمة؛ كل دقيقة تُشغَّل دورة جديدة.

---

## 5. إن لم تملك صلاحية إضافة Cron

جهّز الملفات كما هي هنا، وأبلغ المالك بأن **التفعيل اليدوي ما زال مطلوباً**.
حتى ذلك الحين، يمكن تشغيل دورة واحدة يدوياً للاختبار:

```bash
php /home/ed7sm1twhe8d/public_html/vodnox.com/deploy/cpanel/queue-worker.sh
```
