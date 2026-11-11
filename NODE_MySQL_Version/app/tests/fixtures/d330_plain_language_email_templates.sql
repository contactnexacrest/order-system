-- D-330 (11 Nov 2026): plain-language e-mail templates for buyers — guarded, safe to run twice.
-- Each UPDATE changes a template only while its body is still exactly the text seeded before D-330,
-- so an admin's own edit is never overwritten. The subject changes only if it, too, is still the old
-- seeded subject. A second run finds nothing to change. MySQL and MariaDB.
-- Also: the description of the approve_payment_terms permission (only while it is the old text).
SET NAMES utf8mb4;

-- balance_receipt_confirmation
UPDATE email_templates
   SET subject = IF(subject = 'Balance Payment Received — Order {order_reference}', 'Balance Payment Received — Order {order_reference}', subject),
       body = 'Dear {buyer_contact_person},\n\nWe have received your balance payment for order {order_reference} (your enquiry reference: {buyer_inquiry_ref}). Thank you for your business. We are now finishing the last shipping paperwork.\n\n{sender_signature}'
 WHERE template_key = 'balance_receipt_confirmation'
   AND body = 'Dear {buyer_contact_person},\n\nWe confirm receipt and clearance of the balance payment for your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). Thank you for your business — we are proceeding with final despatch formalities.\n\n{sender_signature}';

-- bl_copy_sent
UPDATE email_templates
   SET subject = IF(subject = 'Bill of Lading Copy — Order {order_reference}', 'Bill of Lading Copy — Order {order_reference}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached the scanned copy of the Bill of Lading for your order {order_reference} (your enquiry reference: {buyer_inquiry_ref}). As agreed, we will send you the original bills of lading by courier once we have received your balance payment.\n\n{sender_signature}'
 WHERE template_key = 'bl_copy_sent'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached the scanned copy of the Bill of Lading for your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). BL originals will be couriered upon clearance of the balance payment, per the agreed terms.\n\n{sender_signature}';

-- buyer_action_link
UPDATE email_templates
   SET subject = IF(subject = 'NexaCrest — acknowledge your order / upload your Buyer PO', 'NexaCrest — sign your purchase order and confirm your order', subject),
       body = 'Hello,\n\nFor order {order_reference}, please use this link to:\n1. download your purchase order,\n2. upload the signed copy with your PO number,\n3. confirm your order when you receive our order confirmation.\n\n{buyer_action_link}\n\nThis link works for 90 days.\n\nNexaCrest International Private Limited'
 WHERE template_key = 'buyer_action_link'
   AND body = 'Hello,\n\nFor order {order_reference}, please use this link to download your Purchase Order, upload the signed copy with your PO number, and acknowledge your Order Confirmation once you receive it:\n{buyer_action_link}\n\nThis link works for 90 days.\n\nNexaCrest International Private Limited';

-- client_portal_invite
UPDATE email_templates
   SET subject = IF(subject = 'Your NexaCrest order portal access', 'Your NexaCrest order portal access', subject),
       body = 'Hello,\n\nThank you — we have received your advance payment.\n\nYou can now sign in at any time to follow your order and download your documents: the quotation, the proforma invoice and every document we send you from now on.\n\nTo set up your login, open this link within 72 hours (it works once):\n{set_password_link}\n\nYour login email will be: {email}\n\nAfter that, log in any time at:\n{login_link}\n\nNexaCrest International Private Limited'
 WHERE template_key = 'client_portal_invite'
   AND body = 'Hello,\n\nYour advance payment has been received and cleared — thank you.\n\nYou can now log in to track your order and download your documents (Quotation, Proforma Invoice, and everything issued from here onward) at any time.\n\nTo set up your login, open this link within 72 hours (it works once):\n{set_password_link}\n\nYour login email will be: {email}\n\nAfter that, log in any time at:\n{login_link}\n\nNexaCrest International Private Limited';

-- client_portal_password_reset
UPDATE email_templates
   SET subject = IF(subject = 'Reset your NexaCrest order portal password', 'Reset your NexaCrest order portal password', subject),
       body = 'Hello,\n\nSomeone asked to reset the password of your NexaCrest buyer account ({email}).\n\nTo set a new password, open this link within 72 hours:\n{reset_link}\n\nIf you didn''t request this, you can ignore this email — your password will not be changed.'
 WHERE template_key = 'client_portal_password_reset'
   AND body = 'Hello,\n\nA password reset was requested for your NexaCrest order portal account ({email}).\n\nTo set a new password, open this link within 72 hours:\n{reset_link}\n\nIf you didn''t request this, you can ignore this email — your password will not be changed.';

