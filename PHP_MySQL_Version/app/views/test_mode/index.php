<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<div class="card page-wide">
  <h1>Test Mode</h1>
  <p class="muted">Run the application full-fledged for testing — complete order pipeline, PDF/DOCX generation, and reports — without touching production data or sending real mail to real clients. While Test Mode is on: the client portal and the public quotation-details form are unavailable, every business email is redirected to the test address below, admin panel settings cannot be changed by anyone (Super Admin included), reports show only test data, and no audit-log entries are recorded for test records. Every order/client/document created while it is on is a test record — its reference number is prefixed "TEST-".</p>

  <?php if ((int) ($settings['is_enabled'] ?? 0) === 1): ?>
    <div class="alert alert-error" style="font-weight:700">TEST MODE IS CURRENTLY ON</div>
  <?php else: ?>
    <div class="alert alert-success">Test Mode is currently off.</div>
  <?php endif; ?>

  <div class="section">
    <h2>Test Email</h2>
    <p class="muted small">Every outbound business email (order/document notices, buyer communications, reminder alerts) is redirected here while Test Mode is on. Staff's own login verification codes and password-reset emails are never redirected. Editable at any time, whether Test Mode is on or off.</p>
    <form method="post" action="/test-mode/test-email" style="display:flex;gap:8px;align-items:center">
      <?= Csrf::field() ?>
      <input type="email" name="test_email" value="<?= View::e($settings['test_email'] ?? '') ?>" placeholder="test-inbox@example.com" required style="width:320px">
      <button type="submit" class="btn-sm">Save</button>
    </form>
  </div>

  <div class="section">
    <h2>Current Test Data</h2>
    <table class="list">
      <tr><th>Clients</th><th>Orders</th><th>Suppliers</th></tr>
      <tr>
        <td><?= (int) $counts['clients'] ?></td>
        <td><?= (int) $counts['orders'] ?></td>
        <td><?= (int) $counts['suppliers'] ?></td>
      </tr>
    </table>
  </div>

  <div class="section">
    <h2>Controls</h2>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <?php if ((int) ($settings['is_enabled'] ?? 0) !== 1): ?>
        <form method="post" action="/test-mode/enable" onsubmit="return confirm('Enable Test Mode? The client portal and quotation-details form will become unavailable, and all outbound business mail will redirect to the test email.');">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-success">Enable Test Mode</button>
        </form>
      <?php else: ?>
        <form method="post" action="/test-mode/disable" onsubmit="return confirm('Disable Test Mode?');">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-danger" <?= $hasTestData ? 'disabled title="Delete all test data first"' : '' ?>>Disable Test Mode</button>
        </form>
        <?php if ($hasTestData): ?><p class="muted small">Disable is unavailable while test data exists — delete it first.</p><?php endif; ?>
      <?php endif; ?>

      <form method="post" action="/test-mode/delete-test-data" onsubmit="return confirm('Permanently delete ALL test data — clients, orders, documents, generated PDF/DOCX files, everything flagged as test? This cannot be undone.');">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-danger" <?= !$hasTestData ? 'disabled title="No test data to delete"' : '' ?>>Delete Test Data</button>
      </form>
    </div>
  </div>
</div>
