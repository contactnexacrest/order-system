'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const codes = require('../../src/services/associateCodeService');

/**
 * Port of QaFinalRunTest.php — final QA run (6 Nov 2026): a name part ran out of personal codes after
 * 31 (one random letter); the code now grows to 2 or 3 random letters.
 */
describe('QA final run — personal code never runs out for a name', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('still finds a code when every one-letter code for the name is taken', async () => {
    const [res] = await db.pool.query("INSERT INTO associates (name, status, commission_mode) VALUES (?, 'active', 'ladder')", [`QA Final Assoc ${crypto.randomBytes(3).toString('hex')}`]);
    const associate = res.insertId;
    const part = `QF${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
    for (const r of codes.ALPHABET) {
      const body = `NXA-${part}-${r}`;
      await db.pool.query('INSERT INTO associate_codes (code, associate_id) VALUES (?, ?)', [body + codes.checkChar(body), associate]);
    }
    const code = await codes.generate(part);
    expect(code).toMatch(new RegExp(`^NXA-${part}-[${codes.ALPHABET}]{2,3}[${codes.ALPHABET}]$`));
    expect(codes.isValid(code)).toBe(true);
    expect(codes.isValid(`NXA-${part}-2${codes.checkChar(`NXA-${part}-2`)}`)).toBe(true);
    expect(codes.isValid(`NXA-${part}-2345${codes.checkChar(`NXA-${part}-2345`)}`)).toBe(false);
  });
});
