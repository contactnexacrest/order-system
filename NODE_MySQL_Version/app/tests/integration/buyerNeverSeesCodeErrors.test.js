'use strict';

// Owner decision 4 Nov 2026 (A6): a buyer never sees an error caused by an
// associate code or link. Whatever is wrong with it, the buyer carries on as
// a direct buyer, and staff see on the intake review what was given and why
// it was not applied. PHP twin: BuyerNeverSeesCodeErrorsTest.php.
const crypto = require('crypto');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const associateInvitationRepository = require('../../src/repositories/associateInvitationRepository');
const associateCodeService = require('../../src/services/associateCodeService');
const associateIntakeService = require('../../src/services/associateIntakeService');
const userRepository = require('../../src/repositories/userRepository');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');

describe('A buyer never sees an associate-code error (A6)', () => {
  let admin;
  const tag = crypto.randomBytes(3).toString('hex').toUpperCase();
  beforeAll(async () => { admin = Number((await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id); });
  afterAll(async () => { await db.pool.end(); });

  async function associate(fields = {}) {
    const id = await associateRepository.create({
      name: `A6 Assoc ${tag}${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `a6-${crypto.randomBytes(4).toString('hex')}@assoc.test`, status: 'active', ...fields,
    }, admin);
    await associateCodeService.issue(id, `Q${tag}`);
    return associateRepository.find(id);
  }

  it('every bad code or link is ignored silently, with a reason for staff', async () => {
    const a = await associate();
    const cases = {};
    const pc = String(a.personal_code);
    cases.mistyped = [await associateIntakeService.introducer(pc.slice(0, -1) + (pc.slice(-1) === 'A' ? 'B' : 'A'), null), 'the code is not valid (mistyped?)'];
    cases.unknown = [await associateIntakeService.introducer(await associateCodeService.generate(`NOBODY${tag}`), null), 'no associate has this code'];
    // D-254 (owner, 4 Nov 2026): an old code still brings the buyer to the associate…
    await associateCodeService.issue(Number(a.id), `N${tag}`);
    const old = await associateIntakeService.introducer(pc, null);
    expect([Number(old.associate.id), old.note]).toEqual([Number(a.id), null]);
    // …unless staff blocked it (e.g. after a leak).
    const mid = (await associateRepository.find(Number(a.id))).personal_code;
    await associateCodeService.issue(Number(a.id), `B${tag}`, true, 'The code was posted on a public forum');
    cases['blocked old'] = [await associateIntakeService.introducer(mid, null), `it is an old code of associate ${a.name} that NexaCrest blocked`];
    const s = await associate({ status: 'suspended' });
    cases.suspended = [await associateIntakeService.introducer(String(s.personal_code), null), 'the associate is suspended'];
    const e = await associate({ status: 'ended' });
    cases.ended = [await associateIntakeService.introducer(String(e.personal_code), null), 'the associate is ended'];
    cases['bad link'] = [await associateIntakeService.introducer(null, `not-a-real-token-${tag}`), 'the link is not valid'];
    const inv = await associateInvitationRepository.createLink(Number(a.id), 'A6 Link Buyer', 'Netherlands');
    await db.execute('UPDATE associate_invitations SET expires_at = NOW() - INTERVAL 1 DAY WHERE id = :id', { id: inv.id });
    cases['expired link'] = [await associateIntakeService.introducer(null, inv.token), 'the link expired or was already used'];

    for (const [name, [r, reason]] of Object.entries(cases)) {
      expect([name, r.associate, r.error]).toEqual([name, null, null]);
      expect(String(r.note)).toContain(`given but not applied: ${reason}.`);
    }
    const ok = await associateIntakeService.introducer(String((await associateRepository.find(Number(a.id))).personal_code), null);
    expect([Number(ok.associate.id), ok.note, ok.error]).toEqual([Number(a.id), null, null]);
  });

  it('staff see the note on the intake review', async () => {
    const r = await db.execute(`INSERT INTO client_intake_submissions (company_legal_name, billing_address, contact_person, email, country_of_destination, status, associate_note)
      VALUES ('A6 Review Buyer', '1 Harbour Road', 'Purchase Head', 'buyer@a6.example', 'Netherlands', 'pending', :n)`,
    { n: 'Associate code NXA-OLD-7K given but not applied: no associate has this code.' });
    const info = await associateIntakeService.reviewInfo(await associateIntakeService.submission(r.insertId));
    expect(info.associate).toBeNull();
    expect(info.flags).toContain('Associate code NXA-OLD-7K given but not applied: no associate has this code.');
  });
});
