'use strict';

/**
 * Port of App\Helpers\BankDetailsInput. Owner request (2026-10-27): reads
 * and checks the bank-details section of the supplier / logistics-partner
 * form (staff) and of the Vendor Details Link (the vendor). The account
 * number is typed twice; a blank account number keeps the one on file.
 */
const ACCOUNT_TYPES = ['Current', 'Savings', 'Cash Credit / Overdraft'];
const FIELDS = ['bank_account_name', 'bank_name', 'bank_branch', 'bank_ifsc', 'bank_account_type', 'bank_upi_id'];

/**
 * @param {object} body the posted form
 * @param {object} current vendorDetailsRepository.bankDetails() of the vendor as it is now
 * @param {boolean} required the vendor's own link: holder, bank, account number and IFSC are required
 * @returns {{bank: object|null, error: string|null}} bank null = nothing to save (all blank, or unchanged)
 */
function collect(body, current, required) {
  const field = (key) => String(body[key] || '').trim() || null;
  const bank = {
    bank_account_name: field('bank_account_name'),
    bank_name: field('bank_name'),
    bank_branch: field('bank_branch'),
    bank_ifsc: field('bank_ifsc') !== null ? field('bank_ifsc').toUpperCase() : null,
    bank_account_type: field('bank_account_type'),
    bank_upi_id: field('bank_upi_id'),
  };
  const number = String(body.bank_account_number || '').replace(/\s+/g, '');
  const confirm = String(body.bank_account_number_confirm || '').replace(/\s+/g, '');

  const allBlank = number === '' && Object.values(bank).every((v) => v === null);
  if (allBlank && !required) {
    return { bank: null, error: null };
  }
  if (number !== '' || confirm !== '') {
    if (number !== confirm) {
      return { bank: null, error: 'The two bank account numbers do not match — type it again.' };
    }
    if (!/^[0-9]{6,20}$/.test(number)) {
      return { bank: null, error: 'Enter the bank account number as digits only (6 to 20 digits).' };
    }
  }
  const hasAccount = number !== '' || !!current.has_bank;
  if (required || number !== '') {
    for (const [key, label] of [['bank_account_name', 'account holder name'], ['bank_name', 'bank name'], ['bank_ifsc', 'IFSC code']]) {
      if (bank[key] === null) {
        return { bank: null, error: `Enter the ${label} for the bank account.` };
      }
    }
  }
  if (!hasAccount) {
    return { bank: null, error: 'Enter the bank account number (twice), or leave the whole bank section empty.' };
  }
  if (bank.bank_ifsc !== null && !/^[A-Z]{4}0[A-Z0-9]{6}$/.test(bank.bank_ifsc)) {
    return { bank: null, error: 'Enter a valid 11-character IFSC code (e.g. SBIN0001234).' };
  }
  if (bank.bank_account_type !== null && !ACCOUNT_TYPES.includes(bank.bank_account_type)) {
    return { bank: null, error: 'Choose the account type from the list.' };
  }

  let unchanged = number === '' || number === (current.bank_account_number || null);
  for (const key of FIELDS) {
    unchanged = unchanged && bank[key] === (current[key] === undefined ? null : current[key]);
  }
  bank.bank_account_number = number !== '' ? number : null;
  return { bank: unchanged ? null : bank, error: null };
}

module.exports = { ACCOUNT_TYPES, collect };
