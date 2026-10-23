'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const emailService = require('../../src/services/emailService');

/**
 * Port of EmailDeliveryIdentityTest.php. External QA report L-43/L-46:
 * customer emails (QT/PI/OC/etc.) went out from a no-reply address with no
 * Reply-To, so a buyer reply was lost; and nodemailer's Message-ID
 * defaulted to the local machine's own hostname instead of the company's
 * own mail domain. Both are applied via identityOptions() (passed into
 * every real sendMail() call) through the two pure helpers under test
 * here — a configured SMTP transport isn't available in this environment,
 * matching every other emailService test in this suite.
 */
describe('emailService delivery identity (External QA report L-43/L-46)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  afterEach(async () => {
    await companySettingsRepository.set('reply_to_email', '');
  });

  it('replyToAddress uses the dedicated setting when configured', async () => {
    await companySettingsRepository.set('reply_to_email', 'sales@nexacrestinternational.test');

    expect(await emailService.replyToAddress()).toBe('sales@nexacrestinternational.test');
  });

  it('replyToAddress falls back to the company contact email when blank', async () => {
    await companySettingsRepository.set('reply_to_email', '');
    const companyEmail = (await companySettingsRepository.get('email')) || '';
    expect(companyEmail).not.toBe('');

    expect(await emailService.replyToAddress()).toBe(companyEmail);
  });

  /**
   * External QA report TI-09: these two tests used to rely on whatever
   * SMTP_FROM_ADDRESS happened to be in the developer's own .env (the
   * comment claiming ".env pins it" wasn't wrong about the mechanism,
   * but it meant the test's outcome depended on a value the test itself
   * never controlled). env.get() reads process.env directly, so pinning
   * it here guarantees the assertion regardless of the real .env.
   */
  describe('with SMTP_FROM_ADDRESS pinned', () => {
    let original;

    beforeEach(() => {
      original = process.env.SMTP_FROM_ADDRESS;
      process.env.SMTP_FROM_ADDRESS = 'dev@nexacrest.test';
    });

    afterEach(() => {
      if (original === undefined) {
        delete process.env.SMTP_FROM_ADDRESS;
      } else {
        process.env.SMTP_FROM_ADDRESS = original;
      }
    });

    it('mailDomain is derived from the from-address domain, not the local machine hostname', () => {
      expect(emailService.mailDomain()).toBe('nexacrest.test');
    });

    it('freshMessageId uses the from-address domain, never the local machine hostname', () => {
      expect(emailService.freshMessageId()).toMatch(/^<[0-9a-f]+@nexacrest\.test>$/);
    });
  });
});
