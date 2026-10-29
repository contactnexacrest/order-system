'use strict';

const flash = require('../helpers/flash');
const orderStorage = require('../helpers/orderStorage');
const reasonValidator = require('../helpers/reasonValidator');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const orderExpenseRepository = require('../repositories/orderExpenseRepository');
const orderRepository = require('../repositories/orderRepository');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const zohoBooksService = require('../services/zohoBooksService');
const caFyLockGuard = require('../services/caFyLockGuard');
const fileUploadService = require('../services/fileUploadService');

/**
 * Port of App\Controllers\OrderExpenseController. Owner decisions
 * (2026-10-27): the order's actual expenses (Step 12a) — each with its
 * receipt, or a reason when no bill exists. A voucher can be entered later
 * with its real (past) date, unless that date falls in a financial year the
 * CA has locked.
 */
const str = (v) => (v === undefined || v === null ? '' : String(v).trim());

function back(req, res, orderId, type, message) {
  flash.set(req, type, message);
  res.redirect(`/orders/${orderId}#financials-12a`);
}

async function add(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const date = str(req.body.expense_date);
  const m = date.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  const d = m ? new Date(`${date}T00:00:00Z`) : null;
  if (!m || d.getUTCFullYear() !== Number(m[1]) || d.getUTCMonth() + 1 !== Number(m[2]) || d.getUTCDate() !== Number(m[3]) || date > todayIst()) {
    back(req, res, orderId, 'error', 'Enter the date of the expense (not in the future).');
    return;
  }
  const category = str(req.body.category);
  if (!orderExpenseRepository.CATEGORIES[category]) {
    back(req, res, orderId, 'error', 'Choose the expense category.');
    return;
  }
  const details = str(req.body.details);
  if (details === '') {
    back(req, res, orderId, 'error', 'Enter what the expense was for.');
    return;
  }
  const amount = str(req.body.amount_inr);
  if (amount === '' || Number.isNaN(Number(amount)) || Number(amount) <= 0) {
    back(req, res, orderId, 'error', 'Enter the amount (₹).');
    return;
  }
  const paidBy = str(req.body.paid_by);
  if (!orderExpenseRepository.PAID_BY[paidBy]) {
    back(req, res, orderId, 'error', 'Choose who paid.');
    return;
  }
  const staffName = str(req.body.staff_name);
  if (paidBy === 'staff' && staffName === '') {
    back(req, res, orderId, 'error', 'Enter the name of the staff member to reimburse.');
    return;
  }
  const hasReceipt = !!req.file;
  const noReceiptReason = str(req.body.no_receipt_reason);
  if (!hasReceipt && noReceiptReason.length < reasonValidator.MIN_LENGTH) {
    back(req, res, orderId, 'error', `Attach the receipt / bill — or, only if no bill exists, give the reason (at least ${reasonValidator.MIN_LENGTH} characters).`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, date, 'order_expenses', 0, 'record'))) {
    res.redirect(`/orders/${orderId}#financials-12a`);
    return;
  }
  let receiptId = null;
  if (hasReceipt) {
    try {
      receiptId = await fileUploadService.handleUpload(req, 'receipt', 'expense_receipt', orderStorage.forReceived(order, 'financials'), order.client_id, orderId, req.user.id, null, null, 'Expense receipt');
    } catch (e) {
      back(req, res, orderId, 'error', e.message);
      return;
    }
  }
  const rounded = Math.round(Number(amount) * 100) / 100;
  const id = await orderExpenseRepository.create(orderId, {
    expense_date: date,
    category,
    details: details.slice(0, 255),
    amount_inr: rounded,
    paid_by: paidBy,
    staff_name: paidBy === 'staff' ? staffName.slice(0, 150) : null,
    receipt_file_id: receiptId,
    no_receipt_reason: hasReceipt ? null : noReceiptReason.slice(0, 255),
  }, req.user.id);
  await auditLogRepository.log(req.user.id, 'ORDER_EXPENSE_ADDED', 'order_expenses', id, 'amount_inr', null, String(rounded), `${orderExpenseRepository.CATEGORIES[category]} on ${date}${hasReceipt ? '' : ` — no receipt: ${noReceiptReason}`}`);
  back(req, res, orderId, 'success', `Expense of ₹${rounded.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} recorded.`);
}

async function remove(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const expense = await orderExpenseRepository.find(parseInt(req.params.expenseId, 10));
  if (!expense || Number(expense.order_id) !== orderId) {
    res.status(404).send('Expense not found.');
    return;
  }
  if (!(await caFyLockGuard.allow(req, String(expense.expense_date), 'order_expenses', expense.id, 'delete'))) {
    res.redirect(`/orders/${orderId}#financials-12a`);
    return;
  }
  // Owner decision 2026-10-29: Zoho Books must match the app — an expense
  // already in Books is removed there first.
  if (expense.zoho_expense_id) {
    try {
      await zohoBooksService.deleteRecord('expense', String(expense.zoho_expense_id));
    } catch (e) {
      back(req, res, orderId, 'error', `This expense is in Zoho Books and could not be removed there, so nothing was changed: ${e.message}`);
      return;
    }
    await zohoSyncLogRepository.log('order_expense', 'order', orderId, 'expense', 'success', String(expense.zoho_expense_id), `Removed expense "${expense.details}" (INR ${Number(expense.amount_inr).toFixed(2)}) from Zoho Books: it was removed in the app.`, 'manual', req.user.id);
  }
  await orderExpenseRepository.remove(expense.id);
  await auditLogRepository.log(req.user.id, 'ORDER_EXPENSE_REMOVED', 'order_expenses', expense.id, 'amount_inr', String(expense.amount_inr), null, String(expense.details));
  back(req, res, orderId, 'success', expense.zoho_expense_id ? 'Expense removed, here and in Zoho Books.' : 'Expense removed.');
}

module.exports = { add, remove };
