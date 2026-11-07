'use strict';
// D01 — For buyers: from your first enquiry to the Proforma Invoice (scenarios 1–5).
// The buyer does every step on camera with no login: the quotation details form, the Buyer
// Action Link (download our Purchase Order, upload the signed copy) and the PI details form.
// Staff hand-offs are short; their routine steps between scenes run off camera (scripts/_buyer.js).
// Source of truth: docs/APPLICATION_HANDBOOK.md 5.2–5.5, SOP 01–04.

const demo = require('../lib/demo');
const B = require('./_buyer');

const st = { order: 0, client: 0, qt: '', pi: '', buyerLink: '', piLink: '' };

module.exports = {
  code: 'D01',
  file: 'D01-Client-From-Enquiry-To-Proforma.mp4',
  group: 'Group D · Buyers',
  title: 'From Your Enquiry to the Proforma Invoice',
  subtitle: 'For buyers: asking for a quotation, accepting it, and confirming your details — no login needed.',
  minutes: '10',
  restoreDb: true,

  async setup(d) { return B.setup(d); },

  steps: () => [
    { card: 'title', say: 'Welcome! This video is for our buyers. We will follow one buyer, a stone importer in Rotterdam, from the very first enquiry to the Proforma Invoice that asks for the advance payment. And here is the good news: for everything in this video, you never need a login or a password. Every step is a simple web page, opened from a link we send you.' },
    { card: 'list', kicker: 'In this video', title: 'Five things you will do', items: [
      'Fill in the Quotation Details Form: your company and shipping details.',
      'Receive our Quotation by e-mail, and tell us you want to go ahead.',
      'Open your Buyer Action Link: download our Purchase Order, upload the signed copy.',
      'Confirm your details on the Proforma Invoice Details Form.',
      'Receive the Proforma Invoice, with the advance you pay now.',
    ], say: 'Here are the five things you will do. Fill in the quotation details form. Receive our quotation, and tell us you want to go ahead. Open your Buyer Action Link, to download our purchase order and upload the signed copy. Confirm your details on the Proforma Invoice details form. And finally, receive the Proforma Invoice, which tells you the advance to pay. Watch the badge in the top right corner: green means you, the buyer.' },

    // ---------- 1. Quotation details form ----------
    { card: 'chapter', title: 'Asking for a quotation', subtitle: 'The Quotation Details Form.', say: 'Part one. Asking for a quotation.' },
    { as: 'buyer', goto: '/quotation-details', caption: 'The Quotation Details Form', say: 'When you first talk to us, we send you a link to this page: the Quotation Details Form. It asks only for your company and shipping details, in three short sections. It takes about two minutes. You do not describe the products here: that conversation happens with our team, by e-mail or phone.', do: async (d) => { await d.point('h1', 1200); await d.point('.intake-summary', 1500); } },
    { caption: 'Section 1 · Your details', say: 'Section one: your details. Your company\'s full legal name, exactly as it should appear on invoices. Then your billing address: street, city, postcode and country.', do: async (d) => {
      await d.type('input[name="company_legal_name"]', B.BUYER.company);
      await d.type('input[name="billing_address_line1"]', B.BUYER.line1);
      await d.type('input[name="billing_city"]', B.BUYER.city);
      await d.type('input[name="billing_postcode"]', B.BUYER.postcode);
      await d.select('#billing_country_select', 'Netherlands');
    } },
    { caption: 'Who we talk to', say: 'Next, who we should talk to: a contact person, your e-mail address, and a phone number. Your e-mail address matters most: every link and every document comes to this address. Then your V A T, E O R I or tax registration number, and the country the goods are going to.', do: async (d) => {
      await d.type('input[name="contact_person"]', B.BUYER.contact);
      await d.type('input[name="email"]', B.BUYER_EMAIL);
      await d.type('input[name="phone"]', B.BUYER.phone);
      await d.type('input[name="vat_eori_tax_no"]', B.BUYER.vat);
      await d.select('#country_of_destination_select', 'Netherlands');
      await d.type('input[name="port_of_discharge_text"]', 'Rotterdam');
    } },
    { caption: 'Section 2 · Consignee', say: 'Section two: the consignee, the company that receives the goods at the port. Most buyers receive the goods themselves, so this box is already ticked: same as buyer. If a different company receives them, untick it, and fill in their details.', do: async (d) => { await d.scrollTo('#consignee_same_as_buyer'); await d.point(d.page.locator('label').filter({ has: d.page.locator('#consignee_same_as_buyer') }), 2200); } },
    { caption: 'Section 3 · Shipping preference', say: 'Section three: how you would like to ship. Choose your Incoterm. Our buyer wants C I F: we pay the freight and the insurance to Rotterdam. And in the notes, anything that helps us prepare the quotation: the stone, the sizes, the quantities, the timing.', do: async (d) => {
      await d.select('#incoterm_select', 'CIF');
      await d.type('textarea[name="notes"]', 'Black Galaxy granite slabs, 150 sqm, and Absolute Black tiles 60 x 60, 300 sqm. Shipment within 6 weeks.', { delay: 30 });
    } },
    { caption: 'Submit Request', say: 'Submit request. Done. We will review it and reply with a quotation within twenty four hours. And if you spot a mistake after sending, the e-mail we send you has a correction link, so you can fix it yourself before we review it.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Submit Request' })); await d.point('h1', 1200); await d.point(d.page.getByText('We\'ve received your quotation request', { exact: false }), 1500); } },
    { as: 'exec', goto: '/client-intake', caption: 'Our side: Quotation Intake Review', say: 'A quick look at our side. Your request lands in our Quotation Intake Review. Our Export Executive checks it and clicks: Accept and Create Client. From now on you are a client in our system, with your own Buyer Inquiry Reference.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: B.BUYER.company }).first(), 1800); await d.clickNav(d.page.getByRole('button', { name: 'Accept & Create Client' }).first()); await d.point('h1', 1200); } },

    // ---------- 2. The quotation ----------
    { card: 'chapter', title: 'Your quotation', subtitle: 'By e-mail, with the PDF attached.', say: 'Part two. Your quotation.', do: async () => {
      st.client = B.clientIdByEmail(demo);
      st.order = B.createOrder(demo, st.client);
      st.qt = B.moves.quotation(demo, st.order);
    } },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Quotation ${st.qt} — ${B.BUYER.company}`; },
      get lines() { return ['Dear Purchasing Manager,', `Please find attached our Quotation ${st.qt} for your reference.`, 'This quotation is valid until the date shown on it. Please let us know if you have any questions or would like to proceed.']; },
      get attach() { return [`${st.qt.replace(/\//g, '-')}.pdf`]; },
      say: 'Our team prices your order, and the quotation is checked and approved by a second person before it ever reaches you. Then it arrives by e-mail, as a P D F. It shows every product line, the price, the Incoterm, the payment terms, and the date until which the price is valid.' },
    { card: 'list', kicker: 'Before you reply', title: 'Read these on your quotation', items: [
      'Valid until: after this date, ask us to re-confirm the price.',
      'Payment terms: here, 40% advance and the balance before shipment.',
      'Questions or changes? Just reply to the e-mail.',
      'Ready to go ahead? Reply that you accept: we prepare your Purchase Order.',
    ], say: 'Three things to read carefully. First, the valid until date: after it, please ask us to confirm the price again. Second, the payment terms: for a new buyer like ours, forty percent in advance, and the balance before shipment. Third: if you have a question, or want a change, simply reply to the e-mail. Your Export Executive answers. And when you are happy, reply that you accept. That is all we need to prepare your purchase order.' },

    // ---------- 3. Buyer Action Link and the signed PO ----------
    { card: 'chapter', title: 'Your Buyer Action Link', subtitle: 'Download our Purchase Order, upload the signed copy.', say: 'Part three. Your Buyer Action Link.', do: async () => { st.buyerLink = B.moves.buyerPoLink(demo, st.order); } },
    { card: 'email', to: B.BUYER_EMAIL, subject: 'NexaCrest — acknowledge your order / upload your Buyer PO',
      lines: ['Hello,', 'For your order, please use this link to download your Purchase Order, upload the signed copy with your PO number, and acknowledge your Order Confirmation once you receive it.', 'This link works for 90 days.'],
      link: 'Buyer Action Link (secure — opens without a login)',
      say: 'You accepted. So we prepare the purchase order for you, in our standard format, and send you your Buyer Action Link. One link for the whole start of your order: it works for ninety days, and it always shows you just the one thing to do next.' },
    { as: 'buyer', do: async (d) => { await d.goto(`/buyer-action/${st.buyerLink}`); await d.point('h1', 1200); await d.point('.intake-summary', 1300); await d.point('.action-progress', 1500); }, caption: 'Your Order page', say: 'Here it is. Your order reference, the number of product lines, the order value, and the Incoterm. Along the top, your three steps: the purchase order, the advance payment, and the order confirmation. Right now: step one.' },
    { caption: 'Step 1 · Your Purchase Order', say: 'Step one tells you exactly what to do. Download the purchase order we prepared. Sign and stamp it. Then upload the signed copy, with your own P O number.', do: async (d) => { await d.point('.action-howto', 2200); await d.point(d.page.getByRole('link', { name: /Download Purchase Order/ }), 1500); } },
    { caption: 'Upload the signed copy', say: 'Our buyer signed it. They type their P O number, choose the signed file, a P D F or a photo, and click: Upload Signed P O.', do: async (d) => {
      await d.type('input[name="buyers_po_ref"]', 'NSS-PO-2611-01');
      await d.upload(d.page.locator('input[name="document"]'), demo.sampleFile('signed-po-sample.pdf', 'SAMPLE SIGNED PURCHASE ORDER - TUTORIAL DEMO'));
      await d.clickNav(d.page.getByRole('button', { name: 'Upload Signed PO' }));
    } },
    { caption: 'Nothing to do right now', say: 'Thank you: nothing to do right now. We have your signed P O, and its number is now on your order. Your Export Executive is told at once. And notice the line at the bottom: questions? Simply reply to the e-mail this link came with.', do: async (d) => { await d.point('.action-wait h2', 1500); await d.point(d.page.getByText('Questions?', { exact: false }), 1500); } },

    // ---------- 4. PI details form ----------
    { card: 'chapter', title: 'Confirm your details', subtitle: 'The Proforma Invoice Details Form.', say: 'Part four. Confirm your details.', do: async () => { st.piLink = B.moves.piFormLink(demo, st.order); } },
    { card: 'email', to: B.BUYER_EMAIL, subject: 'NexaCrest — confirm your PI-stage details',
      lines: ['Hello,', 'Thank you for accepting our Quotation.', 'To issue your Proforma Invoice, please confirm your shipping/consignee details at the link below.', 'This link works for 30 days.'],
      link: 'Proforma Invoice Details Form (secure — opens without a login)',
      say: 'Before we issue the Proforma Invoice, we ask you to check the details that will be printed on it, and later on the shipping documents. Another short link.' },
    { as: 'buyer', do: async (d) => { await d.goto(`/pi-details/${st.piLink}`); await d.point('h1', 1200); await d.point('input[name="company_legal_name"]', 1500); }, caption: 'Already filled in for you', say: 'The good news: it is already filled in, with everything you gave us on the quotation form. You only check it, and add what is missing.' },
    { caption: 'Consignee and notify party', say: 'Check the consignee, and the notify party: the company the shipping line tells when the goods arrive, often your customs broker. Our buyer handles that themselves, so: same as consignee.', do: async (d) => { await d.scrollTo('#notify_party_same_as_consignee'); await d.point(d.page.locator('label').filter({ has: d.page.locator('#notify_party_same_as_consignee') }), 1800); } },
    { caption: 'Shipping details', say: 'Then the shipping details: your port of discharge, the country of destination, and the Incoterm we agreed. All carried over from your quotation form.', do: async (d) => {
      await d.scrollTo('input[name="port_of_discharge_text"]');
      await d.point('input[name="port_of_discharge_text"]', 1200);
      await d.point('#incoterm_select', 1200);
    } },
    { caption: 'Payment terms and your acceptance', say: 'Section five: the payment terms, and your acceptance of the quotation, with its number and date, already written for you. Add your own P O number, the one you wrote on the signed purchase order. And if your customs need anything special on the documents, say so here.', do: async (d) => {
      await d.scrollTo('textarea[name="payment_terms_confirmation"]');
      await d.point('textarea[name="payment_terms_confirmation"]', 1500);
      await d.point('textarea[name="quotation_acceptance_reference"]', 1500);
      await d.type('input[name="buyer_po_ref"]', 'NSS-PO-2611-01');
    } },
    { caption: 'Confirm, and submit', say: 'Section six. Please read this carefully: once you submit, these details are locked. They will be printed on your invoices and shipping documents, so check them well. Tick the box to confirm, and: Submit P I Details.', do: async (d) => {
      await d.scrollTo('input[name="confirm_lock"]');
      await d.point(d.page.locator('fieldset').filter({ has: d.page.locator('input[name="confirm_lock"]') }).locator('.intake-note'), 1800);
      await d.check('input[name="confirm_lock"]');
      await d.clickNav(d.page.getByRole('button', { name: 'Submit PI Details' }));
      await d.point('h1', 1500);
    } },
    { as: 'exec', goto: '/pi-intake-review', caption: 'Our side: PI Intake Review', say: 'On our side, your confirmation appears in the P I Intake Review. The Export Executive clicks: Accept and Apply. Your confirmed details are now on your order, ready for the Proforma Invoice.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: B.BUYER.company }).first(), 1800); await d.clickNav(d.page.getByRole('button', { name: 'Accept & Apply' }).first()); await d.point('.alert', 1200); } },

    // ---------- 5. The Proforma Invoice ----------
    { card: 'chapter', title: 'Your Proforma Invoice', subtitle: 'What to pay now, and how.', say: 'Part five. Your Proforma Invoice.', do: async () => { st.pi = B.moves.proforma(demo, st.order); } },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Proforma Invoice ${st.pi} — ${B.BUYER.company}`; },
      get lines() { return ['Dear Purchasing Manager,', `Please find attached Proforma Invoice ${st.pi}, issued against Quotation ${st.qt}.`, 'This Proforma Invoice is valid until the date shown on it. Please arrange the advance payment shown in the box ADVANCE PAYABLE NOW.']; },
      get attach() { return [`${st.pi.replace(/\//g, '-')}.pdf`]; },
      say: 'The Proforma Invoice arrives by e-mail, checked and approved like every document. Look for the amber box on it: Advance payable now. It shows the exact amount: forty percent of sixteen thousand two hundred dollars, that is six thousand four hundred and eighty dollars, with our bank details.' },
    { as: 'buyer', do: async (d) => { await d.goto(`/buyer-action/${st.buyerLink}`); await d.point('.action-progress', 1500); }, caption: 'Your link moves on', say: 'And your Buyer Action Link has moved on by itself. Step one has a tick, and step two, the advance payment, is now current.' },
    { caption: 'Download the Proforma Invoice', say: 'From here you can download the Proforma Invoice again at any time. And once you have paid, you can tell us right here: your bank transfer reference, the amount, and the date. How to pay, and what happens next, is our next video.', do: async (d) => { await d.point(d.page.getByRole('link', { name: /Proforma/ }).first(), 1500); await d.point('input[name="transaction_ref"]', 1800); } },

    { card: 'list', kicker: 'Recap', title: 'From enquiry to Proforma Invoice', items: [
      'Quotation Details Form: your company and shipping details, two minutes.',
      'Quotation by e-mail: reply with questions, or that you accept.',
      'Buyer Action Link: download our PO, upload the signed copy with your PO number.',
      'PI Details Form: check the pre-filled details, add the notify party.',
      'Proforma Invoice: the amber box shows the advance to pay now.',
    ], next: 'D02 · Paying and your login', say: 'Let us recap. You filled in the quotation details form. You received our quotation by e-mail, and replied that you accept. On your Buyer Action Link you downloaded our purchase order, and uploaded the signed copy with your P O number. On the P I details form you checked your details. And you received the Proforma Invoice, with the advance to pay now. No login, no password, at any step. In the next video: paying the advance, letters of credit, and your own login to the client portal. See you there!' },
  ],
};