-- payment_followup
UPDATE email_templates
   SET subject = IF(subject = 'Payment Follow-Up — Order {order_reference}', 'Payment Follow-Up — Order {order_reference}', subject),
       body = 'Dear {buyer_contact_person},\n\nA payment on your order {order_reference} (your enquiry reference: {buyer_inquiry_ref}) is still open. Please make the payment as soon as you can and send us the bank receipt, so we can continue with your order.\n\n{sender_signature}'
 WHERE template_key = 'payment_followup'
   AND body = 'Dear {buyer_contact_person},\n\nThis is a follow-up regarding the pending payment on your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). Kindly arrange the payment at your earliest convenience and share the remittance copy so we can proceed.\n\n{sender_signature}';

-- pi_form_link
UPDATE email_templates
   SET subject = IF(subject = 'NexaCrest — confirm your PI-stage details', 'NexaCrest — please confirm your details for the proforma invoice', subject),
       body = 'Hello,\n\nThank you for accepting our Quotation for order {order_reference}.\n\nBefore we can send your proforma invoice, please confirm your shipping details and who receives the goods:\n{pi_form_link}\n\nThis link works for 30 days.\n\nNexaCrest International Private Limited'
 WHERE template_key = 'pi_form_link'
   AND body = 'Hello,\n\nThank you for accepting our Quotation for order {order_reference}.\n\nTo issue your Proforma Invoice, please confirm your shipping/consignee details at:\n{pi_form_link}\n\nThis link works for 30 days.\n\nNexaCrest International Private Limited';

-- quotation_correction_link
UPDATE email_templates
   SET subject = IF(subject = 'Your NexaCrest quotation request — correction link', 'Your NexaCrest quotation request — correction link', subject),
       body = 'Hello,\n\nThank you for your quotation request. We''ll review it and get back to you within 24 hours.\n\nDid you make a mistake in your request? You can correct it yourself until our team starts checking it. Use this link:\n{correction_link}\n\nThis link works for 7 days.\n\nNexaCrest International Private Limited'
 WHERE template_key = 'quotation_correction_link'
   AND body = 'Hello,\n\nThank you for your quotation request. We''ll review it and get back to you within 24 hours.\n\nSpotted a mistake in what you submitted? You can correct it yourself, as long as we haven''t already processed it, at:\n{correction_link}\n\nThis link works for 7 days.\n\nNexaCrest International Private Limited';

