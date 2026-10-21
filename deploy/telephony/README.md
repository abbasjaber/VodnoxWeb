# VODNOX — الربط الحقيقي مع Asterisk (المكالمات الداخلية)

هذا المجلد هو حزمة النشر وخدمة التزويد (provisioning) التي تربط تطبيق VODNOX
بسيرفر Asterisk، بحيث تصبح المكالمات الداخلية بين موظفي المؤسسة وظيفة فعلية:
ينشئ السوبر أدمن مؤسسة ويفعّلها → مسؤول المؤسسة ينشئ الموظفين → ينشئ النظام تلقائياً
حساب اتصال SIP مستقلاً لكل موظف → يتصل الموظفون ببعضهم من المتصفح بصوت حقيقي
باتجاهين.

**النطاق: مكالمات صوتية داخلية فقط.** لا Trunks، لا أرقام عامة، لا تسجيل مكالمات،
لا فوترة، لا AI. المكالمات الداخلية الأساسية لا تتطلب أي اشتراك (CRM / FORMS /
Smart IVR) ولا تظهر إعدادات المؤسسة أو الاشتراكات للموظف.

---

## 1. المكوّنات

| المسار | الوظيفة |
| --- | --- |
| `provisioning/src/Agent.php` | خدمة التزويد (PHP 8.2 بلا تبعيات): تتحقق من التوقيع، تمنع التكرار (nonce + idempotency + flock)، وتكتب إعدادات Asterisk ذرّياً. تُصدر كذلك `/capabilities` و`/gateway-lines` و`/gateway-status`، والإصدار الحالي **`1.3.0`**. |
| `provisioning/public/index.php` | نقطة الدخول الوحيدة للخدمة؛ ترفض أي طلب بلا توقيع صحيح. |
| `provisioning/config.example.php` | قالب الإعدادات؛ يُنسخ إلى `config.php` ويحمل السرّين فقط. |
| `events/src/*.php` | **مُرسل أحداث المكالمات**: يقرأ AMI على loopback، يحوّل الأحداث إلى مفردات VODNOX، يخزّنها على القرص، ويرسلها موقّعة إلى webhook التطبيق. |
| `events/bin/run.php` | نقطة دخول مُرسل الأحداث (خدمة systemd مستقلة). |
| `events/config.example.php` | قالب إعدادات المُرسل (سري AMI وسري الـwebhook). |
| `events/systemd/vodnox-telephony-events.service` | وحدة systemd لمُرسل الأحداث. |
| `systemd/vodnox-provisioning.service` | خدمة php-fpm مقيّدة الصلاحيات (hardening) تشغّل الوكيل. |
| `php-fpm/vodnox-provisioning.conf` | Pool باسم `vodnox` يعمل بمستخدم `asterisk`، `open_basedir` و`disable_functions` مقيّدة. |
| `nginx/vodnox-provisioning.conf` | Reverse proxy على 443 للوكيل؛ يقبل **قائمة سماح صريحة** من ملف خارجي `include /etc/nginx/vodnox-allow.conf` (بلا أي شبكة مفترضة). |
| `nginx/vodnox-allow.conf.example` | قالب قائمة السماح؛ بلا `allow` يبقى الوكيل مغلقاً أمام الجميع (fail-closed). |
| `asterisk/pjsip.conf` | تعريف `transport-wss` + تضمين `vodnox/generated/*.conf`. |
| `asterisk/http.conf.snippet` | تفعيل WebSocket + WSS بـ TLS لـ SIP.js (`bindaddr`/`bindport` بالصيغة الصحيحة). |
| `asterisk/extensions.conf.snippet` | توثيق تضمين `vodnox/dialplan/*.conf` وقاعدة العزل. |
| `asterisk/manager.conf.snippet` | حساب AMI **للقراءة فقط** على loopback لمُرسل الأحداث. |
| `asterisk/rtp.conf.snippet` | قالب قسم `[ice_host_candidates]` لحلّ انعدام الصوت خلف NAT (بلا STUN). القيم لكل سيرفر على حدة. |
| `bin/install.sh` | تثبيت idempotent من 11 خطوة، **يكتشف إصدارات PHP** بدل افتراضها. |
| `bin/backup.sh` | نسخة احتياطية لـ `/etc/asterisk` وحالة الوكيل (احتفاظ 14 نسخة). |
| `bin/rollback.sh` | استرجاع نسخة سابقة مع نسخة وقائية قبل الاسترجاع. |
| `bin/ice-candidates.sh` | كتابة `[ice_host_candidates]` بأمان: تحقق + نسخة احتياطية + منع تكرار القسم + كتابة ذرّية + `module reload` فقط. |
| `certbot/vodnox-telephony-deploy-hook.sh` | deploy hook لـCertbot: يتحقق من الشهادة والمفتاح، ينسخ ذرّياً بالصلاحيات، و`core reload` بلا إعادة تشغيل. |
| `bin/package.sh` | بناء حزمة النشر من **قائمة ملفات مسموحة** وفحص محتواها قبل التسليم. |
| `.env.example` | متغيّرات الجهة التطبيقية (بلا أسرار). |

**ما يكتبه الوكيل على السيرفر (المولّد آلياً):**

- `/etc/asterisk/vodnox/generated/extension_<sip_username>.conf` — `aor` + `auth` +
  `endpoint` للـ WebRTC (`webrtc=yes`, `media_encryption=dtls`,
  `dtls_auto_generate_cert=yes`, `ice_support=yes`, `rtcp_mux=yes`, `bundle=yes`,
  `direct_media=no`, `force_rport=yes`, `rewrite_contact=yes`, `rtp_symmetric=yes`).
- `/etc/asterisk/vodnox/generated/gateway_<sip_username>.conf` — `aor` + `auth` +
  `endpoint` **SIP/UDP تقليدي** لخط بوابة GSM/GoIP: `transport=transport-udp`،
  `ulaw,alaw`، `direct_media=no`، `rtp_symmetric=yes`، `force_rport=yes`،
  `rewrite_contact=yes`، `dtmf_mode=rfc4733`، `max_contacts=1`، وبلا `webrtc`
  وبلا DTLS. **لا يُنشأ transport جديد**: يُستخدم `5060/udp` المهيّأ مسبقاً.
