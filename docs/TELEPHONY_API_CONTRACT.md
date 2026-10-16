# عقد بوابة الاتصالات (TELEPHONY API CONTRACT)

> **الحالة الحالية: محاكاة صريحة.** لا يوجد اتصال فعلي بأي سيرفر اتصالات
> (Asterisk/SIP/WebRTC) حتى توفّر المؤسسة بيانات الربط. غياب البيانات لا يمنع
> عمل بقية التطبيق.

---

## 1. نظرة عامة

يتكوّن التكامل من مسارين:

| الاتجاه | الآلية | الملف |
|---------|--------|-------|
| **صادر** (VODNOX ← سيرفر الاتصالات) | `TelephonyGateway` + `ProvisionTelephonyJob` | [TelephonyGateway.php](file:///Users/hayderalkhafaji/Documents/trae_projects/vodnox/app/Contracts/Telephony/TelephonyGateway.php) |
| **وارد** (سيرفر الاتصالات ← VODNOX) | Webhook موقّع HMAC-SHA256 | [TelephonyWebhookController.php](file:///Users/hayderalkhafaji/Documents/trae_projects/vodnox/app/Http/Controllers/Api/V1/TelephonyWebhookController.php) |

الربط (binding) في `AppServiceProvider`:
- إذا وُجد صف في `telephony_servers` بحالة `connected` ولديه `host` ⇒
  `HttpTelephonyGateway`.
- خلاف ذلك ⇒ `SimulatedTelephonyGateway` (الافتراضي).

---

## 2. وضع المحاكاة

`SimulatedTelephonyGateway` لا يجري أي اتصال شبكي، ويعيد ردوداً حتمية موسومة:

```json
{
  "simulated": true,
  "operation": "provision_extension",
  "reference": "sim-3f9c1a2b7e4d0f11",
  "payload": { "...": "..." }
}
```

`testConnection()` تعيد `{"ok": true, "status": "simulated", "message": "Simulation mode: no telephony server configured."}`.

---

## 3. العقد الصادر (TelephonyGateway)

جميع الدوال تستقبل `array $payload` وتعيد `array`:

| الدالة | الغرض | مثال payload |
|--------|-------|--------------|
| `provisionOrganization(array)` | تزويد/مزامنة مؤسسة | `{organization_id, code, name}` |
| `provisionExtension(array)` | تزويد تحويلة/SIP | `{organization_id, extension, sip_secret, user}` |
| `provisionIvr(array)` | تزويد قائمة IVR وخياراتها | `{organization_id, menu, options[]}` |
| `provisionQueue(array)` | تزويد طابور وأعضائه | `{organization_id, queue, members[]}` |
| `testConnection()` | فحص الاتصال/التوثيق | — ⇒ `{ok, status, message?}` |
| `isSimulated()` | هل البوابة محاكاة؟ | ⇒ `bool` |

### Adapter الحقيقي — `HttpTelephonyGateway`

يُبنى من صف الخادم (لا من الطلب):

| العملية | المسار | الطريقة |
|---------|--------|---------|
| Organization | `/organizations` | POST |
| Extension | `/extensions` | POST |
| IVR | `/ivr-menus` | POST |
| Queue | `/queues` | POST |
| Health | `/health` | GET |

- المصادقة: `Authorization: Bearer <ami_secret>`.
- `timeout` من `config('integrations.defaults.timeout')`.
- يعتمد `Accept: application/json` ويرفع استثناءً عند الفشل.

---

## 4. التزويد (Provisioning Job)

`ProvisionTelephonyJob` (`app/Jobs/ProvisionTelephonyJob.php`):

- **Idempotency**: مفتاح `idempotency_key` فريد في `provisioning_jobs`؛ صف
  بحالة `synced` يوقف إعادة التنفيذ.
- **Retry/Backoff**: `tries = 3`، `backoff = [10, 30, 60]` ثانية.
- **الحالات**: `pending` → `processing` → `synced` / `failed` (مع `error_message`).
- عند النجاح يُحدَّث `extensions.status = provisioned` لنفس المؤسسة فقط.

```php
ProvisionTelephonyJob::dispatch(
    type: 'provision_extension',
    organizationId: $org->id,
    payload: [...],
    idempotencyKey: 'ext-'.$extension->id.'-'.$extension->updated_at->timestamp,
    extensionId: $extension->id,
);
```

---

## 5. Webhook الوارد

### 5.1 المسار

```
POST /api/v1/telephony/webhook
Header: X-VODNOX-Signature: <hex hmac-sha256>
Content-Type: application/json
```

- المسار **عام** لكنه موثّق بالتوقيع، و**خارج** مجموعة `throttle:api`.
- يُرجع JSON دائماً (لا HTML ولا redirect) — R13.

### 5.2 التوقيع

```
signature = HMAC-SHA256(raw_request_body, TELEPHONY_WEBHOOK_SECRET)   // lowercase hex
```

يُقارن بـ `hash_equals` (مقارنة زمن ثابت). السر يُقرأ من
`config('integrations.telephony.webhook_secret')` ⇐ `env('TELEPHONY_WEBHOOK_SECRET')`.

مثال توليد التوقيع:

```bash
BODY='{"event_id":"evt_1001","event_type":"call.started","organization_id":1}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$TELEPHONY_WEBHOOK_SECRET" -hex | awk '{print $2}')
curl -X POST https://<host>/api/v1/telephony/webhook \
  -H "Content-Type: application/json" \
  -H "X-VODNOX-Signature: $SIG" \
  --data "$BODY"
```

### 5.3 الحِمل (Payload)

| الحقل | إلزامي | الوصف |
|-------|--------|-------|
| `event_id` | ✅ | معرّف فريد للحدث — مفتاح الـ idempotency |
| `event_type` | ➖ | نوع الحدث (افتراضي `unknown`) |
| `organization_id` | ➖ | يُتحقق من وجوده؛ إن كان مجهولاً ⇒ يُخزَّن `null` (لا نثق بالحِمل) |
| `occurred_at` | ➖ | وقت الحدث (يُفسَّر، وإلا `now()`) |
| بقية الحقول | ➖ | تُخزَّن كما هي في `webhook_events.payload` |

### 5.4 الاستجابات

| الحالة | الشرط | الجسم |
|--------|-------|-------|
| `200` | توقيع صالح + `event_id` صالح | `{success:true, data:{event_id, status, duplicate}}` |
| `401` | توقيع مفقود/غير صالح | `{success:false, message, data:null, errors:null}` |
| `422` | JSON غير صالح أو `event_id` مفقود | `{success:false, errors:{event_id:[...]}}` |
| `503` | `TELEPHONY_WEBHOOK_SECRET` غير مهيأ | `{success:false, message:"بوابة الاتصالات غير مهيأة بعد."}` |

### 5.5 Idempotency

- قيد فريد على `webhook_events.event_id`.
- تكرار نفس `event_id` ⇒ لا صف ثانٍ؛ تُعاد الاستجابة مع `duplicate: true`.
- في حال سباق تزامني، يلتقط الكود `QueryException` ويعيد الصف الفائز.
- الـ webhook **بلا آثار جانبية**: لا يُنشئ مكالمات ولا `usage` ولا أي سجل آخر
  عدا `webhook_events`.

---

## 6. الأمان

- بيانات اعتماد الخادم (`ami_secret`) مُشفَّرة في قاعدة البيانات
  (`encrypted` cast) ومخفيّة من التسلسل (`$hidden`) — لا تظهر في أي استجابة.
- `TELEPHONY_WEBHOOK_SECRET` و`ASTERISK_*` تُقرأ من `.env` فقط، و`.env.example`
  لا يحوي أي قيمة سرية (R12).
- لا تُقرأ أي بيانات اعتماد من الطلب.

---

## 7. متطلبات تفعيل الربط الفعلي

لتشغيل الاتصال الحقيقي لاحقاً يلزم تزويد التطبيق بـ:

1. `host` و`port` و`connection_method` لسيرفر الاتصالات.
2. `ami_username` و`ami_secret` (تُدخل من لوحة السوبر أدمن وتُشفَّر).
3. `TELEPHONY_WEBHOOK_SECRET` المتفق عليه لتوقيع الأحداث الواردة.
4. ضبط حالة الخادم على `connected` (عبر زر الفحص في `/admin/servers`).

عندها يتحول الربط تلقائياً إلى `HttpTelephonyGateway` دون أي تغيير في الكود.

---

## 8. الاختبارات المرتبطة

`tests/Feature/Api/TelephonyWebhookTest.php` (8 اختبارات):
رفض بلا تهيئة (`503`)، رفض بلا توقيع، رفض توقيع غير صالح، قبول توقيع صالح،
idempotency لحدث مكرر، رفض `event_id` ناقص، `organization_id` مجهول ⇒ `null`،
`organization_id` معروف ⇒ يُحفظ.
