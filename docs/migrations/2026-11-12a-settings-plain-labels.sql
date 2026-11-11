-- 2026-11-12a — Settings › Company in plain words: the setting descriptions
-- Owner decision D-332 (12 Nov 2026, "go ahead with all four").
--
-- What changes:
--   * The description of 60 company settings (the grey help sentence under each
--     setting on Settings › Company) is rewritten as one plain sentence for the
--     user. The old seeded texts were written for developers (code names such as
--     SessionAuth, "flagging per architecture doc practice", backlog numbers,
--     D-numbers, other setting keys).
--   * Each UPDATE changes a row ONLY while its description is still exactly the
--     old seeded text, so a description the company changed itself is kept.
--   * A protected setting that is still EMPTY (e.g. a Zoho key not entered yet) is
--     skipped: the database refuses any change to such a row (trigger
--     trg_company_settings_bu). The screen still shows the plain sentence, from
--     the app itself, and the row keeps the old text until it has a value.
--   * Setting keys and values are NOT changed. The screen shows the plain name of
--     each setting from the app itself (no database change needed for that).
--
-- Safe to run twice: every statement is an UPDATE whose WHERE clause matches the
-- old text only; a second run finds nothing left to change. No DDL.
-- Run after 2026-11-11h, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-12a-settings-plain-labels.sql
SET NAMES utf8mb4;