- `/etc/asterisk/vodnox/dialplan/dialplan_<context>.conf` — سياق معزول لكل مؤسسة.
- `/etc/asterisk/vodnox/dialplan/gateway_<context>.conf` — سياق **وارد** مخصّص لكل
  خط بوابة (`vodnox_gw_<org>_<line>`)؛ يوجّه إلى التحويلة صاحبة الوجهة فقط ويرفض
  أي رقم آخر، ولا يوجد catch-all يعبر إلى سياق ثانٍ.
- `/var/lib/vodnox-telephony/state/` — سجلات idempotency وnonces وحالة المؤسسات/الأرقام،
  وحالة خطوط البوابة في `state/gateways.json`.

**لا يُعاد تشغيل Asterisk عند إنشاء موظف.** يُنفَّذ `pjsip reload` و`dialplan reload`
فقط (و`core reload` عند تغيير قالب HTTP — لا وجود للأمر `http reload` في Asterisk 22).

---

## 2. حقائق السيرفر (كما وردت من فريق التشغيل)

| العنصر | القيمة | الحالة |
| --- | --- | --- |
| Public IP | `38.3.184.246` | مؤكّد |
| Hostname | `ho-cp-uat` | مؤكّد |
| IP داخلي | `172.16.100.20` | مؤكّد |
| إصدار Asterisk | `22.5.2~dfsg` | مؤكّد |
| HTTP الداخلي (8088) | Disabled | مؤكّد |
| 5060/udp و5061/tcp | يعملان (5061 ليس TLS) | مؤكّد |
| `res_http_websocket` + `res_pjsip_transport_websocket` | تعملان | مؤكّد |
| سياق قديم `vodnox-test` | `webrtc=no` | مؤكّد — لا يُعتمد عليه ولا يُربط تلقائياً |
| النطاق `pbx.vodnox.com` | **مقترح غير مؤكّد** | يحتاج تأكيد DNS وشهادة |
| SSH / مفاتيح الوصول | غير متوفرة | **لم يُنفَّذ أي عمل حقيقي على السيرفر** |

حسابا `1001` / `1002` القديمان هما أثر اختبار سابق. **لم ولن يُربطا تلقائياً**، ولا
توجد أي بيانات ثابتة لهما في الكود.

---

## 3. جدول المنافذ والخدمات (بلا أسرار)

| المنفذ | البروتوكول | الخدمة | الجهة المسموح لها |
| --- | --- | --- | --- |
| `443/tcp` | HTTPS | وكيل التزويد (nginx → php-fpm) | **عنوان خروج استضافة Laravel فقط** — يُحدَّد من فحص المتطلبات (§11)، وليس `172.16.100.0/24` |
| `8089/tcp` | WSS (TLS) | إشارات SIP من المتصفح (SIP.js) | الموظفون (الإنترنت) |
| `8088/tcp` | HTTP | HTTP الداخلي لـ Asterisk | `127.0.0.1` فقط (Disabled حالياً) |
| `5060/udp` | SIP | PJSIP الحالي | كما هو — غير مستخدم من WebRTC |
| `5061/tcp` | SIP/TCP | PJSIP الحالي (ليس TLS) | كما هو — غير مستخدم من WebRTC |
| `3478/udp`, `3478/tcp` | STUN/TURN | خادم TURN (عند الحاجة) | الموظفون (الإنترنت) |
| `49152–65535/udp` | RTP/RTCP | الوسائط | الموظفون (الإنترنت) |
| `22/tcp` | SSH | إدارة | مسؤولون فقط، بمفاتيح |
| `3306/tcp` | MySQL | قاعدة بيانات VODNOX | محلي فقط |

> الوسائط على WebRTC تُشفَّر بـ **DTLS-SRTP**، والاتصال لا يمرّ على `5060/5061`.

---

## 4. الطلبات التي يرسلها الوكيل (عقد التزويد)

كل طلب يحمل: `Authorization: Bearer <TOKEN>`، و`X-VODNOX-Signature: sha256=<hmac>`
حيث HMAC-SHA256 على `timestamp.nonce.<raw body>`، مع `X-VODNOX-Timestamp`،
`X-VODNOX-Nonce`، و`Idempotency-Key`. الطلبات الأقدم من نافذة 300 ثانية تُرفض،
وكل nonce يُستهلك مرة واحدة.

| الطريقة | المسار | الغرض |
| --- | --- | --- |
| `GET` | `/health` | فحص الحالة |
| `POST` | `/organizations` | إنشاء/تحديث سياق مؤسسة + إعادة كتابة الـ dialplan |
| `DELETE` | `/organizations/{context}` | حذف سياق مؤسسة وكل حساباتها |
| `POST` | `/extensions` | إنشاء/تحديث Endpoint/Auth/AOR + سطر في الـ dialplan |
| `DELETE` | `/extensions/{sip_username}` | إزالة حساب SIP |
| `GET` | `/capabilities` | إصدار الوكيل + قدراته المدعومة (negotiation؛ التطبيق لا يدّعي مزامنة غير موجودة) |
| `POST` | `/gateway-lines` | إنشاء/تحديث خط بوابة GSM: Endpoint/Auth/AOR بـSIP/UDP + سياق وارد مخصّص |
| `DELETE` | `/gateway-lines/{sip_username}` | إزالة خط البوابة وحسابه وسياقه الوارد |
| `POST` | `/gateway-status` | قراءة حالة تسجيل خطوط البوابة من `pjsip show contacts` (حدّ أقصى 50 خطاً) |
| `*` | `/ivr-menus`, `/queues` | **501** — خارج النطاق حالياً |

**ملكية خط البوابة تُحسم دائماً من الحالة المحفوظة على السيرفر:** أي `gateway-lines`
لخط مسجّل لمؤسسة أخرى يُرفض بـ403، ولا يُقبل `organization_id` من أي حمولة.

**العزل:** يُشتق الـ endpoint دائماً من قاعدة بيانات VODNOX
(`extensions.sip_username`) لا من أي ترويسة أو `caller_id` أو `organization_id`
قادم من المتصفح. لا يوجد route عام catch-all، وكل سياق يحوي أرقام مؤسسته فقط؛
أي رقم غير معرّف داخل السياق يعيد `Hangup(404)`، والمؤسسة المعطّلة تعيد
`Hangup(403)`.

---

## 5. خطوات التركيب على السيرفر

