'use strict';

const reportRepository = require('../../src/repositories/reportRepository');

/**
 * Phase 2 item 1 of the approved remediation plan: "customer-wise /
 * product-wise / country-wise reports." Product-wise already existed
 * (products()); this covers aggregateSummary()'s new by_client
 * (customer-wise) breakdown and country_fob_by_currency (giving
 * country-wise an actual value breakdown, not just an order count).
 * Port of PHP's AggregateSummaryByClientAndCountryTest.php.
 */
describe('aggregateSummary by_client and country_fob_by_currency (Phase 2 item 1)', () => {
  it('by_client groups order count and FOB value per customer', () => {
    const rows = [
      { currency_code: 'USD', total_fob_value: '1000', current_stage_name: 'Quotation', status: 'active', country_of_destination: 'UK', company_legal_name: 'Acme Ltd' },
      { currency_code: 'USD', total_fob_value: '500', current_stage_name: 'Quotation', status: 'active', country_of_destination: 'UK', company_legal_name: 'Acme Ltd' },
      { currency_code: 'EUR', total_fob_value: '300', current_stage_name: 'Quotation', status: 'active', country_of_destination: 'Germany', company_legal_name: 'Beta GmbH' },
    ];

    const summary = reportRepository.aggregateSummary(rows);

    expect(summary.by_client['Acme Ltd'].count).toBe(2);
    expect(summary.by_client['Acme Ltd'].fob_by_currency.USD).toBe(1500);
    expect(summary.by_client['Beta GmbH'].count).toBe(1);
    expect(summary.by_client['Beta GmbH'].fob_by_currency.EUR).toBe(300);
  });

  it('by_client sorts most orders first', () => {
    const rows = [
      { currency_code: 'USD', total_fob_value: '100', current_stage_name: 'Quotation', status: 'active', country_of_destination: 'UK', company_legal_name: 'One Order Co' },
      { currency_code: 'USD', total_fob_value: '100', current_stage_name: 'Quotation', status: 'active', country_of_destination: 'UK', company_legal_name: 'Two Orders Co' },
      { currency_code: 'USD', total_fob_value: '100', current_stage_name: 'Quotation', status: 'active', country_of_destination: 'UK', company_legal_name: 'Two Orders Co' },
    ];

    const summary = reportRepository.aggregateSummary(rows);
    const names = Object.keys(summary.by_client);

    expect(names[0]).toBe('Two Orders Co');
  });

  it('country_fob_by_currency gives country-wise value, not just count', () => {
    const rows = [
      { currency_code: 'USD', total_fob_value: '1000', current_stage_name: 'Quotation', status: 'active', country_of_destination: 'UK', company_legal_name: 'Acme Ltd' },
      { currency_code: 'USD', total_fob_value: '2000', current_stage_name: 'Quotation', status: 'active', country_of_destination: 'UK', company_legal_name: 'Other Co' },
    ];

    const summary = reportRepository.aggregateSummary(rows);

    expect(summary.by_country.UK).toBe(2);
    expect(summary.country_fob_by_currency.UK.USD).toBe(3000);
  });
});
