'use strict';

/**
 * Port of App\Helpers\SettingLabels — owner decision D-332 (12 Nov 2026):
 * Settings › Company shows every setting with a plain-English name and a plain
 * section title. The technical key stays only as small grey text (useful when
 * talking to support). A help sentence here replaces a stored description that
 * was written for developers; null = show the stored description. A key or
 * section not listed falls back to a readable form of its name. Same wording as
 * the PHP stack — the parity test compares the two.
 */

/** category => section title */
const SECTIONS = {
  alerts: 'Reminders and alerts',
  bank: 'Company bank account',
  capexil: 'CAPEXIL membership (RCMC)',
  clients: 'Client portal',
  company: 'Company details',
  compliance: 'Export benefits and ECGC',
  defaults: 'Quotation defaults',
  disputes: 'Disputes and working days',
  document_cleanup: 'Document copies clean-up',
  documents: 'Documents',
  formats: 'Reference number formats',
  lut: 'LUT (Letter of Undertaking)',
  mail: 'E-mail',
  mail_redirect: 'E-mail copies and redirect',
  order_defaults: 'New order defaults',
  orders: 'Orders',
  rbi: 'RBI purpose codes',
  security: 'Security and logging in',
  shipping: 'Bill of Lading instructions',
  sop_access: 'SOP library',
  system: 'System',
  tolerances: 'Tolerances',
  ui: 'Screen layout',
  workflow: 'Signed copies',
  zoho: 'Zoho Mail',
  zoho_books: 'Zoho Books',
};

