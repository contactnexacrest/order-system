'use strict';
// C03 — First steps after login: profile, tax details, KYC documents and bank account,
// and how NexaCrest checks them before the account becomes active.
// Updated 13 Nov 2026 (docs audit C; not recorded, recording on hold): D-290 ID proof and Address proof are
// separate uploads; D-293 bank flow — the associate confirms new details on /associate/bank/confirm (last 4
// digits + e-mailed code + "These are my bank details"), then ONE staff member who did not enter them
// verifies with at least two "how I checked" ticks (setting default "one person"); the Finance Manager's
// second check is gone.
const { nav, associateSetup, PLAN_A } = require('../_common');

const EMAIL = 'associate.invited@email.com';

module.exports = {
  code: 'C03',
  file: 'C03-Associate-Profile-Bank-KYC.mp4',
  group: 'Group C · Associates',
  title: 'Your profile, documents and bank account',
  subtitle: 'Complete your profile, upload your KYC documents and add your bank: then NexaCrest activates you.',
  minutes: '11',

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'starter', status: 'invited' }, { withMd: true });
    demo.demoStaff('fm');
    s.actors.fm = { label: 'Finance Manager', sub: 'NexaCrest staff · finance.manager@email.com', initials: 'FM', color: '#2E6B5E', cookies: await demo.staffCookies('fm') };
    s.files = { pan: demo.sampleFile('pan-card-sample.pdf', 'SAMPLE PAN CARD - TUTORIAL DEMO'), aadhaar: demo.sampleFile('aadhaar-sample.pdf', 'SAMPLE ID - TUTORIAL DEMO'),
      cheque: demo.sampleFile('cancelled-cheque-sample.pdf', 'SAMPLE CANCELLED CHEQUE - TUTORIAL DEMO'), agreement: demo.sampleFile('agreement-sample.pdf', 'SAMPLE SIGNED AGREEMENT - TUTORIAL DEMO'),
      address: demo.sampleFile('address-proof-sample.pdf', 'SAMPLE ADDRESS PROOF - TUTORIAL DEMO') };
    return s;
  },

  steps: ({ a = PLAN_A, files = {} } = {}) => {
    const uploadDoc = async (d, kind, ref, file) => {
      await d.select(d.page.locator('#kyc select[name="kind"]'), kind);
      if (ref) await d.type(d.page.locator('#kyc input[name="reference"]'), ref);
      await d.upload(d.page.locator('#kyc input[name="document_file"]'), file);
      await d.clickNav(d.page.locator('#kyc').getByRole('button', { name: 'Upload' }));
    };
    return [
      { card: 'title', say: 'Hi again! You have just logged in for the first time, and your account says Invited. In this video, we make it Active. Profile, documents and bank account, one by one. And then we watch NexaCrest check everything, from the other side.' },

      { card: 'chapter', title: 'Your profile', subtitle: 'Contact and tax details.', say: 'Part one. Your profile.' },
      { as: 'associate', goto: '/associate', caption: 'The reminder on Home', say: 'Your home page reminds you what is still needed. Click the link in the yellow note, and it takes you straight there.', do: async (d) => {
        await d.point(d.page.locator('.alert-warning').nth(1), 1200);
        await d.clickNav(d.page.locator('.alert-warning a').first());
      } },
      { caption: 'My profile', say: 'At the top of your profile: your name, your login e-mail, your agreement dates, and a plain-words summary of how your commission works. To change your e-mail, just ask NexaCrest.', do: async (d) => { await d.scrollTo('h1'); await d.point('table.kv', 1800); } },
      { caption: 'Contact and tax details', say: 'Now the contact and tax details. Your phone number, and, because you are based in India, your PAN. The format is checked as you type: five letters, four digits, one letter.', do: async (d) => {
        await d.scrollTo(d.page.getByRole('heading', { name: 'Contact and tax details' }));
        await d.type(d.page.locator('input[name="pan"]'), 'ABCDE1234F', { delay: 70 });
      } },
      { caption: 'GSTIN only if registered', say: 'If you are registered for GST, add your GSTIN. Then NexaCrest adds GST on top of your commission, once your tax invoice is on file. Not registered? Simply leave it empty. Then your address, and Save.', do: async (d) => {
        await d.ring(d.page.locator('input[name="gstin"]')); await d.wait(800);
        await d.type(d.page.locator('textarea[name="address"]'), 'Demo address line, Jaipur 302001', { delay: 35 });
        await d.clickNav(d.page.locator('form[action="/associate/profile"]').getByRole('button', { name: 'Save' }));
      } },

      { card: 'chapter', title: 'Your KYC documents', subtitle: 'Each one is checked by NexaCrest.', say: 'Part two. Your documents, also called KYC.' },
      { caption: 'The checklist', say: 'This checklist shows exactly what is needed. For an associate in India: PAN card, an ID proof, which is the Aadhaar card or an ID document, a separate address proof, bank proof, and the signed agreement. If you have a GSTIN, the GST certificate too. Associates abroad see a different list: an ID document, an address proof, bank proof, the agreement, and the tax residency papers.', do: async (d) => { await d.scrollTo('#kyc'); await d.ring(d.page.locator('#kyc table.list').first()); await d.wait(1500); } },
      { caption: 'Upload: PAN card', say: 'Uploading is three quick steps. Choose the document, type its number, and pick the file. The number is checked against the right format for that kind of document.', do: async (d) => { await uploadDoc(d, 'pan', 'ABCDE1234F', files.pan); } },
      { caption: 'Aadhaar: last 4 digits only', say: 'For the Aadhaar card, your I D proof, you type only the last four digits. NexaCrest never keeps the full number. Good for your privacy. With a passport or another I D document instead, you first choose its type, then type its number.', do: async (d) => { await d.scrollTo('#kyc form'); await uploadDoc(d, 'aadhaar', '1234', files.aadhaar); } },
      { caption: 'Address proof: a separate upload', say: 'The address proof is a separate item, checked on its own. If one document shows both, for example a passport with your current address, you upload it once as I D, and once as address proof.', do: async (d) => { await d.scrollTo('#kyc form'); await uploadDoc(d, 'address', '', files.address); } },
      { caption: 'Bank proof', say: 'Next, your bank proof: a cancelled cheque or a bank statement.', do: async (d) => { await d.scrollTo('#kyc form'); await uploadDoc(d, 'bank_proof', '', files.cheque); } },
      { caption: 'Signed agreement', say: 'And your signed agreement. Every upload appears in the list below, marked Being checked, and the checklist above follows along.', do: async (d) => { await d.scrollTo('#kyc form'); await uploadDoc(d, 'agreement', '', files.agreement); } },

      { card: 'chapter', title: 'Your bank account', subtitle: 'Where your commission goes.', say: 'Part three. Your bank account.' },
      { caption: 'Add my bank details', say: 'Under Bank details, open Add my bank details. A small note explains the rule: you confirm every bank change yourself with a code we e-mail you, then someone at NexaCrest checks it, and a new account is only used a few days after it is verified. That protects you against fraud.', do: async (d) => {
        await d.scrollTo(d.page.getByRole('heading', { name: 'Bank details' }));
        await d.click(d.page.locator('summary', { hasText: 'bank details' }));
        await d.ring(d.page.locator('details p.muted').first()); await d.wait(1500);
      } },
      { caption: 'Holder and account number', say: 'Type the account holder name, as on your PAN, and the account number.', do: async (d) => {
        await d.type(d.page.locator('input[name="holder_name"]'), 'Demo Associate');
        await d.type(d.page.locator('input[name="account_number"]'), '50100234567890', { delay: 70 });
      } },
      { caption: 'Typed twice, compared at once', say: 'You type the account number a second time. Watch: if one digit is wrong, the field tells you right away. One wrong digit could send your money to a stranger, so this check really matters.', do: async (d) => {
        await d.type(d.page.locator('input[name="account_number_confirm"]'), '50100234567809', { delay: 70 });
        await d.page.locator('input[name="ifsc"]').focus(); await d.wait(900);
        await d.ring(d.page.locator('input[name="account_number_confirm"]')); await d.wait(1200);
        await d.type(d.page.locator('input[name="account_number_confirm"]'), '50100234567890', { delay: 60 });
      } },
      { caption: 'IFSC, bank and proof', say: 'Then the IFSC code, the bank name, and a cancelled cheque or a bank letter as proof. Press: Send, then confirm with the e-mailed code.', do: async (d) => {
        await d.type(d.page.locator('input[name="ifsc"]'), 'HDFC0001234', { delay: 60 });
        await d.type(d.page.locator('input[name="bank_name"]'), 'HDFC Bank');
        await d.upload(d.page.locator('input[name="proof_file"]'), files.cheque);
        await d.clickNav(d.page.getByRole('button', { name: 'Send — then confirm with the e-mailed code' }));
      } },
      { caption: 'Please confirm your new bank details', say: 'The app takes you straight to: please confirm your new bank details. On the left, the account you are paid to now; on the right, the new one, masked. Only the last digits are shown, even to you. Press E-mail me a code: a six digit code goes to your registered e-mail, valid for ten minutes.', do: async (d) => {
        await d.point('h1', 1200); await d.ring(d.page.locator('table[data-bank-compare]')); await d.wait(1800);
        await d.clickNav(d.page.getByRole('button', { name: /E-mail me a code|Send a new code/ }));
      } },
      { caption: 'Last 4 digits, the code, and confirm', say: 'Type the last four digits of the new account number, and the code from the e-mail. Tick: these are my bank details. And press Confirm new bank details. If you never asked for this change, press This is not me instead: the change is cancelled and NexaCrest is told at once.', do: async (d) => {
        await d.type(d.page.locator('input[name="last4"]'), '7890', { delay: 120 });
        const code = await d.devCode();
        await d.type(d.page.locator('input[name="code"]'), code || '', { delay: 140 });
        await d.check('input[name="mine"]');
        await d.point(d.page.getByRole('button', { name: /This is not me/ }), 1200);
        await d.clickNav(d.page.getByRole('button', { name: 'Confirm new bank details' }));
      } },
      { caption: 'Now NexaCrest checks it', say: 'Confirmed. Back on your profile, the new account waits for NexaCrest to check it.', do: async (d) => { await d.point('.alert', 1500); await d.scrollTo(d.page.getByRole('heading', { name: 'Bank details' })); await d.ring(d.page.locator('.section').filter({ has: d.page.getByRole('heading', { name: 'Bank details' }) }).locator('table.list').first()); await d.wait(1500); } },

      { card: 'chapter', title: 'Meanwhile, at NexaCrest', subtitle: 'Documents accepted, bank verified, account activated.', say: 'Part four. Let us switch to NexaCrest and watch the checks.' },
      { as: 'md', goto: `/associates/${a.id}#documents`, caption: 'Staff: KYC and documents', say: 'This is your record, on the staff side. Every document you uploaded is here. Staff open each one, and accept it, or reject it with a reason that you see in your portal.', do: async (d) => { await d.scrollTo('#documents'); await d.wait(800); } },
      { caption: 'Accept each document', say: 'Here the Managing Director accepts them, one after another.', do: async (d) => {
        for (let i = 0; i < 6; i++) {
          const form = d.page.locator('#documents tr', { hasText: 'Not checked yet' }).locator('form[action*="/review"]').first();
          if (!(await form.count())) break;
          await d.scrollTo(form);
          await d.select(form.locator('select[name="decision"]'), 'accept');
          await d.clickNav(form.getByRole('button', { name: 'Save' }));
          await d.page.locator('#documents tr', { hasText: 'Not checked yet' }).locator('form[action*="/review"]').first().waitFor({ state: 'attached', timeout: 3000 }).catch(() => {});
        }
      } },
      { caption: 'Bank: how was it checked?', say: 'Now the bank account, which you have already confirmed. Someone at NexaCrest who did not enter the details verifies it, and ticks at least two ways they checked it: the bank proof matches, a call to your registered phone, or a small test payment received in the right name. Here the Managing Director ticks the bank proof and the call, and presses Verify.', do: async (d) => {
        await d.scrollTo('#bank');
        const f = d.page.locator('#bank form[data-bank-verify-form]').first();
        await d.check(f.locator('input[name="check_bank_proof"]'));
        await d.check(f.locator('input[name="check_phone"]'));
        await d.type(f.locator('input[name="verify_note"]'), 'Called the associate, last 4 digits confirmed', { delay: 25 });
        await d.clickNav(f.getByRole('button', { name: 'Verify' }));
      } },
      { caption: 'Verified, usable after a short wait', say: 'Verified! One person alone never decides where your money goes: you confirmed it, and someone else checked it. The account becomes usable after a short waiting period. Until then, any payment would still go to a previously verified account.', do: async (d) => { await d.scrollTo('#bank'); await d.ring(d.page.locator('#bank table.list')); await d.wait(1500); } },
      { as: 'md', goto: `/associates/${a.id}#profile`, caption: 'Change status: Active', say: 'Finally, the Managing Director changes your status from Invited to Active, with a short reason for the record.', do: async (d) => {
        await d.scrollTo('#profile form[action*="/status"]');
        await d.select(d.page.locator('#profile select[name="status"]'), 'active');
        await d.type(d.page.locator('#profile input[name="reason"]'), 'Documents and bank checked, welcome aboard', { delay: 25 });
        await d.clickNav(d.page.locator('#profile').getByRole('button', { name: 'Change status' }));
      } },

      { card: 'chapter', title: 'You are active!', say: 'Part five. Back to you.' },
      { as: 'associate', goto: '/associate/profile#kyc', caption: 'All accepted', say: 'Back in your portal, the checklist shows your documents as accepted. If one was rejected, you would see the reason here, and simply upload it again.', do: async (d) => { await d.scrollTo('#kyc'); await d.ring(d.page.locator('#kyc table.list').first()); await d.wait(1500); } },
      { goto: '/associate', caption: 'Active', say: 'And on your home page, the Invited warning is gone. Your account is active, and you can start inviting clients. That is exactly what we do in video C03.', do: async (d) => { await d.point('h1', 1500); } },

      { card: 'list', kicker: 'Recap', title: 'From Invited to Active', items: [
        'Profile: phone, PAN (India), GSTIN if registered, address.',
        'KYC: upload each document with its number; Aadhaar last 4 digits only.',
        'Bank: typed twice, proof attached, you confirm with a code, NexaCrest verifies.',
        'NexaCrest accepts the documents and activates your account.',
      ], next: 'Video C03 · The Starter level and your first client', say: 'To recap. Fill in your profile. Upload your KYC documents, with the I D proof and the address proof as separate items. Add your bank account, typed twice, with proof, and confirm it with the e-mailed code. NexaCrest checks each document, verifies the bank, and your account becomes active. Commission can only be paid once all of this is accepted, so do it early. Next up, video C03: a tour of the Starter level, and your first client. See you there!' },
    ];
  },
};
