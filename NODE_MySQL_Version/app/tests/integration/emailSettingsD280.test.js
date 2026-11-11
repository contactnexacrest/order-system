'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const notificationRepository = require('../../src/repositories/notificationRepository');
const associateRepository = require('../../src/repositories/associateRepository');
const emailPolicy = require('../../src/services/emailPolicy');
const mailSenderService = require('../../src/services/mailSenderService');
const emailDispatchService = require('../../src/services/emailDispatchService');
const staffRouting = require('../../src/services/staffRouting');
const associateService = require('../../src/services/associateService');
const associatePortalService = require('../../src/services/associatePortalService');
const twoFactorService = require('../../src/services/twoFactorService');
const emailSettingsController = require('../../src/controllers/emailSettingsController');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of EmailSettingsD280Test.php — owner decisions D-280 / D-281 / D-282
 * (11 Nov 2026, points 1, 2, 3, 5, 22): Admin › E-mail settings, testing mode,
 * truthful "e-mailed" wording with a copyable link, department routing of staff
 * e-mails, company-level compliance owners, the associate's forgot-password and
 * the invite e-mail with the permanent login page.
 */
describe('D-280 / D-281 / D-282 e-mail settings, routing and portal access', () => {
  let testingBefore;

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-d280-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, role_id: role.id }
    );
    return r.insertId;
  }

  /** A user with the role and a current membership of that role's designation. */
  async function member(roleName) {
    const userId = await createTestUser(roleName);
    await db.execute(
      `INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
       SELECT :u, dd.id, CURDATE(), 1 FROM department_designations dd JOIN roles r ON r.id = dd.role_id WHERE r.name = :r LIMIT 1`,
      { u: userId, r: roleName }
    );
    return userId;
  }

  async function newAssociate() {
    const id = await associateRepository.create({ name: `D280 Assoc ${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `d280-${crypto.randomBytes(4).toString('hex')}@assoc.test`, status: 'active', portal_enabled: 1 }, await createTestUser('Admin'));
    await db.execute('UPDATE associates SET portal_enabled = 1 WHERE id = :id', { id });
    return id;
  }

  async function currentSwitches() {
    const on = {};
    const m = await emailPolicy.matrix();
    for (const [kind, cells] of Object.entries(m)) {
      for (const [aud, cell] of Object.entries(cells)) {
        if (cell.tier !== 'must' && cell.on) on[`${kind}|${aud}`] = '1';
      }
    }
    return on;
  }

  const value = async (sql) => {
    const row = await db.queryOne(sql);
    return row ? Object.values(row)[0] : null;
  };

  beforeAll(async () => {
    testingBefore = await companySettingsRepository.get('email_testing_mode');
  });

  beforeEach(async () => {
    await companySettingsRepository.set('email_testing_mode', '0');
    emailPolicy.resetCache();
  });

  afterEach(async () => {
    for (const [k, a] of [['staff_counterparty', 'staff'], ['buyer_documents', 'associate'], ['buyer_links', 'buyer']]) {
      await emailPolicy.setSwitchForTest(k, a, emailPolicy.tier(k, a) === 'should');
    }
    await db.execute('UPDATE compliance_alert_owners SET responsible_user_id = NULL, backup_user_id = NULL');
  });

  afterAll(async () => {
    await companySettingsRepository.set('email_testing_mode', String(testingBefore ?? '0'));
    await db.pool.end();
  });

  // ---- the catalogue and the seed ----

  it('the MUST e-mails are the ones the owner named; only the associate copy of documents is OPTIONAL', () => {
    const must = {
      login_code: ['staff', 'associate', 'buyer'], set_password_invite: ['associate', 'buyer'], forgot_password: ['staff', 'associate', 'buyer'],
      bank_details_changed: ['associate'], backup_code_alert: ['staff'], quote_verification_code: ['buyer'],
      // D-293 / D-303 (owner 12 Nov 2026): the bank-details confirmations are listed as MUST rows too.
      bank_confirm_code: ['associate'], vendor_bank_confirm: ['supplier'],
    };
    for (const [kind, audiences] of Object.entries(must)) {
      for (const a of audiences) expect(emailPolicy.isMust(kind, a)).toBe(true);
    }
    expect(emailPolicy.KINDS.set_password_invite.access_critical).toBe(true);
    const optional = [];
    for (const [kind, def] of Object.entries(emailPolicy.KINDS)) {
      for (const [a, tier] of Object.entries(def.audiences)) if (tier === 'optional') optional.push(`${kind}|${a}`);
    }
    expect(optional).toEqual(['buyer_documents|associate']);
  });

  it('the bank-details confirmation e-mails are listed as MUST rows and sent under that kind', async () => {
    const admin = await createTestUser('Admin');
    let locals = null;
    await emailSettingsController.index({ session: {}, user: { id: admin } }, { renderView(view, l) { locals = l; } });
    const groupA = locals.groups.find((g) => g.key === 'A');
    for (const kind of ['bank_confirm_code', 'vendor_bank_confirm']) {
      const row = groupA.kinds.find((k) => k.kind === kind);
      expect(row.tierLabel).toBe('must');
    }
    expect(await emailPolicy.switchOn('bank_confirm_code', 'associate')).toBe(true);
    expect(await emailPolicy.switchOn('vendor_bank_confirm', 'supplier')).toBe(true);
    const fs = require('fs');
    const path = require('path');
    const src = (f) => fs.readFileSync(path.join(__dirname, '../../src/services', f), 'utf8');
    expect(src('associateBankConfirmation.js')).toContain("{ isSecurityEmail: true, kind: 'bank_confirm_code', audience: 'associate' }");
    expect(src('vendorBankChangeService.js')).toContain("{ isSecurityEmail: true, kind: 'vendor_bank_confirm', audience: 'supplier' }");
  });

  it('the seed starts with SHOULD on, OPTIONAL off and testing mode off; the old master switch is gone', async () => {
    expect(Number(await value("SELECT is_enabled FROM email_settings WHERE email_kind = 'staff_counterparty' AND audience = 'staff'"))).toBe(1);
    expect(Number(await value("SELECT is_enabled FROM email_settings WHERE email_kind = 'buyer_documents' AND audience = 'associate'"))).toBe(0);
    expect(await value("SELECT 1 FROM email_settings WHERE email_kind = 'login_code'")).toBeNull();
    expect(await value("SELECT 1 FROM company_settings WHERE setting_key = 'mail_sending_enabled'")).toBeNull();
    expect(await value("SELECT 1 FROM company_settings WHERE setting_key = 'email_testing_mode'")).not.toBeNull();
  });

  // ---- testing mode and the switches ----

  it('testing mode holds only buyer and supplier e-mails that are not MUST', async () => {
    await companySettingsRepository.set('email_testing_mode', '1');
    expect(await emailPolicy.holdReason('buyer_links', 'buyer')).toBe('testing mode holds e-mails to buyers and suppliers');
    expect(await emailPolicy.holdReason('supplier_links', 'supplier')).toBe('testing mode holds e-mails to buyers and suppliers');
    expect(await emailPolicy.holdReason('staff_counterparty', 'staff')).toBeNull();
    expect(await emailPolicy.holdReason('associate_commission', 'associate')).toBeNull();
    expect(await emailPolicy.holdReason('set_password_invite', 'buyer')).toBeNull();
    expect(await emailPolicy.holdReason('quote_verification_code', 'buyer')).toBeNull();
    expect(await emailDispatchService.mailSendingEnabled()).toBe(false);
  });

  it('a switched-off e-mail is held with its own reason', async () => {
    await emailPolicy.setSwitchForTest('staff_counterparty', 'staff', false);
    expect(await emailPolicy.holdReason('staff_counterparty', 'staff')).toBe('this e-mail is switched off in Admin › E-mail settings');
    expect(await mailSenderService.send('someone@nexacrest.test', 'S', 'B', [], { kind: 'staff_counterparty', audience: 'staff' })).toBe(false);
    expect(mailSenderService.state.lastOutcome).toBe('held');
  });

  it('the OPTIONAL associate copy is off until switched on; its wording', async () => {
    expect(await emailPolicy.holdReason('buyer_documents', 'associate')).toBe('this e-mail is switched off in Admin › E-mail settings');
    await emailPolicy.setSwitchForTest('buyer_documents', 'associate', true);
    expect(await emailPolicy.holdReason('buyer_documents', 'associate')).toBeNull();
    expect(emailDispatchService.associateCopyBody({ name: 'Agent One' }, { company_legal_name: 'Buyer Co' }, { order_reference: 'NX-1' }, 'Please find attached.'))
      .toBe('Hello Agent One,\n\nFor your information, this is a copy of the e-mail NexaCrest sent to your client Buyer Co (order NX-1).\n\n---\n\nPlease find attached.');
  });

  it('a notification e-mail follows the switch; a MUST alert ignores the personal opt-out', async () => {
    const userId = await createTestUser('Export Executive');
    expect(await notificationRepository.emailNotification(userId, 'Hello', 'buyer_po_uploaded_by_client')).not.toBeNull();
    await emailPolicy.setSwitchForTest('staff_counterparty', 'staff', false);
    expect(await notificationRepository.emailNotification(userId, 'Hello', 'buyer_po_uploaded_by_client')).toBeNull();
    await db.execute('UPDATE users SET email_action_notifications_enabled = 0 WHERE id = :id', { id: userId });
    expect(await notificationRepository.emailNotification(userId, 'Security', 'backup_code_used')).not.toBeNull();
  });

  // ---- truthful wording, with the link to copy ----

  it('the link message never says e-mailed when it was not', async () => {
    expect(await emailDispatchService.linkMailOutcome('PI-stage form link', 'b@x.test', true, 'L'))
      .toBe('PI-stage form link e-mailed to b@x.test. If they did not get it, copy the link and send it yourself.');
    expect(await emailDispatchService.linkMailOutcome('PI-stage form link', null, false, 'L'))
      .toBe('PI-stage form link generated — not e-mailed: no client e-mail on file. Copy the link and send it yourself.');
    await emailPolicy.setSwitchForTest('buyer_links', 'buyer', false);
    expect(await emailDispatchService.linkMailOutcome('PI-stage form link', 'b@x.test', false, 'L'))
      .toBe('PI-stage form link generated — not e-mailed to b@x.test: this e-mail is switched off in Admin › E-mail settings. Copy the link and send it yourself.');
    expect(await emailDispatchService.linkMailOutcome('Supplier Action link', null, false, 'L', 'supplier_links', 'supplier'))
      .toBe('Supplier Action link generated — not e-mailed: no supplier e-mail on file. Copy the link and send it yourself.');
  });

  it('a flash can carry a link to copy', () => {
    const req = { session: {} };
    flash.set(req, 'success', 'Made.', 'https://example.test/x');
    expect(flash.pull(req)).toEqual([{ type: 'success', message: 'Made.', copy_link: 'https://example.test/x' }]);
  });

  // ---- associates: invite, forgot password, login page ----

  it('the invite gives the login page; the screen says truthfully whether it was e-mailed', async () => {
    const body = associateService.inviteBody({ name: 'Agent One', email: 'agent@assoc.test' }, 'https://app.test/associate/set-password/abc');
    expect(body).toContain('Set your password within 72 hours (the link works once):\nhttps://app.test/associate/set-password/abc');
    expect(body).toContain(`After that, log in any time at:\n${associateService.loginUrl()}`);
    expect(associateService.loginUrl()).toMatch(/\/associate\/login$/);
    const id = await newAssociate();
    const out = {};
    const link = await associateService.invitePortal(id, await createTestUser('Admin'), out);
    expect(link).toContain('/associate/set-password/');
    expect(out.sent).toBe(false); // no mail server in the test environment
    expect(associateService.inviteOutcome('x@y.test', false)).toMatch(/^The "set your password" e-mail could NOT be sent to x@y\.test/);
  });

  it("the associate's forgot password gives a one-use link only for a real portal account", async () => {
    const id = await newAssociate();
    const a = await associateRepository.find(id);
    expect(await associatePortalService.sendResetLink({ ip: '127.0.0.1' }, `nobody-${crypto.randomBytes(3).toString('hex')}@assoc.test`)).toBeNull();
    const link = await associatePortalService.sendResetLink({ ip: '127.0.0.1' }, a.email);
    expect(link).not.toBeNull();
    const token = link.slice(link.lastIndexOf('/') + 1);
    expect(Number((await associatePortalService.associateForSetPassword(token)).id)).toBe(id);
    expect(await associatePortalService.setPassword(token, 'Reset1234567')).toBe(true);
    expect(await associatePortalService.associateForSetPassword(token)).toBeNull();
    expect(associatePortalService.resetBody(a, 'L')).toContain('If you did not ask for this, you can ignore this e-mail');
  });

  it('the buyer login code has its own wording; the invite template has the login page', async () => {
    expect(twoFactorService.CLIENT_CODE_BODY).not.toContain('administrator');
    expect(twoFactorService.CLIENT_CODE_BODY).toContain('order portal');
    expect(await value("SELECT 1 FROM email_templates WHERE template_key = 'client_login_code'")).not.toBeNull();
    expect(String(await value("SELECT body FROM email_templates WHERE template_key = 'client_portal_invite'"))).toContain('{login_link}');
  });

  // ---- department routing ----

  it('order events e-mail only the department on the order', async () => {
    const finance = await member('Accounts Executive');
    const sales = await member('Export Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [finance, sales]);
    expect(staffRouting.departmentFor('client_payment_reported')).toBe('FIN');
    const route = await staffRouting.forOrder(orderId, 'FIN');
    expect(route.level).toBe('order');
    expect(route.email).toEqual([finance]);
    expect(route.note).toBeNull();
  });

  it('with nobody from the department the manager is told to assign someone', async () => {
    const manager = await member('Logistics Manager');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [await member('Export Executive')]);
    const route = await staffRouting.forOrder(orderId, 'LOG');
    expect(route.level).toBe('manager');
    expect(route.email).toContain(manager);
    expect(route.note).toBe('Nobody from Logistics is assigned to this order — please assign someone.');
    await notificationRepository.notifyOrderTeam(orderId, 'inspection_failed', 'Inspection failed on the order.');
    expect(await value(`SELECT message FROM notifications WHERE user_id = ${manager} AND related_order_id = ${orderId} ORDER BY id DESC LIMIT 1`))
      .toBe('Inspection failed on the order. Nobody from Logistics is assigned to this order — please assign someone.');
  });

  it('with no manager either the MD is e-mailed; MD / ED / Coordinator are in-app only for routine events', async () => {
    const code = `T${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
    await db.execute('INSERT INTO departments (code, name) VALUES (:c, :n)', { c: code, n: `Test Dept ${code}` });
    const orderId = await createTestOrder(await createTestClient());
    const route = await staffRouting.forOrder(orderId, code);
    expect(route.level).toBe('md');
    expect(route.email).toEqual(await staffRouting.managingDirectors());
    expect(await staffRouting.isRoutineInAppOnly(await createTestUser('Managing Director'))).toBe(true);
    expect(await staffRouting.isRoutineInAppOnly(await createTestUser('Executive Director'))).toBe(true);
    expect(await staffRouting.isRoutineInAppOnly(await createTestUser('Order Coordinator'))).toBe(true);
    expect(await staffRouting.isRoutineInAppOnly(await createTestUser('Export Executive'))).toBe(false);
  });

  it('company alerts go to the responsible person and the backup', async () => {
    const responsible = await createTestUser('Export Executive');
    const backup = await createTestUser('Accounts Executive');
    const admin = await createTestUser('Admin');
    const req = { session: {}, user: { id: admin }, body: { reason: 'Name who looks after the LUT', on: await currentSwitches(), owners: { lut: { responsible: String(responsible), backup: String(backup) } } } };
    await emailSettingsController.save(req, { redirect() {} });
    expect(await staffRouting.companyItem('lut')).toEqual([responsible, backup]);
    expect(Number(await value(`SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'COMPLIANCE_ALERT_OWNER_CHANGED' AND field_name = 'lut' AND user_id = ${admin}`))).toBe(1);
    await db.execute('UPDATE users SET is_active = 0 WHERE id = :id', { id: responsible });
    expect(await staffRouting.companyItem('lut')).toEqual([backup]);
  });

  it('the settings page needs a reason and saves testing mode and switches', async () => {
    const admin = await createTestUser('Admin');
    const bad = { session: {}, user: { id: admin }, body: { reason: '', testing_mode: '1', on: {} } };
    await emailSettingsController.save(bad, { redirect() {} });
    expect(flash.pull(bad)[0].type).toBe('error');
    expect(await emailPolicy.testingMode()).toBe(false);

    const on = await currentSwitches();
    delete on['staff_counterparty|staff'];
    const req = { session: {}, user: { id: admin }, body: { reason: 'Testing the buyer e-mails this week', testing_mode: '1', on } };
    await emailSettingsController.save(req, { redirect() {} });
    const f = flash.pull(req)[0];
    expect(f.type).toBe('success');
    expect(f.message).toContain('Testing mode (hold e-mails to buyers and suppliers): on');
    expect(await emailPolicy.testingMode()).toBe(true);
    expect(await emailPolicy.switchOn('staff_counterparty', 'staff')).toBe(false);
    expect(await emailPolicy.switchOn('login_code', 'staff')).toBe(true);
  });

  it('PHP and Node offer the same catalogue', () => {
    const fs = require('fs');
    const path = require('path');
    const php = fs.readFileSync(path.join(__dirname, '../../../../PHP_MySQL_Version/app/src/Services/EmailPolicy.php'), 'utf8');
    for (const kind of Object.keys(emailPolicy.KINDS)) expect(php).toContain(`'${kind}' => ['group' => '${emailPolicy.KINDS[kind].group}'`);
  });
});
