<?php

/*
|--------------------------------------------------------------------------
| Telephony Technical Settings
|--------------------------------------------------------------------------
|
| Strings of the technical section: the sign-in screen, the session badges,
| the error messages and the audit notes. The failure message never reveals
| whether a username exists.
|
*/

return [
    // Section title
    'title' => 'Telephony Technical Settings',
    'subtitle' => 'A section for the support team: add GSM devices, manage ports, SIP data and the wiring guide.',

    // Sign-in screen
    'gate_heading' => 'Technical sign-in required',
    'gate_intro' => 'This section is for the technical support team. Enter the technical credentials of your organization to continue.',
    'gate_org' => 'Current organization',
    'gate_username' => 'Technical username',
    'gate_password' => 'Technical password',
    'gate_submit' => 'Start technical session',
    'gate_no_account' => 'No technical account has been prepared for this organization yet. The super admin prepares it from the platform console.',
    'gate_disabled_account' => 'The technical account of this organization is disabled. Please contact the super admin.',
    'gate_separation' => 'The technical sign-in is completely separate from the organization administrator account, and grants no access to another organization or to the super-admin console.',
    'gate_no_ui_protection' => 'Access is enforced by server-side authentication and authorisation — not by hiding fields or views.',

    // Session
    'session_badge' => 'Technical support session',
    'session_org' => 'Organization',
    'session_started_at' => 'Started',
    'session_end' => 'End technical session',
    'session_end_confirm' => 'The technical session will end immediately. Continue?',
    'session_remaining' => 'The session ends after :idle minutes without activity, and in any case after :absolute minutes.',
    'session_started' => 'Technical session started.',
    'session_ended' => 'Technical session ended.',
    'session_required' => 'This action requires a live technical session. Sign in from "Telephony Technical Settings".',

    // Authentication
    'invalid_credentials' => 'Invalid credentials.',
    'too_many_attempts' => 'Too many sign-in attempts. Please try again shortly.',

    // Wiring guide
    'guide_button' => 'Download setup guide',
    'guide_include_passwords' => 'Include SIP passwords',
    'guide_include_passwords_hint' => 'Off by default. Requires the secret-reveal permission, and uses the current passwords without creating or rotating any.',
    'guide_secrets_warning' => 'The file with secrets contains real SIP passwords and is handed to the authorised engineer only. Rotating the SIP credentials makes the previous copy stale.',
    'guide_no_secrets_note' => 'The file never contains the technical sign-in password, the organization account, or any server service key.',

    // Audit
    'audit_note' => 'Technical sign-in, session end, revealing and rotating SIP secrets, guide downloads and device changes are audited. Secrets are never stored in the log.',
    'audit_shared_identity_note' => 'The technical account is shared between the organization engineers, so the log records the account and the session it was opened from — it does not claim to identify the individual.',
];