```bash
# 1) على سيرفر الوسائط، من جذر المستودع:
sudo deploy/telephony/bin/install.sh

# 2) اضبط أسرار الوكيل ومُرسل الأحداث (كل سرّ يجب أن يطابق نظيره في .env بالتطبيق):
sudo editor /opt/vodnox-telephony/config.php
#    token          == TELEPHONY_PROVISIONING_TOKEN
#    signing_secret == TELEPHONY_PROVISIONING_SIGNING_SECRET

sudo editor /etc/vodnox-telephony-events/config.php
#    ami.secret     == سرّ حساب [vodnox-events] في manager.conf
#    webhook.secret == TELEPHONY_WEBHOOK_SECRET

#    ثمّ ضع عنوان خروج استضافة Laravel في قائمة السماح (نتيجة الفحص §11):
sudo editor /etc/nginx/vodnox-allow.conf      # allow <egress-of-vodnox.com>;  ثم: sudo systemctl reload nginx

# 3) أضف الأسطر التالية مرة واحدة (install.sh لا يعدّل Asterisk):
#    /etc/asterisk/pjsip.conf      -> #include vodnox/pjsip.conf
#    /etc/asterisk/extensions.conf -> #include vodnox/dialplan/*.conf
#    ثم ادمج asterisk/http.conf.snippet وasterisk/manager.conf.snippet في ملفيهما
#    (account [vodnox-events] للقراءة فقط على loopback، بـ secret من config.php)

# 4) أعد التحميل بلا إعادة تشغيل:
sudo asterisk -rx 'manager reload'
sudo asterisk -rx 'pjsip reload'
sudo asterisk -rx 'dialplan reload'
sudo asterisk -rx 'core reload'      # يقرأ http.conf (الشهادة/WSS) بلا قطع المكالمات

#    ملاحظة: لا وجود للأمر 'http reload' في Asterisk 22؛ استخدم 'core reload'.
#    تغيير bindaddr/bindport في http.conf يُربَط عند تحميل الوحدة، وإن لم يظهر
#    مستمع WSS بعده فاللازم إعادة تشغيل واحدة في نافذة صيانة:
#        sudo asterisk -rx 'core restart when convenient'
#    لا إعادة تشغيل تلقائية، ولا أثناء مكالمة.
```

**إعادة التشغيل الأولية (مرة واحدة فقط):** تغيير `bindaddr`/`bindport` في
`http.conf` يُربَط عند تحميل الوحدة، لا عند `core reload`. إن لم يظهر مستمع WSS
على 8089 بعد `core reload`، نفّذ **إعادة تشغيل واحدة** لـ Asterisk في نافذة صيانة:
`sudo asterisk -rx 'core restart when convenient'` (ينتظر انتهاء المكالمات
الجارية). هذه الإعادة تخصّ تغيير قالب HTTP وحده، و**لا تحدث عند إنشاء موظف** —
التزويد اليومي يستخدم `pjsip reload` و`dialplan reload` فقط.

`install.sh` يلتقط نسخة احتياطية قبل أي تعديل، وينشئ المجلدات القابلة للكتابة،
ويثبّت nginx/php-fpm/systemd، ويشغّل الخدمة. إعادة تشغيله آمنة ولا تلمس
الإعدادات المولّدة.

---

## 5.1 تحديث وكيل التزويد إلى `1.3.0` (بوابات GSM/GoIP + توجيه الوارد)

**هذه أهم خطوة يبقى على المشغّل تنفيذها.** الوكيل المركَّب حالياً على السيرفر
(`38.3.184.246`) هو إصدار ما قبل البوابات: لا يملك `/capabilities` ولا
`/gateway-lines` ولا `/gateway-status`. لذلك تظهر في واجهة «بوابات الاتصال» رسالة
«خدمة الربط على الخادم لا تدعم خطوط البوابة بعد»، ولا يُعرض أي خط كـ«مُزامَن» —
وهذا **سلوك صادق لا خطأ**، ومقصود: لا يُعلَن نجاح مزامنة لم تحدث.

**ما الجديد في `1.3.0`:** `GET /capabilities`، `POST/DELETE /gateway-lines`،
`POST /gateway-status`، وملفا `gateway_*.conf` (endpoint بـSIP/UDP + سياق وارد
مخصّص لكل خط). وفوق ذلك قدرة **`gateway_routing`**: يترجم الوكيل **كامل** مجموع
قواعد المنفذ (التحويل المؤقت، استثناءات التواريخ، نهاية الأسبوع، ساعات العمل،
خارج ساعات العمل، الوجهة الافتراضية) إلى dialplan ويقرّر وقت المكالمة بنفس
ترتيب الأولوية الذي تعرضه المعاينة، ويرفض المكالمة (`Hangup(480)`) بلا تخمين عند
غياب أي وجهة صالحة. **لا تغيير** على عقد الموظفين القائم (`/organizations`,
`/extensions`) ولا على `config.php`؛ لذلك **لا تُلمس الأسرار ولا `config.php` ولا
الحالة ولا الملفات المولّدة**.

**الحزمة تأتي من CI:** كل تشغيل ناجح على `main` يرفع artifact باسم
`vodnox-telephony-agent` يحوي `vodnox-telephony-<UTC>.tar.gz` و
`vodnox-telephony-<UTC>.tar.gz.sha256` (وبصمة SHA-256 مطبوعة في سجل التشغيل مع
`agent version : 1.3.0`).

```bash
# 0) نزّل الحزمة: GitHub → Actions → آخر تشغيل ناجح على main → Artifacts.

# 1) على سيرفر الوسائط: تحقّق من البصمة أولاً، ثم فكّ ثم ثبّت الملفات فقط.
sha256sum -c vodnox-telephony-<UTC>.tar.gz.sha256
sudo tar -xzf vodnox-telephony-<UTC>.tar.gz -C /tmp/vodnox-agent-<UTC>

#    نسخة احتياطية واحدة قبل الاستبدال (تشمل /etc/asterisk والحالة معاً):
sudo deploy/telephony/bin/backup.sh pre-agent-update

sudo cp -a /tmp/vodnox-agent-<UTC>/provisioning/src/. /opt/vodnox-telephony/src/
sudo chown -R root:asterisk /opt/vodnox-telephony/src

#    ملاحظة: الخطوة أعلاه تنسخ `provisioning/src` فقط. لا يُكتب أي شيء في
#    /etc/asterisk ولا في مجلدات الوكيل المولّدة، لذا يبقى أي **حساب GoIP
#    مُعدّ يدوياً** (اسم المستخدم وكلمة المرور والـtransport ورقم الوارد
#    والـcontext اليدوي) وإعداداته كما هي بلا أي مساس. الوكيل يملك فقط
#    الملفات المشتقّة من حالة داخلية: generated/gateway_<username>.conf
#    وdialplan/gateway_<context>.conf، ولا يحذف أو يستبدل ملفاً لا يعرف اسمه.

#    تحقّق أن الملف المستقرّ فعلاً هو الإصدار الجديد:
grep -m1 "const VERSION" /opt/vodnox-telephony/src/Agent.php
#    المتوقّع: public const VERSION = '1.3.0';

# 2) أعد تحميل وحدة الوكيل المحددة فقط (وحدة install.sh نفسها؛ لا إعادة تشغيل
#    Asterisk ولا php-fpm العام، ولا أي أثر على اتصالات أو حسابات قائمة):
sudo systemctl reload vodnox-provisioning.service
sudo systemctl is-active vodnox-provisioning.service   # المتوقّع: active
```