/** setting_key => [label, help sentence or null] */
const LABELS = {
  fdn_overdue_days_c: ['Freight payment overdue after … days', 'Days after the date on the freight debit note (FDN) before its payment shows as overdue (calendar days). Keep it the same as the "payable within 3 Calendar Days" in the freight clause of the Quotation and Proforma.'],
  fema_realization_alert_days: ['FIRC / eBRC still missing: alert after … days', 'Days after a payment is marked cleared before it is flagged because its FIRC / eBRC reference is still missing (270 days = the 9-month RBI realisation period).'],
  lut_alert_days_x: ['LUT expiry: first alert … days before', null],
  lut_escalation_days_y: ['LUT expiry: tell the MD … days before', null],
  rcmc_alert_days_a: ['RCMC expiry: first alert … days before', null],
  rcmc_escalation_days_b: ['RCMC expiry: tell the MD … days before', null],
  bank_account_no: ['Account number', null],
  bank_address: ['Branch address', null],
  bank_branch: ['Branch name', null],
  bank_name: ['Bank name', null],
  bank_pincode: ['Branch PIN code', null],
  ifsc: ['IFSC code', null],
  swift_bic: ['SWIFT / BIC code', null],
  rcmc_number: ['RCMC number', 'CAPEXIL Registration-cum-Membership Certificate (RCMC) number, printed on the Certificate of Origin preparation sheet.'],
  rcmc_valid_until: ['RCMC valid until', 'Expiry date of the CAPEXIL RCMC certificate (renewed every year). The reminders follow the RCMC days under "Reminders and alerts".'],
  client_impersonation_enabled: ['Staff may "Log in as this client"', 'Off = no staff member can open a client\'s portal as that client, whatever is set on the client or the role.'],
  client_portal_show_document_downloads: ['Clients can download their documents', 'On (default) = clients can download the approved documents of their own orders from the portal. Off = the Documents section is hidden, unless a client\'s own setting says otherwise.'],
  client_portal_show_order_history: ['Clients see their full order history', 'On (default) = clients see all their orders, including completed and lost ones. Off = only active and disputed orders, unless a client\'s own setting says otherwise.'],
  corporate_office: ['Corporate / working office address', null],
  director_name: ['Director name (for a director signature)', 'Name of the director, used only if a document carries a director\'s signature (Quotation, Proforma and OC show the Managing Director only).'],
  director_title: ['Director title', null],
  email: ['Company e-mail address', null],
  gstin: ['GSTIN', null],
  iec_pan: ['IEC / PAN', null],
  legal_name: ['Legal company name', null],
  md_name: ['Managing Director name', null],
  md_title: ['Managing Director title', null],
  phone: ['Company phone number', null],
  registered_office: ['Registered office address', null],
  benefit_deadline_days_duty_drawback: ['Duty Drawback: claim deadline (days)', null],
  benefit_deadline_days_other: ['Other export schemes: claim deadline (days)', null],
  benefit_deadline_days_rodtep: ['RoDTEP: claim deadline (days)', null],
  benefit_deadline_days_rosctl: ['RoSCTL: claim deadline (days)', null],
  ecgc_declaration_day: ['ECGC: monthly declaration due by day', null],
  ecgc_policy_cover_pct: ['ECGC: cover %', null],
  ecgc_policy_no: ['ECGC policy number', null],
  ecgc_policy_valid_to: ['ECGC policy valid until', null],
  default_currency: ['Default quotation currency', null],
  dispute_response_days_n: ['Disputes: answer within … working days', 'Working days allowed to answer a buyer\'s dispute before it is escalated. Keep it the same as the "ten (10) working days" in the dispute clause printed on the Quotation, Proforma, OC and Buyer PO. Weekly days off and company holidays are not counted.'],
  weekly_off_days: ['Weekly days off', 'Days of the week that never count as working days, in small letters and separated by commas (e.g. sunday).'],
  doc_cleanup_cafin_mode: ['Old CAFIN copies', null],
  doc_cleanup_draft_days: ['Remove DRAFT copies after … days', null],
  doc_cleanup_superseded_days: ['Remove unsent superseded copies after … days', null],
  always_show_consignee_section: ['Always print the Consignee section', 'On = the CONSIGNEE DETAILS section prints on the Quotation, Proforma, OC, Packing List and Commercial Invoice even when the consignee is the buyer. Off = it prints only when the consignee is different.'],
  always_show_notify_party_section: ['Always print the Notify Party section', 'On = the NOTIFY PARTY section prints on the Proforma, Packing List and Commercial Invoice even when the notify party is the buyer. Off = it prints only when the notify party is different.'],
  annexure_product_note: ['Annexure A: product note', null],
  generated_document_disclaimer_text: ['"System-generated document" note: wording', 'The wording printed under the signature block when the "system-generated document" note is switched on.'],
  pi_validity_days: ['Proforma Invoice valid for … days', 'Days a Proforma Invoice stays valid from its issue date.'],
  quotation_validity_days: ['Quotation valid for … days', 'Days a Quotation stays valid from its issue date.'],
  revision_start_number: ['First revision number', null],
  show_generated_document_disclaimer: ['Print the "system-generated document" note', 'On = documents that carry a signature or seal image show the "system-generated document" note under the signature block. Off by default.'],
  master_tracking_ref_format: ['Buyer inquiry reference format', null],
  order_ref_format: ['Order reference format', 'The pattern of order reference numbers: a daily counter, e.g. NC/SC/ORD/26/0411001.'],
  lut_expiry_date: ['LUT expiry date', 'The date the current LUT expires — check it against the LUT certificate.'],
  lut_number: ['LUT number (ARN)', null],
  lut_valid_fy: ['LUT financial year', null],
  email_testing_mode: ['Testing mode — hold e-mails to buyers and suppliers', 'On = e-mails to buyers and suppliers are held; staff and associate e-mails and every MUST e-mail still go out. Set on Settings › E-mail.'],
  mail_approval_queue_enabled: ['Document e-mails need a second person\'s approval', 'On (default) = every document e-mail to a buyer is approved by a second person before it goes out. Off = one staff member who can generate documents sends it directly.'],
  reply_to_email: ['Reply-to address for customer e-mails', 'Where buyers\' replies go — a sales mailbox someone reads. Leave empty to use the company e-mail address.'],
  mail_cc_emails: ['Copy every e-mail to (list)', 'Extra addresses, separated by commas, copied on every e-mail except login codes and password links. Only a Super Admin can change this.'],
  mail_default_cc_email: ['Copy every e-mail to (one address)', 'One address copied on every e-mail except login codes and password links, besides the list above. Only a Super Admin can change this.'],
  mail_redirect_address: ['Send redirected e-mails to', 'While redirect is on, e-mails go to this address instead of their real recipient. If it is empty, redirected e-mails are only logged, not sent.'],
  mail_redirect_enabled: ['Redirect all e-mails to one address', 'On = every e-mail except login codes and password links goes to the address below instead of its real recipient (separate from Test Mode).'],
  default_estimated_gross_weight_kg: ['New order: gross weight (kg) to start with', null],
  default_estimated_net_weight_kg: ['New order: net weight (kg) to start with', null],
  default_estimated_total_cbm: ['New order: total CBM to start with', null],
  lead_time_display_unit: ['Show lead times in days or weeks', 'How the production and sailing lead times show on the order page. Only the display changes; they are always entered and kept in days.'],
  oc_ack_auto_confirm_hours: ['Order Confirmation: confirmed automatically after … hours', 'Hours after the Order Confirmation e-mail is sent before the order confirms itself if the buyer has not answered (48 on the live site).'],
  oc_ack_override_restricted: ['Only permitted roles may record the buyer\'s OC acknowledgement', 'Off (default) = anyone who manages orders can record the buyer\'s acknowledgement of the Order Confirmation by hand. On = only roles given the "Override buyer acknowledgement" permission.'],
  rbi_purpose_code_advance: ['Purpose code — advance payments', null],
  rbi_purpose_code_balance: ['Purpose code — balance payments', null],
  rbi_purpose_code_freight: ['Purpose code — freight / insurance payments', null],
  failed_login_lockout_count: ['Lock the account after … failed logins', null],
  lockout_duration_minutes: ['Account stays locked for … minutes', 'How many minutes an account stays locked after too many failed logins.'],
  login_remember_browser_days: ['Remember an office computer for … days', 'Staff who tick "This is my own office computer" skip the e-mailed login code on that computer for this many days (0 to 90; default 30; 0 = always ask). A password change or "Forget all remembered computers" ends it.'],
  password_complexity_json: ['Password rules', null],
  password_expiry_days: ['Passwords expire after … days', 'Staff must change their password after this many days; 0 = never (the default, as staff log in with an e-mailed code).'],
  password_min_length: ['Minimum password length', null],
  session_absolute_staff_hours: ['Staff: log in again at least every … hours', 'Staff must log in again after this many hours, however active they are (1 to 24; default 12).'],
  session_timeout_associate_minutes: ['Associates: log out after … minutes idle', 'Associates are logged out of their portal after this many minutes without activity (5 to 1440; default 120 = 2 hours).'],
  session_timeout_client_minutes: ['Buyers: log out after … minutes idle', 'Buyers are logged out of their portal after this many minutes without activity (5 to 1440; default 120 = 2 hours).'],
  session_timeout_staff_minutes: ['Staff: log out after … minutes idle', 'Staff are logged out after this many minutes without activity (5 to 1440; default 240 = 4 hours). A "Stay logged in" warning shows 2 minutes before.'],
  bl_consignee_instruction: ['BL consignee instruction', 'Printed on every BL Instruction Sheet: the BL is made out to the company\'s order, so the buyer cannot use it until the company releases it. {company} becomes the company\'s legal name.'],
  bl_type_instruction: ['BL type instruction', 'Printed on every BL Instruction Sheet: an original Bill of Lading only — no Sea Waybill or Express BL, which would let the buyer collect the goods before paying the balance.'],
  sop_library_enabled: ['SOP library on for staff', 'Off = only the MD and Admin see the SOP library; Getting Started stays open. Set on Settings › SOP access.'],
  storage_base_path: ['File storage folder (for information only)', 'For information only — the app reads the real folder from the server\'s .env file, not from here.'],
  max_bank_charges_pct: ['Bank charges allowed (% of the amount due)', null],
  non_usd_price_buffer_pct: ['Price buffer for non-USD quotes (%)', 'A suggested extra margin shown (not added automatically) on quotes in a currency other than USD.'],
  quantity_shortfall_tolerance_pct: ['Quantity shortfall allowed (%)', null],
  default_ui_template: ['Default screen layout', 'The layout (Standard or Kanban Board) a user sees until they choose their own on My Account. Only active layouts can be picked.'],
  wet_signature_required_buyer_po: ['Buyer PO: signed copy required', 'On (default) = the Buyer PO step cannot be confirmed until the buyer\'s signed PO copy is uploaded; a reference number alone is not enough.'],
  wet_signature_required_supplier_po: ['Supplier PO: signed copy required', 'On (default) = the Supplier PO cannot be confirmed as signed until the supplier\'s signed copy of the current version is uploaded.'],
  zoho_account_id: ['Zoho Mail account ID', 'The number Zoho gives the mail account that e-mails are sent from.'],
  zoho_accounts_domain: ['Zoho login server', 'Zoho\'s login address for your region, e.g. accounts.zoho.in for India.'],
  zoho_api_domain: ['Zoho Mail server', 'Zoho Mail\'s address for the same region, e.g. mail.zoho.in.'],
  zoho_client_id: ['Zoho Mail client ID', 'The client ID of the "self client" in the Zoho API Console.'],
  zoho_client_secret: ['Zoho Mail client secret', 'The client secret of the "self client" in the Zoho API Console.'],
  zoho_from_address: ['Send from (Zoho mailbox)', 'The Zoho mailbox e-mails are sent from; it must be one of the account\'s verified addresses.'],
  zoho_mail_enabled: ['Send e-mails through Zoho Mail', 'Off by default. When off, or when Zoho fails, e-mails go out through the normal mail server.'],
  zoho_refresh_token: ['Zoho Mail refresh token', 'The long-lasting key from the Zoho API Console that lets the app send e-mail.'],
  zoho_api_daily_limit: ['Zoho calls per day (at most)', null],
  zoho_api_minute_limit: ['Zoho calls per minute (at most)', null],
  zoho_books_accounts_domain: ['Zoho Books login server', 'Zoho\'s login address for your region, e.g. accounts.zoho.in for India.'],
  zoho_books_api_domain: ['Zoho Books server', 'Zoho Books\' address for the same region, e.g. www.zohoapis.in.'],
  zoho_books_client_id: ['Zoho Books client ID', 'The client ID of the "self client" in the Zoho API Console.'],
  zoho_books_client_secret: ['Zoho Books client secret', 'The client secret of the "self client" in the Zoho API Console.'],
  zoho_books_deposit_account_id: ['Zoho Books bank account for payments received', 'The ID of the account in Zoho Books (your bank account there) that buyer payments are recorded into.'],
  zoho_books_enabled: ['Send accounts to Zoho Books', 'Off by default. While off, "Sync now" and the scheduled sync only note "skipped — not set up" and send nothing.'],
  zoho_books_organization_id: ['Zoho Books organisation ID', 'Your organisation\'s ID in Zoho Books.'],
  zoho_books_refresh_token: ['Zoho Books refresh token', 'The long-lasting key from the Zoho API Console that lets the app work in Zoho Books.'],
  zoho_books_restore_checked_at: ['Last full check of Zoho Books (set by the app)', null],
  zoho_books_sync_start_date: ['Zoho Books start date', 'Only invoices, debit notes, payments, bills and expenses dated on or after this day are sent to Zoho Books; earlier ones are the CA\'s opening position. Needed on the live site — without it nothing is sent.'],
  zoho_sync_clients: ['Send clients to Zoho Books as customers', null],
  zoho_sync_products: ['Send products to Zoho Books as items', null],
};

function humanise(key) {
  const t = String(key).replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
  return t.charAt(0).toUpperCase() + t.slice(1);
}

function sectionTitle(category) {
  return Object.prototype.hasOwnProperty.call(SECTIONS, category) ? SECTIONS[category] : humanise(category);
}

function label(key) {
  return Object.prototype.hasOwnProperty.call(LABELS, key) ? LABELS[key][0] : humanise(key);
}

/** The plain help sentence, or the stored description when there is none. */
function help(key, storedDescription) {
  const own = Object.prototype.hasOwnProperty.call(LABELS, key) ? LABELS[key][1] : null;
  return own !== null ? own : String(storedDescription || '');
}

/** The sections in the order of their plain titles (A to Z), as a new object. */
function sortSections(grouped) {
  const keys = Object.keys(grouped).sort((a, b) => {
    const x = sectionTitle(a).toLowerCase();
    const y = sectionTitle(b).toLowerCase();
    return x < y ? -1 : (x > y ? 1 : 0);
  });
  const out = {};
  for (const k of keys) out[k] = grouped[k];
  return out;
}

module.exports = { SECTIONS, LABELS, humanise, sectionTitle, label, help, sortSections };
