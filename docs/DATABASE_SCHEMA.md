# مخطط قاعدة البيانات (DATABASE_SCHEMA)

قاعدة بيانات MySQL بترميز `utf8mb4` / `utf8mb4_unicode_ci`.
- **63 جدولاً** موزّعة على وحدات النظام.
- كل الجداول التابعة لمؤسسة تحمل `organization_id` (مفتاح أجنبي إلى `organizations`).
- الحقول المالية `decimal(15,2)` — لا `float`.
- التخزين UTC؛ العرض وتحويلات الجداول بتوقيت `Asia/Baghdad`.

---

## 1. الأساس والهوية

### `users`
`id`, `name`, `username` (unique), `email`, `email_verified_at`, `password`,
`remember_token`, `organization_id` (nullable — السوبر أدمن بلا مؤسسة),
`role_id`, `active`, `extension`, `phone`, `position`, timestamps.

### `organizations`
`id`, `code` (unique), `name_ar`, `name_en`, `contact_name`, `email`, `phone`,
`city`, `status` (active/suspended/…), `timezone`, `language`, `internal_notes`,
`activated_at`, `deleted_at` (soft deletes), timestamps.

### `departments`
`id`, `organization_id`, `name_ar`, `name_en`, `code`, timestamps.

### `roles` / `permissions` / `permission_role`
- `roles`: `id`, `name` (unique), `label_ar`, `label_en`, `scope` (platform/organization).
- `permissions`: `id`, `name` (unique), `label_ar`, `label_en`.
- `permission_role`: `permission_id`, `role_id` (pivot).

