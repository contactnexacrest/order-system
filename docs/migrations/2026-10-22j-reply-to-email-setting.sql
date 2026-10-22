-- External QA report L-43 (Medium, Both stacks): "Customer e-mails come
-- from noreply@... with no Reply-To, so when a buyer replies to a QT/PI/OC
-- (e.g. 'we confirm the OC') the reply goes to the no-reply mailbox and is
-- lost." Adds the company_settings.reply_to_email key the application code
-- fix (EmailService.php / emailService.js) reads — falling back to the
-- existing company_settings.email when left blank. Apply to both the PHP
-- and Node MySQL databases — same schema.

SET NAMES utf8mb4;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin)
SELECT 'reply_to_email', '', 'email', 'mail',
       'External QA report L-43: customer emails (QT/PI/OC/etc.) went out from a no-reply address with no Reply-To, so a buyer reply was lost. Set a monitored sales/support address here so replies reach someone; leave blank to fall back to company_settings.email.',
       0, 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'reply_to_email');