**الدليل السلوكي (لا يكفي `grep` وحده):** من واجهة المؤسسة افتح «بوابات الاتصال»
— يجب أن تختفي رسالة «خدمة الربط لا تدعم خطوط البوابة بعد»، وأن يعمل زر
«تحديث الحالة» ويعرض «مسجّل / لا يوجد تسجيل / غير معروفة» من قراءة حقيقية
لمخاطبات PJSIP. كل مسارات الوكيل موقّعة (بما فيها `/capabilities`)، فلا تُختبر
بـ`curl` بسيط بلا توقيع — وستعيد `401 unauthorized`، وهذا متوقّع.

**الرجوع (مرة واحدة، إن لزم):**

```bash
sudo deploy/telephony/bin/rollback.sh <archive>   # يستعيد /etc/asterisk والحالة
#    ثم أعد ملفات الوكيل السابقة إلى /opt/vodnox-telephony/src و:
sudo systemctl reload vodnox-provisioning.service
```

الرجوع آمن بلا إعادة تشغيل: الطلب على مسار غير موجود يعيد 404، فيُبلغ التطبيق
«يلزم تحديث خدمة الربط» بدل ادّعاء نجاح، وتظل المكالمات الداخلية للموظفين تعمل
كما هي.

> **نطاق الـCI:** خطوة النشر ترفع كود Laravel إلى cPanel فقط. **الـCI لا يكتب
> `/etc/asterisk` ولا يحدّث الوكيل ولا يفتح منفذاً.** الحزمة أعلاه هي التسليم،
> وتركيبها (وكذلك المراجعة والرجوع) خطوة يدوية واحدة محقّقة بالأوامر أعلاه.

**شرط تهيئة واحد لا يتكرّر لكل بوابة:** يجب أن يقبل `5060/udp` على العنوان العام
`38.3.184.246` تسجيل الأجهزة القادم من مواقع العملاء — يُفتح **مرة واحدة** عند
تشغيل أول بوابة (توسيع قاعدة الجدار القائمة، لا إنشاء transport جديد ولا إعادة
تشغيل Asterisk). الخط المستخدم هو نقل PJSIP الموجود أصلاً؛ النظام **لا يُنشئ ولا
يعيد تشغيل أي transport** عند إضافة Gateway.

**ملاحظة جهاز GoIP المعتمد:** الملف المجرَّب `Firmware GHSFVT-1.1-68-9` بخط واحد،
و`Call In via GSM = Enable`، و`Media Encryption = None` مع `SIP/UDP` — وُصف بذلك
كما هو، ولا يُوصف بأنه مشفّر. لا ترقية تلقائية ولا reset للجهاز من النظام.

---

## 6. الجهة التطبيقية (Laravel)

```dotenv
TELEPHONY_ENABLED=true
TELEPHONY_PROVISIONING_URL=https://pbx.vodnox.com
TELEPHONY_PROVISIONING_TOKEN=<shared>
TELEPHONY_PROVISIONING_SIGNING_SECRET=<shared>
TELEPHONY_WEBHOOK_SECRET=<shared with the media server>
TELEPHONY_SIP_DOMAIN=pbx.vodnox.com
TELEPHONY_SIP_WSS_URL=wss://pbx.vodnox.com:8089/ws
TELEPHONY_SIP_ICE_URL=stun:pbx.vodnox.com:3478
TELEPHONY_TURN_URL=            # عند الحاجة فقط
TELEPHONY_TURN_USERNAME=
TELEPHONY_TURN_CREDENTIAL=
```

مطلوب كذلك:

- **Queue worker** — التهيئة تُنفَّذ في الطابور:
  `php artisan queue:work --queue=default` (يُنصح بمشرف systemd/supervisor مع
  `--tries=3`). بدونه تبقى الحسابات `pending` ولا تُزامَن.
- **Routes** — `POST /api/v1/telephony/webhook` يستقبل أحداث المكالمات ويجب أن
  يكون متاحاً لسيرفر الوسائط، موقّعاً على **نصّ الطلب الخام** بـ
  `X-VODNOX-Signature`. الحدث المكرّر (`event_id`) لا يُطبَّق مرتين، والأحداث
  تأتي بترتيب (ringing → answered → ended) ولا يُنشأ سجل مكالمة من حدث انتهاء وحده.

**الأسرار لا تصل للمتصفح:** نقطة `/user/telephony/credentials` تُعيد للتوثيق
الحالي فقط بياناته، بترويسة `Cache-Control: no-store`، ولا تُخزَّن في
`localStorage` أو السجلات أو Git. كلمات مرور SIP **لا تُكتب أبداً** في
`provisioning_jobs` (تُقرأ مشفّرة وقت تنفيذ الـ job).

---

## 7. DNS والشهادة وWSS وNAT/RTP وTURN

1. **DNS**: سجل `A` للنطاق `pbx.vodnox.com` → `38.3.184.246`. لا تُستخدم الشهادة
   أو WSS قبل تأكيد هذا السجل.