### جداول إطار العمل
`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `migrations`.

---

## 2. الاشتراكات والفوترة

### `plans` / `plan_limits`
- `plans`: `id`, `name_ar`, `name_en`, `description_ar`, `description_en`,
  `monthly_price` decimal(15,2), `yearly_price` decimal(15,2), `currency`,
  `is_active`, `sort_order`.
- `plan_limits`: `id`, `plan_id`, `key`, `value`.

### `subscriptions`
`id`, `organization_id`, `plan_id`, `status`, `billing_cycle`, `starts_at`,
`ends_at`, `price` decimal(15,2), `currency`.

### `features` / `organization_feature_subscriptions`
- `features`: `id`, `code` (unique: `crm`, `forms`, `smart_ivr`), `name_ar`,
  `name_en`, `is_active`.
- `organization_feature_subscriptions`: `id`, `organization_id`, `feature_id`,
  `status` (inactive/active/suspended), `starts_at`, `ends_at`, `max_users`,
  `max_customers`, `max_forms`, `max_submissions`, `price`, `currency`.
  > قيد فريد على (`organization_id`, `feature_id`).

### `activation_requests`
`id`, `organization_id`, `feature_id`, `status`, `notes`, `approved_by`,
`approved_at`.

### `invoices` / `invoice_items` / `payments` / `payment_proofs`
- `invoices`: `id`, `invoice_number` (unique), `organization_id`, `subscription_id`,
  `amount` decimal(15,2), `currency`, `status` (unpaid/partial/paid/…),
  `due_date`, `paid_at`, `notes`.
- `invoice_items`: `id`, `invoice_id`, `description_ar`, `description_en`,
  `quantity`, `unit_price` decimal(15,2), `total` decimal(15,2).
- `payments`: `id`, `invoice_id`, `organization_id`, `amount` decimal(15,2),
  `currency`, `method`, `reference`, `status`, `notes`, `recorded_by`.
- `payment_proofs`: `id`, `payment_id`, `file_path`, `file_name`, `mime_type`, `size`.
  > رفع إثبات الدفع **لا** يجعل الفاتورة `paid`؛ التأكيد اليدوي فقط يفعل (R10).

---

## 3. أصول الاتصالات والتوجيه

### `phone_numbers`
`id`, `organization_id`, `number` (unique), `label`, `status`, `ivr_menu_id`,
`call_queue_id`, `routing_type`, `routing_target_id`.

### `extensions` / `devices`
- `extensions`: `id`, `organization_id`, `user_id`, `extension`, `label`,
  `sip_secret`, `status`. قيد فريد (`organization_id`, `extension`).
- `devices`: `id`, `organization_id`, `extension_id`, `name`, `mac_address`,
  `ip_address`, `status`.

### `call_queues` / `call_queue_members`
- `call_queues`: `id`, `organization_id`, `name_ar`, `name_en`, `strategy`,
  `timeout`, `is_active`.
- `call_queue_members`: `id`, `call_queue_id`, `user_id`, `order`.

### `ivr_menus` / `ivr_options`
- `ivr_menus`: `id`, `organization_id`, `name_ar`, `name_en`, `greeting_ar`,
  `greeting_en`, `timeout` (3–30), `max_attempts` (0–3), `invalid_destination_type`,
  `invalid_destination_id`, `no_input_destination_type`, `no_input_destination_id`,
  `is_active`.
- `ivr_options`: `id`, `ivr_menu_id`, `digit` (0–9، فريد لكل قائمة),
  `destination_type`, `destination_id`.

### `routing_schedules` / `routing_schedule_days` / `routing_schedule_targets`
- `routing_schedules`: `id`, `organization_id`, `name_ar`, `name_en`, `strategy`
  (simultaneous/sequential), `is_active`, `ring_duration` (5–120),
  `no_answer_type`, `no_answer_target_id`, `fallback_ring_duration` (20),
  `outside_hours_type`, `outside_hours_target_id`.
- `routing_schedule_days`: `id`, `routing_schedule_id`, `day` (0–6),
  `start_time`, `end_time` (time — النهاية حصرية).
- `routing_schedule_targets`: `id`, `routing_schedule_id`, `user_id`, `order`,
  `ring_duration`.
  > منع التداخل بين الجداول المفعّلة، ومنع تكرار الموظف، ومنع بداية=نهاية — في
  > طبقة التحقق (Form Request) و`ScheduleMatcher`.

---

## 4. المكالمات والتسجيلات

### `calls`
`id`, `organization_id`, `external_call_id`, `user_id`, `customer_id`,
`direction`, `caller_number`, `callee_number`, `status`, `started_at`,
`answered_at`, `ended_at`, `duration_seconds`, `billable_seconds`,
`recording_path`, `notes`.

### `call_events`
`id`, `call_id`, `event_type`, `data` (json), `occurred_at`.

### `recordings`
`id`, `organization_id`, `call_id`, `file_path`, `file_name`, `size`,
`duration_seconds`, `mime_type`. الملفات تُخزّن خارج `public/`.

### `voicemails`
`id`, `organization_id`, `user_id`, `caller_number`, `duration_seconds`,
`file_path`, `is_read`.

---

## 5. مساحة عمل الموظف

- `agent_preferences`: `id`, `user_id`, `audio_device`, `auto_answer`,
  `do_not_disturb`, `settings` (json).
- `contact_favorites`: `id`, `user_id`, `organization_id`, `contact_id`, `name`,
  `phone_number`.
- `call_notes`: `id`, `call_id`, `user_id`, `organization_id`, `customer_id`, `content`.
- `call_wrapups`: `id`, `call_id`, `user_id`, `organization_id`, `customer_id`,
  `outcome`, `summary`, `is_completed`.
- `followups`: `id`, `organization_id`, `user_id`, `customer_id`, `call_id`,
  `title`, `description`, `due_at` (datetime بتوقيت بغداد)، `status`.

---

## 6. CRM (اشتراك مستقل)

- `customers`: `id`, `organization_id`, `name_ar`, `name_en`, `company_name`,
  `email`, `phone`, `address`, `status`, `assigned_to`, `notes`, `deleted_at`.
- `customer_contacts`: `id`, `customer_id`, `name`, `role`, `email`, `phone`,
  `is_primary`.
- `customer_notes`: `id`, `customer_id`, `user_id`, `content`.
- `interactions`: `id`, `organization_id`, `customer_id`, `user_id`, `call_id`,
  `type`, `content`, `occurred_at`.
- `opportunity_stages`: `id`, `organization_id`, `name_ar`, `name_en`, `order`.
- `opportunities`: `id`, `organization_id`, `customer_id`, `title`, `description`,
  `value` decimal(15,2), `currency`, `stage_id`, `expected_close_date`, `status`,
  `assigned_to`.
- `tasks`: `id`, `organization_id`, `customer_id`, `opportunity_id`,
  `assigned_to`, `title`, `description`, `due_at`, `status`, `priority`.
- `complaints`: `id`, `organization_id`, `customer_id`, `call_id`,
  `department_id`, `subject`, `description`, `priority`, `status`, `assigned_to`.

---

## 7. VODNOX FORMS (اشتراك مستقل)

- `forms`: `id`, `organization_id`, `title_ar`, `title_en`, `description_ar`,
  `description_en`, `thank_you_message_ar`, `thank_you_message_en`,
  `public_token` (unique + index)، `status` (draft/published)، `starts_at`,
  `ends_at`, `max_submissions`, `is_active`, `created_by`, `deleted_at`.
- `form_versions`: `id`, `form_id`, `version`, `schema` (json — لقطة غير قابلة
  للتغيير عند النشر)، `is_published`, `published_at`.
- `form_fields`: `id`, `form_id`, `type` (11 نوعاً), `label_ar`, `label_en`,
  `help_text_ar`, `help_text_en`, `placeholder_ar`, `placeholder_en`,
  `is_required`, `order`, `min_length`, `max_length`, `min_value`, `max_value`,
  `max_rating`, `max_selections`.
- `form_options`: `id`, `form_field_id`, `label_ar`, `label_en`, `value`, `order`.
- `form_conditions`: `id`, `form_field_id`, `depends_on_field_id`, `operator`,
  `value`, `action`.
  > يمنع المرجع الدائري/اللاحق (self/forward/cycle) في `FormDefinitionValidator`.
- `form_submissions`: `id`, `form_id`, `form_version_id`, `ip_address`,
  `user_agent`, `answers` (json).
  > الحصّة تُحتسب داخل transaction مع `lockForUpdate` (R6).

---

## 8. البنية التحتية والإعدادات

- `telephony_servers`: `id`, `name`, `host`, `port`, `connection_method`,
  `ami_username`, `ami_secret` (encrypted cast)، `status`, `last_connected_at`.
- `integrations`: `id`, `type`, `name`, `config` (json)، `is_active`.
- `provisioning_jobs`: `id`, `type`, `organization_id`, `payload` (json),
  `status`, `idempotency_key` (unique)، `error_message`, `completed_at`.
- `webhook_events`: `id`, `event_id` (unique — idempotency)، `event_type`,
  `organization_id` (nullable)، `payload` (json)، `signature`, `status`,
  `occurred_at`.
- `audit_logs`: `id`, `user_id`, `organization_id`, `action`, `auditable_type`,
  `auditable_id`, `old_values` (json)، `new_values` (json)، `ip_address`.
- `settings`: `id`, `key` (unique)، `value`, `type`.
- `contact_inquiries`: `id`, `name`, `email`, `phone`, `company`, `message`,
  `status`. (استفسارات الموقع العام — بلا `organization_id`.)

---

## 9. العلاقات الأساسية

```
organizations 1─n users, departments, phone_numbers, extensions, call_queues,
                 ivr_menus, routing_schedules, calls, recordings, voicemails,
                 customers, forms, subscriptions, invoices, payments, audit_logs
users         1─n extensions, agent_preferences, contact_favorites, call_notes,
                 call_wrapups, calls
forms         1─n form_fields ─n form_options / form_conditions
forms         1─n form_versions 1─n form_submissions
invoices      1─n invoice_items, payments 1─n payment_proofs
plans         1─n plan_limits, subscriptions
features      1─n organization_feature_subscriptions, activation_requests
routing_schedules 1─n routing_schedule_days, routing_schedule_targets
```