UPDATE company_settings SET description = 'Days after the date on the freight debit note (FDN) before its payment shows as overdue (calendar days). Keep it the same as the "payable within 3 Calendar Days" in the freight clause of the Quotation and Proforma.'
 WHERE setting_key = 'fdn_overdue_days_c' AND description = 'WORKING days after FDN issue before a freight payment is flagged overdue — must match the "payable within 3 working days of issue" figure in the CFR/CIF Indicative Freight Validity clause (QT/PI) verbatim, since that clause is the written promise to the buyer. (Corrected from an earlier placeholder of 7, which did not match the clause text and used calendar-day arithmetic instead of working days — see check_alerts.php.)'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Days after a payment is marked cleared before it is flagged because its FIRC / eBRC reference is still missing (270 days = the 9-month RBI realisation period).'
 WHERE setting_key = 'fema_realization_alert_days' AND description = 'Days after a settlement leg is marked cleared before the CA / Accounting module flags its FIRC/eBRC reference as still missing — 270 days (9 months) mirrors the RBI/FEMA export-proceeds realization period as a practical proof-of-realization deadline, not a legal shipment-date calculation (this system does not track the shipment date this rule technically runs from).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'CAPEXIL Registration-cum-Membership Certificate (RCMC) number, printed on the Certificate of Origin preparation sheet.'
 WHERE setting_key = 'rcmc_number' AND description = 'CAPEXIL RCMC (Registration-cum-Membership Certificate) number — required on the COO Preparation Sheet (Phase C).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Expiry date of the CAPEXIL RCMC certificate (renewed every year). The reminders follow the RCMC days under "Reminders and alerts".'
 WHERE setting_key = 'rcmc_valid_until' AND description = 'CAPEXIL RCMC certificate expiry date — placeholder, renew annually. Alerts configured via rcmc_alert_days_a/rcmc_escalation_days_b below.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Off = no staff member can open a client''s portal as that client, whatever is set on the client or the role.'
 WHERE setting_key = 'client_impersonation_enabled' AND description = 'Global switch for staff "Log in as this client" (Section AV). Off = the feature is unavailable for every client, regardless of any individual client''s own allow_staff_impersonation flag or who holds the impersonate_client permission.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On (default) = clients can download the approved documents of their own orders from the portal. Off = the Documents section is hidden, unless a client''s own setting says otherwise.'
 WHERE setting_key = 'client_portal_show_document_downloads' AND description = 'On (default) = every client can download their own order''s approved/sent customer-facing documents from the portal. Off = the Documents section is hidden and direct download links are refused, unless an individual client''s own override says otherwise.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On (default) = clients see all their orders, including completed and lost ones. Off = only active and disputed orders, unless a client''s own setting says otherwise.'
 WHERE setting_key = 'client_portal_show_order_history' AND description = 'On (default) = every client sees their full order history, including completed/lost orders, on their portal dashboard. Off = clients see active/disputed orders only, unless an individual client''s own override says otherwise.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Name of the director, used only if a document carries a director''s signature (Quotation, Proforma and OC show the Managing Director only).'
 WHERE setting_key = 'director_name' AND description = 'Director name, if a director signature is used on any document (open question — confirm whether this is needed; QT/PI/OC templates only show the MD signature block).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Working days allowed to answer a buyer''s dispute before it is escalated. Keep it the same as the "ten (10) working days" in the dispute clause printed on the Quotation, Proforma, OC and Buyer PO. Weekly days off and company holidays are not counted.'
 WHERE setting_key = 'dispute_response_days_n' AND description = 'WORKING days allowed for a dispute response before escalation — must match the "ten (10) working days" figure in the Dispute Resolution & Public Communications clause below verbatim, since that clause is the legally binding promise printed on QT/PI/OC/BUYERPO. (Corrected from an earlier placeholder of 7, which did not match the clause text.) response_due_date is computed via WorkingDaysCalculator, which skips weekly_off_days and company_holidays — plain calendar-day arithmetic would silently undercount the deadline by counting Sundays/holidays as working days.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Days of the week that never count as working days, in small letters and separated by commas (e.g. sunday).'
 WHERE setting_key = 'weekly_off_days' AND description = 'Comma-separated lowercase weekday name(s) that never count as a working day, used by WorkingDaysCalculator (Addition beyond the spec''s named key list — needed to make dispute_response_days_n''s "working days" figure actually computable; NexaCrest''s standard Mon-Sat working week per this same section''s holiday calendar).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On = the CONSIGNEE DETAILS section prints on the Quotation, Proforma, OC, Packing List and Commercial Invoice even when the consignee is the buyer. Off = it prints only when the consignee is different.'
 WHERE setting_key = 'always_show_consignee_section' AND description = 'Print the CONSIGNEE DETAILS section on QT/PI/OC/PL/CI even when the Consignee is identical to the Buyer. Set to 0 to omit that section whenever Consignee = Buyer — it still always prints the moment the two genuinely differ, regardless of this setting.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On = the NOTIFY PARTY section prints on the Proforma, Packing List and Commercial Invoice even when the notify party is the buyer. Off = it prints only when the notify party is different.'
 WHERE setting_key = 'always_show_notify_party_section' AND description = 'Print the NOTIFY PARTY section on PI/PL/CI even when the Notify Party is identical to the Buyer (directly, or via "same as Consignee" when the Consignee itself is the Buyer). Set to 0 to omit that section whenever Notify Party = Buyer — it still always prints the moment the two genuinely differ, regardless of this setting.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The wording printed under the signature block when the "system-generated document" note is switched on.'
 WHERE setting_key = 'generated_document_disclaimer_text' AND description = 'Exact wording shown under the signature block when the disclaimer above is enabled. Edit freely — no code change needed.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Days a Proforma Invoice stays valid from its issue date.'
 WHERE setting_key = 'pi_validity_days' AND description = 'Days a Proforma Invoice stays valid from its issue date. Same addition as quotation_validity_days, for the same reason — the source PI template states "15 days" directly in its T&C text.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Days a Quotation stays valid from its issue date.'
 WHERE setting_key = 'quotation_validity_days' AND description = 'Days a Quotation stays valid from its issue date (Addition beyond the spec''s named key list — the source Quotation template states "30 days" directly in its T&C text; making it a setting instead of a literal keeps that number DB-driven if it ever changes).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On = documents that carry a signature or seal image show the "system-generated document" note under the signature block. Off by default.'
 WHERE setting_key = 'show_generated_document_disclaimer' AND description = 'Show the "system-generated document" disclaimer under the signature block on any generated document that carries a signature/seal image. Defaults to OFF (90-item backlog #21) — set to 1 to show it everywhere.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The pattern of order reference numbers: a daily counter, e.g. NC/SC/ORD/26/0411001.'
 WHERE setting_key = 'order_ref_format' AND description = 'Order reference format (owner decision 4 Nov 2026, D1): a daily counter, e.g. NC/SC/ORD/26/0411001.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The date the current LUT expires — check it against the LUT certificate.'
 WHERE setting_key = 'lut_expiry_date' AND description = 'LUT expiry date — placeholder (FY 2026-27 end date assumed as 31 March 2027) — the source PI template states the FY but not an explicit expiry date; confirm the real one.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On = e-mails to buyers and suppliers are held; staff and associate e-mails and every MUST e-mail still go out. Set on Settings › E-mail.'
 WHERE setting_key = 'email_testing_mode' AND description = 'D-280: Testing mode — hold e-mails to buyers and suppliers. On = e-mails to buyers and suppliers are not sent (the screen says so and shows the link to copy); staff and associate e-mails and every MUST e-mail (login codes, set-password and forgot-password links, security alerts) still go out. Set in Admin › E-mail settings, with the per-e-mail switches.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On (default) = every document e-mail to a buyer is approved by a second person before it goes out. Off = one staff member who can generate documents sends it directly.'
 WHERE setting_key = 'mail_approval_queue_enabled' AND description = 'On (default) = every buyer-facing document send still needs a second person''s Level-2 approval before it goes out (Section 10). Off = a single staff member with generate_documents can send directly — useful for a small team or while testing.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Where buyers'' replies go — a sales mailbox someone reads. Leave empty to use the company e-mail address.'
 WHERE setting_key = 'reply_to_email' AND description = 'External QA report L-43: customer emails (QT/PI/OC/etc.) went out from a no-reply address with no Reply-To, so a buyer reply was lost. Set a monitored sales/support address here so replies reach someone; leave blank to fall back to company_settings.email.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Extra addresses, separated by commas, copied on every e-mail except login codes and password links. Only a Super Admin can change this.'
 WHERE setting_key = 'mail_cc_emails' AND description = 'Comma-separated additional CC addresses applied to every non-security outbound email, regardless of mail_redirect_enabled. Super Admin only.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'One address copied on every e-mail except login codes and password links, besides the list above. Only a Super Admin can change this.'
 WHERE setting_key = 'mail_default_cc_email' AND description = 'A single default CC address always applied to every non-security outbound email, in addition to mail_cc_emails above. Super Admin only.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'While redirect is on, e-mails go to this address instead of their real recipient. If it is empty, redirected e-mails are only logged, not sent.'
 WHERE setting_key = 'mail_redirect_address' AND description = 'Where redirected mail goes when mail_redirect_enabled is on. Required for the redirect to actually work — if left blank while enabled, redirected mail is logged, never sent.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On = every e-mail except login codes and password links goes to the address below instead of its real recipient (separate from Test Mode).'
 WHERE setting_key = 'mail_redirect_enabled' AND description = 'Independent of Test Mode — when on, every non-security outbound email (order updates, document sends, etc.) is redirected to mail_redirect_address below instead of its real recipient. Staff 2FA codes and password-reset links are never redirected.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'How the production and sailing lead times show on the order page. Only the display changes; they are always entered and kept in days.'
 WHERE setting_key = 'lead_time_display_unit' AND description = '90-item backlog item 16: whether the Production/Sailing Lead Time ranges on the Order Detail page show in days (default) or weeks. Display only — the stored figures and every input field stay in days regardless.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Hours after the Order Confirmation e-mail is sent before the order confirms itself if the buyer has not answered (48 on the live site).'
 WHERE setting_key = 'oc_ack_auto_confirm_hours' AND description = 'Hours after the Order Confirmation email is sent before the order auto-confirms if the buyer hasn''t responded (docs/schema.sql Section AE). Lower it for testing; 48 is the production default.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Off (default) = anyone who manages orders can record the buyer''s acknowledgement of the Order Confirmation by hand. On = only roles given the "Override buyer acknowledgement" permission.'
 WHERE setting_key = 'oc_ack_override_restricted' AND description = 'Off (default) = any user with manage_orders can record a buyer''s Order Confirmation acknowledgment manually, as before. On = that action also requires the override_buyer_acknowledgment permission, so it can be restricted to specific roles.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'How many minutes an account stays locked after too many failed logins.'
 WHERE setting_key = 'lockout_duration_minutes' AND description = 'How long an account stays locked after too many failed attempts. (Addition beyond the spec''s named key list — needed to make failed_login_lockout_count actually enforceable; flagging per architecture doc practice.)'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Staff who tick "This is my own office computer" skip the e-mailed login code on that computer for this many days (0 to 90; default 30; 0 = always ask). A password change or "Forget all remembered computers" ends it.'
 WHERE setting_key = 'login_remember_browser_days' AND description = 'D-265 / D-283: staff may tick "This is my own office computer" on the login-code screen and skip the e-mailed code on that computer for this many days (0 to 90; default 30). 0 = off: the code is asked at every login. A password change or reset, or "Forget all remembered computers" (My Account, or Admin in Users › Edit), forgets them.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Staff must change their password after this many days; 0 = never (the default, as staff log in with an e-mailed code).'
 WHERE setting_key = 'password_expiry_days' AND description = 'Days before a password must be changed. Enforced on every request (SessionAuth) — 0 disables the check (D-313: off by default; staff sign in with an e-mailed code).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Staff must log in again after this many hours, however active they are (1 to 24; default 12).'
 WHERE setting_key = 'session_absolute_staff_hours' AND description = 'D-283: staff log in again at least this often, however active they are (1 to 24 hours; default 12).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Associates are logged out of their portal after this many minutes without activity (5 to 1440; default 120 = 2 hours).'
 WHERE setting_key = 'session_timeout_associate_minutes' AND description = 'D-283: associates (associate portal) are signed out after this many minutes without activity (5 to 1440; default 120 = 2 hours).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Buyers are logged out of their portal after this many minutes without activity (5 to 1440; default 120 = 2 hours).'
 WHERE setting_key = 'session_timeout_client_minutes' AND description = 'D-283: buyers (client portal) are signed out after this many minutes without activity (5 to 1440; default 120 = 2 hours).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Staff are logged out after this many minutes without activity (5 to 1440; default 240 = 4 hours). A "Stay logged in" warning shows 2 minutes before.'
 WHERE setting_key = 'session_timeout_staff_minutes' AND description = 'D-283: staff are signed out after this many minutes without activity (5 to 1440; default 240 = 4 hours). A warning with "Stay logged in" shows 2 minutes before.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Printed on every BL Instruction Sheet: the BL is made out to the company''s order, so the buyer cannot use it until the company releases it. {company} becomes the company''s legal name.'
 WHERE setting_key = 'bl_consignee_instruction' AND description = 'Mandatory BL consignee instruction — ensures the BL is to NexaCrest''s order so the buyer cannot use it until NexaCrest endorses and releases it. {company} is substituted with the company legal name at render time. Same addition/rationale as bl_type_instruction.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Printed on every BL Instruction Sheet: an original Bill of Lading only — no Sea Waybill or Express BL, which would let the buyer collect the goods before paying the balance.'
 WHERE setting_key = 'bl_type_instruction' AND description = 'Mandatory BL type instruction printed on every BL Instruction Sheet (Addition beyond the spec''s named key list — this hard rule was previously hardcoded directly in the BLI template, with no governance or audit trail if it ever needed a one-off exception; a Sea Waybill/Express BL lets the buyer collect cargo without surrendering any document, eliminating NexaCrest''s financial leverage over the balance payment).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Off = only the MD and Admin see the SOP library; Getting Started stays open. Set on Settings › SOP access.'
 WHERE setting_key = 'sop_library_enabled' AND description = 'D-266: the SOP library (menu, chapters, screenshots, Gallery) for staff. Off = only MD and Admin see it; Getting Started stays open. Switched on Admin > SOP access.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'For information only — the app reads the real folder from the server''s .env file, not from here.'
 WHERE setting_key = 'storage_base_path' AND description = 'Documentation only — the app reads the real path from .env, not from this row, so it can never drift out of sync with the filesystem.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'A suggested extra margin shown (not added automatically) on quotes in a currency other than USD.'
 WHERE setting_key = 'non_usd_price_buffer_pct' AND description = 'Advisory price buffer shown (not auto-applied) for non-USD quotes, per your instruction.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The layout (Standard or Kanban Board) a user sees until they choose their own on My Account. Only active layouts can be picked.'
 WHERE setting_key = 'default_ui_template' AND description = 'Which UI template (see Admin > UI Templates) a user sees when they have not chosen one for themselves on their own Account page. Must be an active template — picking it from the dropdown on this screen enforces that.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On (default) = the Buyer PO step cannot be confirmed until the buyer''s signed PO copy is uploaded; a reference number alone is not enough.'
 WHERE setting_key = 'wet_signature_required_buyer_po' AND description = 'When on, Stage 2 (Buyer PO) cannot be confirmed until the buyer''s signed PO copy has been uploaded for this order via "Attach Buyer PO copy". Default on — this is the fix for a real gap: recording just a reference number used to be enough to pass the gate.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'On (default) = the Supplier PO cannot be confirmed as signed until the supplier''s signed copy of the current version is uploaded.'
 WHERE setting_key = 'wet_signature_required_supplier_po' AND description = 'When on, Stage 5 (Supplier PO) cannot be confirmed signed until the supplier''s signed acknowledgment copy has been uploaded for the current Supplier PO version. Default on — same class of gap as the Buyer PO flag above.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The number Zoho gives the mail account that e-mails are sent from.'
 WHERE setting_key = 'zoho_account_id' AND description = 'Zoho Mail account ID (from Zoho Mail API''s accounts endpoint) that mail is sent from.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Zoho''s login address for your region, e.g. accounts.zoho.in for India.'
 WHERE setting_key = 'zoho_accounts_domain' AND description = 'Zoho OAuth token endpoint domain — Zoho is region-specific (accounts.zoho.com / .eu / .in / .com.cn / .com.au, matching whichever data center the Zoho One account lives in).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Zoho Mail''s address for the same region, e.g. mail.zoho.in.'
 WHERE setting_key = 'zoho_api_domain' AND description = 'Zoho Mail API domain — matches zoho_accounts_domain''s region (mail.zoho.com / .eu / .in / .com.cn / .com.au).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The client ID of the "self client" in the Zoho API Console.'
 WHERE setting_key = 'zoho_client_id' AND description = 'Zoho API Console self-client OAuth Client ID.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The client secret of the "self client" in the Zoho API Console.'
 WHERE setting_key = 'zoho_client_secret' AND description = 'Zoho API Console self-client OAuth Client Secret.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The Zoho mailbox e-mails are sent from; it must be one of the account''s verified addresses.'
 WHERE setting_key = 'zoho_from_address' AND description = 'The Zoho mailbox address mail is sent from — must be one of the Zoho account''s own verified addresses.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Off by default. When off, or when Zoho fails, e-mails go out through the normal mail server.'
 WHERE setting_key = 'zoho_mail_enabled' AND description = 'Master on/off switch for sending through the Zoho Mail API. Off by default (no credentials configured yet) — when off, or on any Zoho send failure, mail goes out through the existing SMTP path instead. Never a hard dependency.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The long-lasting key from the Zoho API Console that lets the app send e-mail.'
 WHERE setting_key = 'zoho_refresh_token' AND description = 'Zoho OAuth refresh token (mail.send scope) — exchanged for a short-lived access token on each send.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Zoho''s login address for your region, e.g. accounts.zoho.in for India.'
 WHERE setting_key = 'zoho_books_accounts_domain' AND description = 'Zoho OAuth token endpoint domain — region-specific, matching whichever data center the Zoho One account lives in.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Zoho Books'' address for the same region, e.g. www.zohoapis.in.'
 WHERE setting_key = 'zoho_books_api_domain' AND description = 'Zoho Books API domain — matches zoho_books_accounts_domain''s region (www.zohoapis.com / .eu / .in / .com.cn / .com.au).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The client ID of the "self client" in the Zoho API Console.'
 WHERE setting_key = 'zoho_books_client_id' AND description = 'Zoho Books API Console self-client OAuth Client ID.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The client secret of the "self client" in the Zoho API Console.'
 WHERE setting_key = 'zoho_books_client_secret' AND description = 'Zoho Books API Console self-client OAuth Client Secret.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The ID of the account in Zoho Books (your bank account there) that buyer payments are recorded into.'
 WHERE setting_key = 'zoho_books_deposit_account_id' AND description = 'The Zoho Books chart-of-accounts account ID that synced customer payments are recorded as deposited into (e.g. your bank account in Zoho Books).'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Off by default. While off, "Sync now" and the scheduled sync only note "skipped — not set up" and send nothing.'
 WHERE setting_key = 'zoho_books_enabled' AND description = 'Master on/off switch for syncing revenue to Zoho Books. Off by default (no credentials configured yet) — when off, Sync Now and the scheduled job both log a single "skipped, not configured" entry and exit cleanly.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Your organisation''s ID in Zoho Books.'
 WHERE setting_key = 'zoho_books_organization_id' AND description = 'Zoho Books organization ID — required on every Books API call.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'The long-lasting key from the Zoho API Console that lets the app work in Zoho Books.'
 WHERE setting_key = 'zoho_books_refresh_token' AND description = 'Zoho Books OAuth refresh token (ZohoBooks.fullaccess scope) — exchanged for a short-lived access token on each sync.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');

UPDATE company_settings SET description = 'Only invoices, debit notes, payments, bills and expenses dated on or after this day are sent to Zoho Books; earlier ones are the CA''s opening position. Needed on the live site — without it nothing is sent.'
 WHERE setting_key = 'zoho_books_sync_start_date' AND description = 'Go-live date for Zoho Books (YYYY-MM-DD). Only CIs, Debit Notes, payments, bills, vendor payments and order expenses dated on or after it are sent; anything earlier is booked by the CA as the opening position. Required on the live server (ZOHO_ENVIRONMENT=production): without it nothing is sent.'
   AND NOT (is_protected = 1 AND COALESCE(setting_value, '') = '');


-- Check (read only): settings whose description still holds a developer note.
SELECT setting_key, description FROM company_settings
 WHERE description LIKE '%SessionAuth%' OR description LIKE '%architecture doc%' OR description LIKE '%backlog%' OR description LIKE '%Addition beyond the spec%';