2. **الشهادة**: شهادة موثوقة (Let's Encrypt أو CA المؤسسة) للنطاق نفسه، تُستخدم
   لـ `wss://...:8089/ws` ولوكيل التزويد على 443.
   - الأصل عند Certbot: `/etc/letsencrypt/live/<domain>/{fullchain.pem,privkey.pem}`.
   - النسخ التي يستخدمها Asterisk وNginx:
     `/etc/asterisk/keys/<domain>.fullchain.pem` و`/etc/asterisk/keys/<domain>.key`
     بصلاحيات `/etc/asterisk/keys` = `root:asterisk 0750` والملفين `root:asterisk 0640`.
   - **لا تُعطَّل الشهادة ولا يُتجاوز التحقق** في المتصفح أو في الوكيل.
   - **التجديد**: Certbot يجدّد الأصل تلقائياً، لكن النسخ لا تتحدّث بلا hook.
     ثبّت الـhook المرافق (راجع §7.1) ليُنسخ الأصل بعد كل تجديد ناجح.
   - **تحديث HTTPS/WSS في Asterisk 22**: الطريق المدعوم هو `asterisk -rx 'core reload'`
     — يعيد قراءة `http.conf` (بما فيه `tlscertfile`/`tlsprivatekey`) **دون قطع
     المكالمات الجارية**.
     **لا وجود للأمر `http reload` على هذا السيرفر** — لا تستخدمه.
     و`module unload` لوحدات الهاتف ممنوع، وكذلك أي restart تلقائي أثناء المكالمات.
     إن لم يلتقط مستمع WSS الشهادة الجديدة بعد `core reload`، فالتحديث يحتاج
     **نافذة صيانة**: `asterisk -rx 'core restart when convenient'` (ينتظر انتهاء
     المكالمات). الـhook لا ينفّذ ذلك تلقائياً؛ بل يسجّل تحذيراً صريحاً.

#### 7.1 تركيب deploy hook للشهادات

```bash
# على سيرفر الاتصالات، مرة واحدة:
sudo install -m 0755 deploy/telephony/certbot/vodnox-telephony-deploy-hook.sh \
    /etc/letsencrypt/renewal-hooks/deploy/vodnox-telephony
#    اضبط النطاق إن لزم:  VODNOX_TLS_DOMAIN=pbx.vodnox.com في رأس الملف أو في
#    /etc/default/vodnox-telephony (المتغيّر يُقرأ منه إن وُجد).

# فحص التجديد بلا إصدار شهادة حقيقية (يستدعي الـhook على بيئة staging):
sudo certbot renew --dry-run --cert-name <domain>

# تحقّق من أن النسخ تطابقت:
sudo ls -l /etc/asterisk/keys/
sudo openssl x509 -in /etc/asterisk/keys/<domain>.fullchain.pem -noout -subject -enddate
echo | openssl s_client -connect 127.0.0.1:8089 -servername <domain> 2>/dev/null \
    | openssl x509 -noout -fingerprint -sha256
```

> **إنشاء الملف داخل Git لا يعني تركيبه على السيرفر.** يبقى التركيب خطوة يدوية
> واحدة، ويجب التحقق منها بالأوامر أعلاه.
> نطاق عمل الـhook: يستبدل نسخ `/etc/asterisk/keys` **فقط**، ويتحقق من مطابقة
> المفتاح للشهادة قبل الاستبدال، وإلا يترك الملفات السليمة كما هي.

**ضمانات السلامة (المطبَّقة في المصدر):**

- **لا تغيير لصلاحيات أو ملكية أي مجلد عام.** لا يُنشئ الـhook ولا يعيد ضبط
  `/var/log` أو `/etc` أو `/var/backups` أو `/etc/asterisk`. المجلدات تُنشأ فقط
  إن كانت **غائبة** (وبالصلاحية الموثّقة)، والمجلد الموجود والمختلف يُبلَّغ عنه
  بـ `WARNING` مع الأمر المقترح ولا يُعدَّل خلف ظهر المشغّل.
- **السجل في مجلد خاص:** `/var/log/vodnox-telephony/certbot-deploy.log`
  بصلاحية `0600`، ومجلده `0700`. الأسطر تحمل بصمات وقرارات فقط، ولا تحتوي مادة
  المفتاح إطلاقاً. يمكن تغييره عبر `VODNOX_TLS_LOG`.
- **رفض المسارات العامة:** أي ضبط لـ`VODNOX_ASTERISK_KEYS_DIR` أو
  `VODNOX_BACKUP_DIR` أو مجلد السجل على مسار عام (`/etc`, `/var/log`,
  `/var/backups`, `/etc/asterisk`, …) يُفشل الـhook بخروج غير صفري، مع مقارنة
  المسار ومكافئه بعد حلّ الروابط (`/var/log` ← `/private/var/log` على macOS).
- **فشل `nginx -t` أو فشل الـreload فشل حقيقي:** يُظهر نصّ الخطأ، **يُعيد النسخ
  المستبدَلة إلى ما كانت عليه (rollback)**، ويخرج بغير صفر. النسخ الاحتياطية تبقى
  في `/var/backups/vodnox-telephony` للمشغّل.
- **فحص WSS محدود بمهلة** (`VODNOX_TLS_WSS_TIMEOUT`، افتراضياً 10 ثوانٍ)؛ لا
  يستطيع مقبس معلّق أن يعلّق التجديد.
- **الشهادة المقدَّمة هي المُعتبرة، لا نسخة القرص:** يُتحقق من بصمة الشهادة التي
  يقدّمها المستمع فعلياً في handshake TLS؛ تطابق الملف على القرص وحده لا يُعتبر
  دليلاً على أن WSS يقدّمها.
- **لا `restart` ولا `module unload` تلقائي:** التحديث عبر `core reload` فقط،
  وإن لم يلتقط المستمع الشهادة الجديدة فلا يُدّعى أنه فعل، بل تُطبَع تعليمات نافذة
  الصيانة (`core restart when convenient`) ويُخرج بغير صفر. المكالمات الجارية لا
  تُمَس.

**اختبار السلوك (بيئة معزولة، بلا root، بلا تعديل على النظام):**

```bash
bash deploy/telephony/tests/certbot-deploy-hook.test.sh
# يغطّي: ثبات صلاحيات/ملكية المجلدات الآب، رفض المسارات العامة، الإرجاع عند فشل
# nginx، حدّ مهلة WSS، مطابقة الشهادة المقدَّمة، ومنع أي restart/unload.
# الاختبار مستقلّ عن المضيف: يثبّت stub خاصاً لكل أمر يفحصه الـhook
# (nginx/systemctl/asterisk/id)، فلا يغيّر وجودها أو غيابها على الجهاز النتيجة.
```

3. **WSS**: `http.conf` يفعّل `tlsenable=yes` على `0.0.0.0:8089`؛ يبقى HTTP
   الداخلي على `127.0.0.1:8088` (أو معطّلاً كما هو الآن).
4. **NAT/RTP**: **نطاق RTP لا يُفترض.** اقرأ `rtpstart`/`rtpend` الفعليين من
   `/etc/asterisk/rtp.conf` (§11، الأمر `A12`) وافتح **ذلك النطاق فقط** في الجدار
   موجّهاً للسيرفر؛ إن لم يكن الملف معرّفاً فالقيمة الافتراضية للأسترِسك تُقرأ من
   المخرَج نفسه ولا تُخمَّن. أضف `external_media_address` /
   `external_signaling_address` = `38.3.184.246` في `pjsip.conf`، مع
   `rtp_symmetric=yes` + `rewrite_contact=yes` لكل endpoint، وإن كان السيرفر خلف
   NAT أضف `local_net` وحدود الفتح في `rtp.conf`. لا تُعطَّل الجدار ولا يُفتح مدى
   أوسع من النطاق المقروء.
   - **قسم `[ice_host_candidates]` في `rtp.conf` — سبب نجاح الصوت فعلياً:**
     السيرفر وراء NAT، وكان يعلن عنوانه الداخلي فقط داخل SDP، فكانت المكالمة
     تتصل **بلا صوت باتجاهين**. الحل المطبَّق (وهو حلّ Asterisk الأصلي، بلا حاجة
     إلى خادم STUN خارجي) هو إعلان العنوان العام كـ ICE host candidate:
     ```ini
     [ice_host_candidates]
     172.16.100.20 => 38.3.184.246
     ```
     - `172.16.100.20` = العنوان الداخلي لهذا السيرفر، و`38.3.184.246` = عنوانه
       العام. **هاتان قيمتا هذا السيرفر تحديداً**، وليستا افتراضاً لسيرفرات أخرى؛
       لكل سيرفر زوجه الخاص.
     - طُبِّق بـ`asterisk -rx 'module reload res_rtp_asterisk.so'`، وبعدها صارت
       عدادات RTP تتحرك بالطرفين ونجح الصوت بين جهازين مختلفين.
     - **لا تُزل هذا القسم ولا تستبدله بخدمة STUN غير موجودة.**
     - القالب: `asterisk/rtp.conf.snippet`. والتطبيق الآمن (تحقق + نسخة احتياطية +
       منع تكرار القسم + كتابة ذرّية + `module reload` فقط) عبر:
       ```bash
       sudo deploy/telephony/bin/ice-candidates.sh \
           --private <private-ip> --public <public-ip>
       ```
       إعادة تطبيقه بنفس القيم على هذا السيرفر لا تغيّر شيئاً (idempotent) — آمن
       ولا يكتب فوق الإعداد العامل بغير مبرر.
     - متغيّر STUN في الجهة التطبيقية `TELEPHONY_SIP_ICE_URL` يبقى كما هو؛ لا
       يُعتمد عليه لحلّ NAT السيرفر — الحلّ في `rtp.conf` أعلاه.
5. **TURN**: يُضاف فقط عند وجود موظفين خلف NAT متماثل (symmetric NAT) تفشل فيه
   ICE. يُضاف عنوانه وبياناته عبر `TELEPHONY_TURN_*`، وتظهر تلقائياً في
   `iceServers` التي يتلقاها المتصفح.

---

## 8. النسخ الاحتياطي والاسترجاع

```bash
sudo deploy/telephony/bin/backup.sh pre-change      # نسخة يدوية
sudo ls -1 /var/backups/vodnox-telephony            # آخر 14 نسخة
sudo deploy/telephony/bin/rollback.sh <archive>     # استرجاع (ينسخ الحالة الحالية أولاً)
```

تشمل النسخة `/etc/asterisk` و`/var/lib/vodnox-telephony` معاً، لأن الـ dialplan
المولّد وحالة الأرقام يجب أن يُستَرجعا كنقطة واحدة متسقة.

---

## 9. خطوات اختبار القبول

نفّذها بالترتيب؛ لا تستخدم `1001/1002` القديمين، بل مؤسسة وموظفين **جدد** من
واجهات Laravel.

1. سوبر أدمن: أنشئ مؤسسة جديدة وفعّلها.
2. مسؤول المؤسسة: أنشئ موظفَين جديدَين.
3. تأكّد تلقائياً من إنشاء رقم داخلي فريد لكل موظف + SIP id فريد عالمياً
   (لا يساوي الرقم القصير) + كلمة مرور مستقلة، وحالة `pending` ثم `synced`.
4. سجّل الدخول من **جهازين** بحسابَي الموظفَين.
5. فعّل الهاتف في كل جهاز وتأكّد أن الحالة تصبح `Registered`.
6. اتصل من الموظف الأول بالثاني، وتأكّد من الرنين ووصول الصوت **باتجاهين**,
   واستمر دقيقة كاملة.
7. أعد الاختبار بالاتجاه المعاكس.
8. اختبر: الرفض، الانشغال، عدم الرد، وانقطاع الشبكة أثناء المكالمة.
9. تحقّق من السجل: مكالمة واحدة صحيحة لكل مكالمة (لا leg لكل مكالمة مستقلة)،
   مع التوقيت والمدة والسبب، ورابط الملاحظة بالمكالمة الفعلية.
10. أنشئ مؤسسة ثانية بنفس الأرقام القصيرة، وأثبت عدم عبور المكالمات بين
    المؤسستين.
11. عطّل موظفاً (يجب أن يفشل تسجيله وتمنع مكالماته) ثم أعد تفعيله.
12. أعد طلب provisioning وكرّر إرسال نفس الـ webhook، وتأكّد أنه لا يُنشئ
    تكراراً على السيرفر ولا في سجل المكالمات.
13. اختبر من شبكتين مختلفتين، ومع TURN عند الحاجة.
14. اختبر مؤقت المكالمة: يبدأ عند **الإجابة الفعلية** فقط، ويتوقّف عند الإنهاء.

---

## 10. تقرير الاختبار

### تم التحقق منه فعلياً (آلي، داخل المستودع)

- `vendor/bin/phpunit` → **242 اختباراً ناجحاً / 906 تأكيداً**، منها اختبارات الهاتف:
  - `tests/Feature/Telephony/EventForwarderTest.php` — **مُرسل الأحداث** (15
    اختباراً / 68 تأكيداً): تمييز القنوات المُدارة ورفض `1001/1002` و`vodnox-test`،
    تحويل `ringing → answered → ended` وتجميع الأرجل على `linkedid`، ثبات
    `event_id`، كتابة الـ spool قبل الإرسال وترتيبه ورفض التكرار، تطابق التوقيع مع
    ما يتحقّق منه `TelephonyWebhookService` فعلاً، وسياسة 2xx/4xx/5xx/null، ورحلة
    end-to-end عبر socket pair تُنتج الأحداث الثلاثة موقّعة.
  - `tests/Feature/Telephony/TelephonyCredentialsTest.php` — آلة الحالات
    (`unprovisioned / pending / failed / disabled / ready`)، ترويسة `no-store`،
    عدم تسريب بيانات زميل، والدليل يستعرض زملاء المؤسسة النشطين فقط.
  - `tests/Feature/Telephony/TelephonyProvisioningTest.php` — تخصيص الأرقام من
    `1000`، استخدام مؤسستين لنفس الرقم، فرادة الـ SIP id عالمياً ومفارقته للرقم
    القصير، تشفير كلمة المرور في القاعدة، وخلوّ payload من السرّ، ومنع التكرار
    عند التعطيل، وتزويد الموظفين المؤهّلين فقط.
  - `tests/Feature/Telephony/GatewayProvisioningTest.php` (20 اختباراً) —
    **حالة كل خط بوابة بمحاورها الثلاثة المستقلة** (desired / provisioning /
    registration)، مؤسستان تتشاركان الرقم `1001` بخطين وهويتَي SIP مختلفتين،
    الهوية `vdg{org}_…` العالمية ولا تساوي الرقم القصير، الملف المولَّد يستخدم
    `transport-udp` لا WebRTC، تشفير كلمة المرور في القاعدة وخلوّ `payload` ونتيجة
    الـjob من السرّ مع حقنه **وقت التشغيل فقط**، رفض وجهة تابعة لمؤسسة أخرى
    (`inbound_destination_unavailable`)، التعطيل يحفظ الهوية ويدفع `enabled=false`،
    إعادة التفعيل تعيد التزويد فعلياً بـrevision أعلى، حذف الجهاز يزيل كل خطوطه،
    تدوير السرّ يدفع الجديد، قفل الـjob على المورد (`gateway-line:{org}:{device}:{line}`)
    لا على الـrevision، وretry متأخر لا يكتب فوق revision أحدث، ووكيل قديم يُنتج
    `agent_update_required` بلا ادّعاء مزامنة، ومصدر مراقبة غير متاح يُعرض
    «غير معروفة» لا «منقطعة»، والقراءة القديمة (stale) كذلك، ومكالمة واردة من خط
    بوابة تُنسب لصاحب البوابة لا لـCaller-ID المُنتحَل.
  - `tests/Feature/Telephony/GatewayWorkspaceTest.php` (15 اختباراً) — الطلبات عبر
    HTTP والصلاحيات: الزائر يُحوَّل، و`supervisor` يرى فقط ولا يدير ولا يقرأ
    الأسرار (403)، وصفحة البوابة **لا تُصيّر كلمة المرور أبداً**، و`organization_admin`
    لا يصل أداة السوبر أدمن، والمؤسسة تُؤخذ من الجلسة لا من حمولة مُنتحَلة، وتُمحى
    محارف التحكّم (CR/LF) من النصوص الحرّة، ووجهة مؤسسة أخرى تُرفض، وموديل غير
    معتمد يُرفض، وجهاز/خط مؤسسة أخرى يعيد 404 لكل إجراء، ونقطة الأسرار بـ`no-store`
    مع تدقيق `gateway.secret_viewed` بلا السرّ، ووكيل قديم يُظهر إشعار التحديث،
    وفشل المصدر يُعرض خطأً، ونجاح التحديث يستخدم قراءة التسجيل الحقيقية، وأداة
    السوبر أدمن **عبر المؤسسات وقراءة فقط وبلا أسرار** (`admin.gateways.store`
    و`admin.gateways.lines.secret` غير موجودتين).
  - `tests/Feature/Telephony/CallEventMappingTest.php` — ترتيب الأحداث، تجاهل
    الأحداث المتأخرة، عدم إنشاء مكالمة من حدث انتهاء وحده، دمج الأرجل على
    `linkedid`، المدة من `billsec` أو من الزمن المنقضي، وتجريب انتحال
    `organization_id` وتعطيل الرقم المكرر بين مؤسستين.
  - `tests/Feature/Api/TelephonyWebhookTest.php` — رفض التوقيع غير الصحيح،
    منع تكرار `event_id`، بنية payload، ووصول الحدث إلى سجل المكالمات.
- **CI تشغّل PHPUnit فعلاً** (وظيفة `PHPUnit test suite` في
  `.github/workflows/main.yml`): خدمة MySQL، `composer install` مع dev، توليد
  `APP_KEY`، ثم `vendor/bin/phpunit`. وخطوة النشر `deploy` تعتمد عليها
  (`needs: tests`)، فاختبار أحمر **يمنع** النشر إلى cPanel — نجاح FTP وحده لم يكن
  يثبت شيئاً لأن `composer install --no-dev` لا يثبّت PHPUnit أصلاً.
- **حزمة الوكيل** (قسم §5.1): يبنيها `deploy/telephony/bin/package.sh` من قائمة
  سماح ويفحص محتواها قبل التسليم، ويطبع `agent version` وSHA-256، ويرفعها الـCI
  كـartifact باسم `vodnox-telephony-agent`. الـCI **لا يكتب `/etc/asterisk`** ولا
  يحدّث الوكيل.
- أثناء كتابة اختبار المدة انكشف خلل حقيقي: مقارنة توقيت يحمل إزاحة (`Z`) بقيمة
  زمنية تُقرأ بتوقيت التطبيق كان يُنتج فارقاً بساعة التطبيق. صُحِّح في
  `CallEventService::occurredAt()` بتطبيع التوقيت على توقيت التطبيق.

### لم يتم التحقق منه (يحتاج وصولاً/SSH وشهادة وDNS)

- أي عملية حقيقية على السيرفر: تثبيت الوكيل، `pjsip reload`، `http reload`.
- **تركيب وكيل `1.3.0`** على سيرفر الوسائط (§5.1) — خطوة المشغّل الواحدة. حتى
  تنفيذها لا تفعّل الواجهة أي خط بوابة، وتظهر رسالة «خدمة الربط لا تدعم خطوط
  البوابة بعد»، ولا يُعرض خط كـ«مُزامَن». هذا سلوك صادق مقصود، وليس عطلاً.
- **تسجيل بوابة GSM/GoIP حقيقية** على `5060/udp` وظهور «مسجّل بالجهاز» من قراءة
  `pjsip show contacts` الفعلية.
- **مكالمة GSM واردة حقيقية** تصل إلى التحويلة المختارة، مع رفض رقم غير مُعرَّف
  داخل سياق الخط ورفض خط مؤسسة معطّلة.
- **تعطيل/إعادة تفعيل/تدوير سرّ/حذف** خط على جهاز حقيقي: يثبت أن الملفات المولَّدة
  والحالة على السيرفر تتبع المطلوب بدقة.
- تسجيل SIP.js فعلياً عبر WSS، والصوت باتجاهين، وDTLS-SRTP/ICE على شبكات حقيقية.
- تأكيد `pbx.vodnox.com` وشهادته، وفتح المنافذ في الجدار، و`external_*_address`.
- اختبار TURN من شبكة متماثلة.
- **باختصار:** كل ما يعتمد على شبكة/سيرفر حقيقي مُعلَّم كغير مُتحقَّق منه، ولم
  يُدَّعَ نجاحه.

---

## 11. فحص المتطلبات قبل التثبيت (قراءة فقط)

هذه الأوامر **للفحص فقط ولا تعدّل شيئاً**، ولا يُنفَّذ أي تعديل على السيرفر قبل
قراءة نتائجها. افصل المجموعتين: **أ** على سيرفر الاتصالات، **ب** على استضافة
Laravel. النتائج المطلوبة من كل أمر مشروحة بين قوسين.

### أ) سيرفر الاتصالات (Asterisk، `38.3.184.246`)

```bash
# A1. هوية النظام والأرشيف (Ubuntu/Debian؟)
hostnamectl; cat /etc/os-release | head -n 3

# A2. نسخة Asterisk الفعلية
sudo asterisk -rx 'core show version'

# A3. وجود مستخدم الخدمة
id asterisk

# A4. وحدتا WebSocket المطلوبتان لـ SIP.js
sudo asterisk -rx 'module show like websocket'

# A5. مستمعات PJSIP الحالية (5060/5061 + النقل WSS إن وُجد)
sudo asterisk -rx 'pjsip show transports'

# A6. إصدار PHP المتاح فعلاً (لا يُفترض 8.2)
ls -1d /usr/sbin/php-fpm* 2>/dev/null; ls -1d /etc/php/*/fpm 2>/dev/null
php -v 2>/dev/null | head -n 1; php-fpm -v 2>/dev/null | head -n 1
php -m 2>/dev/null | grep -E '^(json|openssl|mbstring|curl)$'

# A7. ملفات الإعداد الحالية (بلا كشف أسرار: الأسطر السرّية تُستبعد)
sudo grep -nE '^\s*\[|^\s*(bindaddr|bindport|tlsenable|tlsbindaddr|enabled)' /etc/asterisk/http.conf
sudo grep -nE '^\s*\[|^\s*(read|write|permit|deny)' /etc/asterisk/manager.conf

# A8. نطاق RTP الفعلي — الأساس لقاعدة الجدار (لا يُفترض 49152-65535)
sudo grep -nE '^\s*(rtpstart|rtpend|icesupport|strictrtp|localnet)' /etc/asterisk/rtp.conf \
  || echo 'rtp.conf غير معرّف أو بلا نطاق مخصّص — نستخدم الافتراضي المطبوع في A9'

# A9. المنافذ المستمعة فعلاً (RTP ديناميكي؛ يظهر عند وجود مكالمة)
sudo ss -lntup

# A10. حالة الجدار الحالية وقواعد الإدخال
sudo ufw status verbose 2>/dev/null || sudo iptables -S 2>/dev/null
sudo firewall-cmd --list-all 2>/dev/null || true

# A11. أثر الاختبار القديم (للتأكد فقط، لن نلمسه)
sudo asterisk -rx 'pjsip show endpoints' | grep -iE '1001|1002|vdx' || echo 'لا توجد endpoints قديمة'

# A12. عنوان الخروج كما يراه الإنترنت من السيرفر
curl -sS https://ifconfig.me/ip; echo
```

### ب) استضافة Laravel (`vodnox.com`)

```bash
# B1. إصدار PHP وLaravel على الاستضافة (منفصل عن PHP البوابة)
php -v
php artisan --version

# B2. الامتدادات التي تحتاجها Laravel فعلاً
php -m | grep -E '^(bcmath|ctype|curl|dom|fileinfo|json|mbstring|openssl|pdo|pdo_mysql|tokenizer|xml)$'

# B3. عنوان الخروج الفعلي للاستضافة — أساس قيمة "allow" (§3 و§7)
curl -sS https://ifconfig.me/ip; echo

# B4. وجود متغيّرات TELEPHONY_* (الأسماء فقط، بلا قيم)
grep -oE '^TELEPHONY_[A-Z_]+=' .env | sed 's/=$//' || echo 'لا يوجد ملف .env أو لا توجد متغيّرات'

# B5. عامل الطابور الذي ينفّذ التزويد (مطلوب وإلا بقيت الحسابات pending)
systemctl is-active vodnox-queue 2>/dev/null || ps -eo cmd | grep -E '[q]ueue:work' || echo 'لا يعمل عامل طابور'

# B6. هل يستطيع مضيف Laravel الوصول إلى بوابة التزويد الآن؟ (متوقّع فشل قبل DNS/الشهادة)
getent hosts pbx.vodnox.com || echo 'pbx.vodnox.com غير مُعرّف بعد (متوقّع)'
curl -sS -o /dev/null -w 'https_status=%{http_code}\n' https://pbx.vodnox.com/health 2>&1 | tail -n 1
```

**قاعدة الانتظار:** لا يُعدَّل أي ملف على السيرفر ولا تُفتح أي منفذ قبل إرسال
مخرَجات أعلاه. القيم المستخلصة منها تحسم ثلاثة قرارات مؤجّلة: نطاق `allow` في
`/etc/nginx/vodnox-allow.conf` (من B3)، نطاق RTP في الجدار (من A8/A9)، وإصدار
PHP الذي ستستخدمه وحدتا `php-fpm` وCLI (من A6).
