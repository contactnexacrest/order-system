'use strict';

const emailService = require('../../src/services/emailService');

/**
 * QA Round 5, R5-17: a blank SMTP_ENCRYPTION means TLS (STARTTLS required)
 * on both stacks — PHP's Env::get('SMTP_ENCRYPTION', 'tls') already did;
 * Node used to send in clear when the server offered no TLS.
 */
describe('SMTP_ENCRYPTION setting (QA Round 5, R5-17)', () => {
  const original = process.env.SMTP_ENCRYPTION;
  afterEach(() => {
    if (original === undefined) delete process.env.SMTP_ENCRYPTION;
    else process.env.SMTP_ENCRYPTION = original;
  });

  it.each([
    ['', { secure: false, requireTLS: true }],
    ['tls', { secure: false, requireTLS: true }],
    ['TLS', { secure: false, requireTLS: true }],
    ['ssl', { secure: true, requireTLS: false }],
  ])('SMTP_ENCRYPTION=%p', (value, expected) => {
    process.env.SMTP_ENCRYPTION = value;
    expect(emailService.smtpSecurityOptions()).toEqual(expected);
  });
});
