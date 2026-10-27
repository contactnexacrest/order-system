<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\OrderStorage;
use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\CaFyLockGuard;
use App\Services\FileUploadService;

/**
 * Owner decisions (2026-10-27): the order's actual expenses (Step 12a) —
 * each with its receipt, or a reason when no bill exists. A voucher can be
 * entered later with its real (past) date, unless that date falls in a
 * financial year the CA has locked. docs/schema.sql Section CE.
 */
final class OrderExpenseController
{
    private function back(int $orderId, string $type, string $message): void
    {
        Flash::set($type, $message);
        header("Location: /orders/{$orderId}#financials-12a");
    }

    public function add(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $date = trim((string) ($_POST['expense_date'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $date > date('Y-m-d')) {
            $this->back($orderId, 'error', 'Enter the date of the expense (not in the future).');
            return;
        }
        $category = (string) ($_POST['category'] ?? '');
        if (!isset(OrderExpenseRepository::CATEGORIES[$category])) {
            $this->back($orderId, 'error', 'Choose the expense category.');
            return;
        }
        $details = trim((string) ($_POST['details'] ?? ''));
        if ($details === '') {
            $this->back($orderId, 'error', 'Enter what the expense was for.');
            return;
        }
        $amount = trim((string) ($_POST['amount_inr'] ?? ''));
        if (!is_numeric($amount) || (float) $amount <= 0) {
            $this->back($orderId, 'error', 'Enter the amount (₹).');
            return;
        }
        $paidBy = (string) ($_POST['paid_by'] ?? '');
        if (!isset(OrderExpenseRepository::PAID_BY[$paidBy])) {
            $this->back($orderId, 'error', 'Choose who paid.');
            return;
        }
        $staffName = trim((string) ($_POST['staff_name'] ?? ''));
        if ($paidBy === 'staff' && $staffName === '') {
            $this->back($orderId, 'error', 'Enter the name of the staff member to reimburse.');
            return;
        }
        $hasReceipt = !empty($_FILES['receipt']['name']);
        $noReceiptReason = trim((string) ($_POST['no_receipt_reason'] ?? ''));
        if (!$hasReceipt && mb_strlen($noReceiptReason) < ReasonValidator::MIN_LENGTH) {
            $this->back($orderId, 'error', 'Attach the receipt / bill — or, only if no bill exists, give the reason (at least ' . ReasonValidator::MIN_LENGTH . ' characters).');
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($date, (int) $user['id'], $roleId, 'order_expenses', 0, 'record')) {
            header("Location: /orders/{$orderId}#financials-12a");
            return;
        }
        $receiptId = null;
        if ($hasReceipt) {
            try {
                $receiptId = FileUploadService::handleUpload('receipt', 'expense_receipt', OrderStorage::forReceived($order, 'financials'), (int) $order['client_id'], $orderId, (int) $user['id'], null, null, 'Expense receipt');
            } catch (\Throwable $e) {
                $this->back($orderId, 'error', $e->getMessage());
                return;
            }
        }
        $id = OrderExpenseRepository::create($orderId, [
            'expense_date' => $date,
            'category' => $category,
            'details' => mb_substr($details, 0, 255),
            'amount_inr' => round((float) $amount, 2),
            'paid_by' => $paidBy,
            'staff_name' => $paidBy === 'staff' ? mb_substr($staffName, 0, 150) : null,
            'receipt_file_id' => $receiptId,
            'no_receipt_reason' => $hasReceipt ? null : mb_substr($noReceiptReason, 0, 255),
        ], (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_EXPENSE_ADDED', 'order_expenses', $id, 'amount_inr', null, (string) round((float) $amount, 2), OrderExpenseRepository::CATEGORIES[$category] . ' on ' . $date . ($hasReceipt ? '' : ' — no receipt: ' . $noReceiptReason));
        $this->back($orderId, 'success', 'Expense of ₹' . number_format((float) $amount, 2) . ' recorded.');
    }

    public function delete(array $params): void
    {
        $orderId = (int) $params['id'];
        $expense = OrderExpenseRepository::find((int) $params['expenseId']);
        if (!$expense || (int) $expense['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Expense not found.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow((string) $expense['expense_date'], (int) $user['id'], $roleId, 'order_expenses', (int) $expense['id'], 'delete')) {
            header("Location: /orders/{$orderId}#financials-12a");
            return;
        }
        OrderExpenseRepository::delete((int) $expense['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_EXPENSE_REMOVED', 'order_expenses', (int) $expense['id'], 'amount_inr', (string) $expense['amount_inr'], null, (string) $expense['details']);
        $this->back($orderId, 'success', 'Expense removed.');
    }
}
