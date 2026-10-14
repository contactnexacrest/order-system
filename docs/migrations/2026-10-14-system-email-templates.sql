-- ================================================================
-- DB MIGRATION — 2026-10-14
-- 90-item backlog #37: full completeness audit of the Email Templates
-- module. Found 7 system/transactional emails whose subject/body were
-- hardcoded inline in PHP/Node rather than pulled from the admin-editable
-- email_templates table — the 2FA login code, both the staff and
-- client-portal password-reset emails, the client-portal invite
-- ("set your password") email, and the 3 staff-generated one-time link
-- emails (quotation-correction, PI-form, Buyer Action). Each one's code
-- path now reads from this table first (EmailTemplateRepository::
-- renderOrDefault() in PHP, the mirrored helper in Node) and only falls
-- back to the original hardcoded wording if a row is missing/deactivated.
--
-- Pure data insert into the existing email_templates table (no ALTER
-- TABLE, no new table — same table the "Send to Buyer" templates already
-- live in). INSERT IGNORE makes it safe to run more than once —
-- template_key is UNIQUE, so a prior run is a silent no-op. Every row's
-- subject/body below is byte-for-byte the original hardcoded wording, so
-- applying this migration changes nothing visible until an admin
-- actually edits one of these 7 templates from Admin > Settings > Email
-- Templates.
--
-- Run this ONCE against each production database this app uses (the PHP
-- stack's DB and/or the Node stack's DB — they use the same schema).
-- Already applied to both local dev databases (nexacrest,
-- nexacrest_node_test) during this session. NOT yet applied anywhere
-- else.
-- ================================================================

INSERT IGNORE INTO email_templates (template_key, subject, body, footer) VALUES
  ('two_factor_code', 'Your NexaCrest login verification code',
   'Your verification code is: {code}\nThis code expires in 5 minutes. If you did not request this, contact your administrator.',
   NULL),
  ('client_portal_invite', 'Your NexaCrest order portal access',
   'Hello,\n\nYour advance payment has been received and cleared — thank you.\n\nYou can now log in to track your order and download your documents (Quotation, Proforma Invoice, and everything issued from here onward) at any time.\n\nTo set up your login, open this link within 72 hours:\n{set_password_link}\n\nYour login email will be: {email}\n\nNexaCrest International Private Limited',
   NULL),
  ('staff_password_reset', 'Reset your NexaCrest password',
   'Hello {name},\n\nA password reset was requested for your NexaCrest Export Operations account ({email}).\n\nTo set a new password, open this link within 45 minutes:\n{reset_link}\n\nIf you didn''t request this, you can ignore this email — your password will not be changed.',
   NULL),
  ('client_portal_password_reset', 'Reset your NexaCrest order portal password',
   'Hello,\n\nA password reset was requested for your NexaCrest order portal account ({email}).\n\nTo set a new password, open this link within 72 hours:\n{reset_link}\n\nIf you didn''t request this, you can ignore this email — your password will not be changed.',
   NULL),
  ('quotation_correction_link', 'Your NexaCrest quotation request — correction link',
   'Hello,\n\nThank you for your quotation request. We''ll review it and get back to you within 24 hours.\n\nSpotted a mistake in what you submitted? You can correct it yourself, as long as we haven''t already processed it, at:\n{correction_link}\n\nThis link works for 7 days.\n\nNexaCrest International Private Limited',
   NULL),
  ('pi_form_link', 'NexaCrest — confirm your PI-stage details',
   'Hello,\n\nThank you for accepting our Quotation for order {order_reference}.\n\nTo issue your Proforma Invoice, please confirm your shipping/consignee details at:\n{pi_form_link}\n\nThis link works for 30 days.\n\nNexaCrest International Private Limited',
   NULL),
  ('buyer_action_link', 'NexaCrest — acknowledge your order / upload your Buyer PO',
   'Hello,\n\nTo acknowledge your Order Confirmation and/or upload your signed Buyer PO copy for order {order_reference}, please visit:\n{buyer_action_link}\n\nThis link works for 90 days.\n\nNexaCrest International Private Limited',
   NULL);