-- send_annexa
UPDATE email_templates
   SET subject = IF(subject = 'Annexure A {document_reference} — {company_name}', 'Annexure A {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached Annexure A {document_reference} dated {generated_date}, accompanying your order (your enquiry reference: {buyer_inquiry_ref}). Please read this annexure together with the main document it belongs to.\n\n{sender_signature}'
 WHERE template_key = 'send_annexa'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached Annexure A {document_reference} dated {generated_date}, accompanying your order (Buyer Inquiry Ref: {buyer_inquiry_ref}). This annexure must always be read together with its parent document.\n\n{sender_signature}';

-- send_ble
UPDATE email_templates
   SET subject = IF(subject = 'Bill of Lading Endorsement {document_reference} — {company_name}', 'Bill of Lading Endorsement {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached the Bill of Lading Endorsement {document_reference} dated {generated_date} for your order (your enquiry reference: {buyer_inquiry_ref}).\n\n{sender_signature}'
 WHERE template_key = 'send_ble'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached the Bill of Lading Endorsement {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}';

-- send_buyerpo
UPDATE email_templates
   SET subject = IF(subject = 'Buyer PO — Order Acceptance {document_reference} — {company_name}', 'Buyer PO — Order Acceptance {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached our formal acceptance of your Purchase Order, {document_reference} dated {generated_date}, based on quotation {quotation_ref} (your enquiry reference: {buyer_inquiry_ref}).\n\n{sender_signature}'
 WHERE template_key = 'send_buyerpo'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached our formal acceptance of your Purchase Order, {document_reference} dated {generated_date}, issued against Quotation {quotation_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}';

-- send_ci
UPDATE email_templates
   SET subject = IF(subject = 'Commercial Invoice {document_reference} — {company_name}', 'Commercial Invoice {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached Commercial Invoice {document_reference} dated {generated_date} for your order (your enquiry reference: {buyer_inquiry_ref}).\n\n{payment_request_sentence}\n\n{sender_signature}'
 WHERE template_key = 'send_ci'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached Commercial Invoice {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{payment_request_sentence}\n\n{sender_signature}';

-- send_esi
UPDATE email_templates
   SET subject = IF(subject = 'Export Service Invoice {document_reference} — {company_name}', 'Export Service Invoice {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached Export Service Invoice {document_reference} dated {generated_date} for the services on your order (your enquiry reference: {buyer_inquiry_ref}).\n\nPlease pay as set out in the invoice.\n\n{sender_signature}'
 WHERE template_key = 'send_esi'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached Export Service Invoice {document_reference} dated {generated_date} for the services on your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nKindly arrange the payment as per the terms stated in the invoice.\n\n{sender_signature}';

-- send_fdn
UPDATE email_templates
   SET subject = IF(subject = 'Debit Note {document_reference} — Payment Required Before Shipment', 'Freight bill (debit note) {document_reference} — please pay before we ship', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached Debit Note {document_reference} dated {generated_date} (your enquiry reference: {buyer_inquiry_ref}).\n\nPlease pay this within 3 days of the date on it. We can book your shipment only after we receive the full freight payment.\n\n{sender_signature}'
 WHERE template_key = 'send_fdn'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached Debit Note {document_reference} dated {generated_date} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nPayment is required within 3 Calendar Days of this Debit Note''s date. We will confirm shipment booking only upon receipt of full freight payment.\n\n{sender_signature}';

-- send_oc
UPDATE email_templates
   SET subject = IF(subject = 'Order Confirmation {document_reference} — {company_name}', 'Order Confirmation {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nWe are pleased to confirm your order. Please find attached Order Confirmation {document_reference} dated {generated_date}, based on proforma invoice {pi_ref} (your enquiry reference: {buyer_inquiry_ref}).\n\nWe will make your goods on the dates given in the attached document.\n\n{sender_signature}'
 WHERE template_key = 'send_oc'
   AND body = 'Dear {buyer_contact_person},\n\nWe are pleased to confirm your order. Please find attached Order Confirmation {document_reference} dated {generated_date}, issued against Proforma Invoice {pi_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nProduction will proceed as per the schedule stated in the attached document.\n\n{sender_signature}';

-- send_pi
UPDATE email_templates
   SET subject = IF(subject = 'Proforma Invoice {document_reference} — {company_name}', 'Proforma Invoice {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached Proforma Invoice {document_reference} dated {generated_date}, based on quotation {quotation_ref} (your enquiry reference: {buyer_inquiry_ref}).\n\nThis Proforma Invoice is valid until {pi_valid_until}. {payment_request_sentence}\n\n{sender_signature}'
 WHERE template_key = 'send_pi'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached Proforma Invoice {document_reference} dated {generated_date}, issued against Quotation {quotation_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nThis Proforma Invoice is valid until {pi_valid_until}. {payment_request_sentence}\n\n{sender_signature}';

-- send_pl
UPDATE email_templates
   SET subject = IF(subject = 'Packing List {document_reference} — {company_name}', 'Packing List {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached Packing List {document_reference} dated {generated_date} for your order (your enquiry reference: {buyer_inquiry_ref}).\n\n{sender_signature}'
 WHERE template_key = 'send_pl'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached Packing List {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}';

-- send_qt
UPDATE email_templates
   SET subject = IF(subject = 'Quotation {document_reference} — {company_name}', 'Quotation {document_reference} — {company_name}', subject),
       body = 'Dear {buyer_contact_person},\n\nPlease find attached our Quotation {document_reference} dated {generated_date} for your reference (your enquiry reference: {buyer_inquiry_ref}).\n\nThis quotation is valid until {quotation_valid_until}. Please let us know if you have any questions or would like to proceed.\n\n{sender_signature}'
 WHERE template_key = 'send_qt'
   AND body = 'Dear {buyer_contact_person},\n\nPlease find attached our Quotation {document_reference} dated {generated_date} for your reference (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nThis quotation is valid until {quotation_valid_until}. Please let us know if you have any questions or would like to proceed.\n\n{sender_signature}';

-- shipment_readiness
UPDATE email_templates
   SET subject = IF(subject = 'Shipment Readiness Confirmation — Order {order_reference}', 'Your order {order_reference} is ready to ship (shipment readiness confirmation)', subject),
       body = 'Dear {buyer_contact_person},\n\nWe are pleased to confirm that your order {order_reference} (your enquiry reference: {buyer_inquiry_ref}) is packed and ready for shipment. We will share the shipment documents with you as they are issued.\n\n{sender_signature}'
 WHERE template_key = 'shipment_readiness'
   AND body = 'Dear {buyer_contact_person},\n\nWe are pleased to confirm that your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}) is packed and ready for shipment. We will share the shipment documents with you as they are issued.\n\n{sender_signature}';

-- approve_payment_terms: the description named only Admin, MD, ED; the seed also grants it to
-- Sales/Export Manager, Sales Director and Finance Director.
UPDATE permissions
   SET description = 'Approve an order''s payment terms when its preset is marked "Requires MD approval" — needed before the Quotation can be generated or sent. Default: Admin, Managing Director, Executive Director, Sales/Export Manager, Sales Director, Finance Director.'
 WHERE permission_key = 'approve_payment_terms'
   AND description = 'Approve an order''s payment terms when its preset is marked "Requires MD approval" — needed before the Quotation can be generated or sent. Default: Admin, MD, ED.';
