'use strict';

const settingValueValidator = require('../../src/helpers/settingValueValidator');

/**
 * QA-5 SET-02: pins settingValueValidator's contract for each
 * company_settings.value_type.
 */
describe('settingValueValidator (QA-5 SET-02)', () => {
  describe('number', () => {
    it.each(['abc', '', '1.2.3', 'NaN', 'Infinity'])('rejects "%s"', (v) => {
      expect(settingValueValidator.check('number', v)).not.toBeNull();
    });
    it('rejects negative numbers', () => {
      expect(settingValueValidator.check('number', '-1')).not.toBeNull();
    });
    it.each(['0', '30', '5.00', '270'])('accepts "%s"', (v) => {
      expect(settingValueValidator.check('number', v)).toBeNull();
    });
  });

  describe('boolean', () => {
    it.each(['0', '1'])('accepts "%s"', (v) => {
      expect(settingValueValidator.check('boolean', v)).toBeNull();
    });
    it.each(['true', 'yes', '2', ''])('rejects "%s"', (v) => {
      expect(settingValueValidator.check('boolean', v)).not.toBeNull();
    });
  });

  describe('date', () => {
    it('accepts a real calendar date', () => {
      expect(settingValueValidator.check('date', '2027-03-31')).toBeNull();
    });
    it.each(['2027-02-30', '31-03-2027', 'not-a-date', ''])('rejects "%s"', (v) => {
      expect(settingValueValidator.check('date', v)).not.toBeNull();
    });
  });

  describe('json', () => {
    it('accepts valid JSON', () => {
      expect(settingValueValidator.check('json', '{"a":1}')).toBeNull();
    });
    it.each(['{not json}', '', '{"a":}'])('rejects "%s"', (v) => {
      expect(settingValueValidator.check('json', v)).not.toBeNull();
    });
  });

  describe('string', () => {
    it('accepts anything', () => {
      expect(settingValueValidator.check('string', 'anything at all')).toBeNull();
      expect(settingValueValidator.check('string', '')).toBeNull();
    });
  });
});
