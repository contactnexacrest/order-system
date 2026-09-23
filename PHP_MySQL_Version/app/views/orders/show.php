<?php
use App\Helpers\Csrf;
use App\Services\AuthService;
use App\Services\PermissionService;

$currentUser = AuthService::currentUser();
$canViewReports = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_reports');
$canEditLockedData = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'edit_locked_data');
$canManageOrders = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_orders');
$canViewAuditLog = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_audit_log');

$stage1 = $stageByNumber[1] ?? null;
$stage2 = $stageByNumber[2] ?? null;
$stage3 = $stageByNumber[3] ?? null;
$stage4 = $stageByNumber[4] ?? null;
$stage5 = $stageByNumber[5] ?? null;
$stage6 = $stageByNumber[6] ?? null;
$stage7 = $stageByNumber[7] ?? null;
$stage8 = $stageByNumber[8] ?? null;
$stage9 = $stageByNumber[9] ?? null;

$hasQt = $stage1 && $stage1['status'] === 'gate_passed';
$hasBuyerPo = $stage2 && $stage2['status'] === 'gate_passed';
$advanceCleared = $stage3 && $stage3['status'] === 'gate_passed';
$buyerAcknowledged = $stage4 && $stage4['status'] === 'gate_passed';
$supplierSigned = $stage5 && $stage5['status'] === 'gate_passed';
$isFob = strtoupper((string) $order['incoterm_code']) === 'FOB';
$freightSkipped = $stage6 && $stage6['status'] === 'skipped';
$freightCleared = $stage6 && $stage6['status'] === 'gate_passed';
$blIssued = $stage7 && $stage7['status'] === 'gate_passed';
$balanceCleared = $stage8 && $stage8['status'] === 'gate_passed';
$orderClosed = $order['status'] === 'complete';
?>
<div class="card page-wide">
  <h1><?= htmlspecialchars($order['order_reference']) ?>
    <?php if ($order['status'] !== 'active'): ?><span class="badge badge-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($order['status']) ?></span><?php endif; ?>
  </h1>
  <p class="muted">
    <?= htmlspecialchars($order['company_legal_name']) ?> &nbsp;·&nbsp;
    Buyer Inquiry Ref: <?= htmlspecialchars($order['buyer_inquiry_ref']) ?> &nbsp;·&nbsp;
    <?= htmlspecialchars($order['incoterm_code']) ?> <?= htmlspecialchars($order['port_of_loading_name'] ?? '') ?>
    <?php if ($canViewReports): ?>&nbsp;·&nbsp; <a href="/reports/order/<?= (int) $order['id'] ?>">Full Report</a><?php endif; ?>
  </p>
  <?php if ($order['status'] === 'lost'): ?>
    <p class="muted small">Marked lost<?php if ($order['lost_at']): ?> on <?= htmlspecialchars($order['lost_at']) ?><?php endif; ?><?php if ($order['lost_reason']): ?> — reason: <?= htmlspecialchars($order['lost_reason']) ?><?php endif; ?></p>
  <?php endif; ?>
  <?php if ((int) ($order['is_archived'] ?? 0) === 1): ?>
    <p class="muted small">Archived<?php if ($order['archived_at']): ?> on <?= htmlspecialchars($order['archived_at']) ?><?php endif; ?> — hidden from the main Orders list. Nothing was deleted.</p>
    <?php if ($canManageOrders): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/unarchive" style="display:inline" onsubmit="return confirm('Restore <?= htmlspecialchars(addslashes($order['order_reference'])) ?> to the main Orders list?');">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm btn-success">Restore from Archive</button>
    </form>
    <?php endif; ?>
  <?php elseif ($canManageOrders): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/archive" style="display:inline" onsubmit="return confirm('Archive <?= htmlspecialchars(addslashes($order['order_reference'])) ?>? It will no longer appear in the main Orders list, but nothing is deleted and it can be restored any time.');">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm btn-secondary">Archive Order</button>
    </form>
  <?php endif; ?>
  <?php if ($order['status'] === 'active' && $canManageOrders): ?>
  <form method="post" action="/orders/<?= (int) $order['id'] ?>/mark-lost" style="display:inline" onsubmit="return confirmMarkLost(this);">
    <?= Csrf::field() ?>
    <input type="hidden" name="reason" class="mark-lost-reason">
    <button type="button" class="btn-sm btn-danger" onclick="promptMarkLost(this)">Mark as Lost</button>
  </form>
  <script>
    function promptMarkLost(btn) {
      var form = btn.closest('form');
      var reason = prompt('Why is this order lost? (required — recorded in the audit log)');
      if (!reason || !reason.trim()) { return; }
      form.querySelector('.mark-lost-reason').value = reason.trim();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
    function confirmMarkLost(form) {
      var reason = form.querySelector('.mark-lost-reason').value;
      if (!reason) { return false; }
      return confirm('Mark <?= htmlspecialchars(addslashes($order['order_reference'])) ?> as LOST? This locks the order (same as closing it) and cannot be undone from this screen.');
    }
  </script>
  <?php endif; ?>

  <div class="stage-track">
    <?php foreach ($stages as $s): ?>
      <div class="stage-chip <?= htmlspecialchars($s['status']) ?>">
        <?= (int) $s['stage_number'] ?>. <?= htmlspecialchars($s['stage_name']) ?><br>
        <span class="muted small"><?= htmlspecialchars(str_replace('_', ' ', $s['status'])) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="section">
    <h2>Order Details</h2>
    <div class="kv-grid">
      <div><span class="k">FOB Value</span><span class="v"><?= htmlspecialchars($order['currency_code']) ?> <?= number_format((float) $fobTotal, 2) ?></span></div>
      <div><span class="k">Port of Discharge</span><span class="v"><?= htmlspecialchars($order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? 'TBC') ?></span></div>
      <div><span class="k">Container Type</span><span class="v"><?= htmlspecialchars($order['container_type'] ?? '—') ?></span></div>
      <div><span class="k">Payment Preset</span><span class="v"><?= htmlspecialchars($order['preset_name']) ?></span></div>
      <div><span class="k">Buyer's PO Ref</span><span class="v"><?= htmlspecialchars($order['buyers_po_ref'] ?? 'NIL') ?></span></div>
      <div><span class="k">Special Requirements</span><span class="v"><?= htmlspecialchars($order['special_requirements'] ?? '—') ?></span></div>
    </div>
  </div>

  <div class="section">
    <h2>Products</h2>
    <table class="list">
      <tr><th>#</th><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th></tr>
      <?php foreach ($products as $i => $p): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($p['description']) ?><?php if ($p['dimensions'] || $p['finish']): ?><br><span class="muted small"><?= htmlspecialchars($p['dimensions'] ?? '') ?> <?= htmlspecialchars($p['finish'] ?? '') ?></span><?php endif; ?></td>
        <td><?= $p['quantity_is_tbc'] ? 'TBC' : htmlspecialchars((string) $p['quantity']) ?></td>
        <td><?= htmlspecialchars($p['unit'] ?? '') ?></td>
        <td><?= $p['unit_price'] !== null ? number_format((float) $p['unit_price'], 2) : 'TBC' ?></td>
        <td><?= $p['fob_value'] !== null ? number_format((float) $p['fob_value'], 2) : 'TBC' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="section">
    <h2>Documents</h2>
    <table class="list">
      <tr><th>Type</th><th>Reference</th><th>Rev.</th><th>Generated</th><th>Status</th><th>Download</th></tr>
      <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['document_type_code']) ?></td>
        <td><?= htmlspecialchars($d['document_reference'] ?? '—') ?></td>
        <td><?= (int) $d['revision_number'] ?></td>
        <td><?= htmlspecialchars($d['generated_at']) ?></td>
        <td><?= htmlspecialchars(str_replace('_', ' ', $d['status'])) ?></td>
        <td>
          <?php if ($d['pdf_file_id']): ?><a href="/documents/<?= (int) $d['id'] ?>/download?format=pdf">PDF</a><?php endif; ?>
          <?php if ($d['docx_file_id']): ?> · <a href="/documents/<?= (int) $d['id'] ?>/download?format=docx">DOCX (internal)</a><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?>
      <tr><td colspan="6" class="muted">No documents generated yet.</td></tr>
      <?php endif; ?>
    </table>

    <div class="btn-row">
      <?php if ($stage1 && $stage1['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="QT">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…"><?= $hasQt ? 'Regenerate Quotation (new revision)' : 'Generate Quotation' ?></button>
        </form>
      <?php endif; ?>

      <?php if ($stage3 && $stage3['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="PI">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate Proforma Invoice</button>
        </form>
      <?php endif; ?>

      <?php if ($stage4 && $stage4['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="OC">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate Order Confirmation</button>
        </form>
      <?php endif; ?>

      <?php if ($stage2 && $stage2['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="BUYERPO">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate Buyer PO</button>
        </form>
      <?php endif; ?>

      <?php if ($stage5 && $stage5['status'] !== 'locked' && $supplierPo): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="SUPPO">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate Supplier PO</button>
        </form>
      <?php endif; ?>

      <?php if (!$isFob && $stage6 && $stage6['status'] !== 'locked' && $freight): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="FDN">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate Freight Debit Note</button>
        </form>
      <?php endif; ?>

      <?php if ($stage7 && $stage7['status'] !== 'locked' && $packing): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="PL">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate Packing List</button>
        </form>
      <?php endif; ?>

      <?php if ($stage7 && $stage7['status'] !== 'locked' && $shipping): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="BLI">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate BL Instruction Sheet</button>
        </form>
      <?php endif; ?>

      <?php if ($stage8 && $stage8['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="CI">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate Commercial Invoice</button>
        </form>
      <?php endif; ?>

      <?php if ($stage9 && $stage9['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="COOPREP">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate COO Prep Sheet (internal)</button>
        </form>
      <?php endif; ?>

      <?php if ($order['include_annexure_a']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="ANNEXA">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate Annexure A</button>
        </form>
      <?php endif; ?>
    </div>
    <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>/annexure"><?= $order['include_annexure_a'] ? 'Manage Annexure A product entries &amp; images' : 'Enable / manage Annexure A' ?></a></p>
  </div>

  <div class="section">
    <h2>Review, Approval &amp; Send to Buyer</h2>
    <p class="muted">A document generated above starts life <strong>draft</strong> with the DRAFT watermark. Assign at least one reviewer to move it to review; once every assigned reviewer approves (minimum required per document type), it's re-watermarked as final and can be sent to the buyer. The buyer only ever receives that final watermarked PDF — never the internal DOCX, never an unwatermarked copy.</p>
    <?php foreach ($documents as $d):
      $reviews = $reviewsByDocument[(int) $d['id']] ?? [];
      $crossVerifications = $crossVerificationsByDocument[(int) $d['id']] ?? [];
      $myPendingReview = null;
      foreach ($reviews as $r) {
          if ((int) $r['reviewer_id'] === (int) $currentUser['id'] && $r['status'] === 'pending') {
              $myPendingReview = $r;
          }
      }
    ?>
      <details>
        <summary><?= htmlspecialchars($d['document_type_code']) ?> <?= htmlspecialchars($d['document_reference'] ?? '—') ?> Rev.<?= (int) $d['revision_number'] ?> — <?= htmlspecialchars(str_replace('_', ' ', $d['status'])) ?></summary>

        <?php if (empty($reviews)): ?>
          <form method="post" action="/documents/<?= (int) $d['id'] ?>/reviewers">
            <?= Csrf::field() ?>
            <label>Assign reviewer(s) * (minimum required: <?= (int) ($d['min_reviewers_default'] ?? 1) ?>)
              <select name="reviewer_ids[]" multiple size="4" required style="min-width:220px">
                <?php foreach ($activeUsers as $u): ?>
                  <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['role_name'] ?? '—') ?>)</option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="btn-sm">Assign Reviewers</button>
          </form>
        <?php else: ?>
          <table class="list">
            <tr><th>Reviewer</th><th>Status</th><th>Comments</th><th>Reviewed</th></tr>
            <?php foreach ($reviews as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['reviewer_name']) ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
                <td><?= htmlspecialchars($r['comments'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['reviewed_at'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>

        <?php if ($myPendingReview): ?>
          <form method="post" action="/reviews/<?= (int) $myPendingReview['id'] ?>/approve" style="display:inline">
            <?= Csrf::field() ?>
            <input type="text" name="comments" placeholder="Comments (optional)">
            <button type="submit" class="btn-sm btn-success">Approve</button>
          </form>
          <form method="post" action="/reviews/<?= (int) $myPendingReview['id'] ?>/reject" style="display:inline" onsubmit="return confirm('Reject this document? It goes back for rework.');">
            <?= Csrf::field() ?>
            <input type="text" name="comments" placeholder="Reason (mandatory)" required>
            <button type="submit" class="btn-sm btn-danger">Reject</button>
          </form>
        <?php endif; ?>

        <form method="post" action="/documents/<?= (int) $d['id'] ?>/cross-verify" style="margin-top:6px">
          <?= Csrf::field() ?>
          <select name="result" required>
            <option value="pass">Cross-verify: Pass</option>
            <option value="fail">Cross-verify: Fail</option>
          </select>
          <input type="text" name="comments" placeholder="Comments (optional)">
          <button type="submit" class="btn-sm">Record Cross-Verification</button>
        </form>
        <?php if (!empty($crossVerifications)): ?>
          <p class="muted small">
            <?php foreach ($crossVerifications as $cv): ?>
              <?= htmlspecialchars($cv['verified_by_name']) ?>: <?= htmlspecialchars($cv['result']) ?> (<?= htmlspecialchars($cv['verified_at']) ?>)<br>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>

        <?php
          $emailLogs = $emailLogByDocument[(int) $d['id']] ?? [];
          $hasPendingSend = false;
          foreach ($emailLogs as $log) {
              if ($log['status'] === 'pending_approval' || $log['status'] === 'approved') {
                  $hasPendingSend = true;
              }
          }
        ?>
        <?php if (!empty($emailLogs)): ?>
          <table class="list" style="margin-top:6px">
            <tr><th>Sent To</th><th>Status</th><th>Requested</th><th>Detail</th></tr>
            <?php foreach ($emailLogs as $log): ?>
              <tr>
                <td><?= htmlspecialchars($log['recipient_email']) ?></td>
                <td><?= htmlspecialchars(str_replace('_', ' ', $log['status'])) ?></td>
                <td><?= htmlspecialchars($log['created_at']) ?></td>
                <td>
                  <?php
                    $canCancelThis = $currentUser && (PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'approve_email_send') || (int) $log['requested_by'] === (int) $currentUser['id']);
                  ?>
                  <?php if ($log['status'] === 'sent'): ?>
                    Sent <?= htmlspecialchars($log['sent_at'] ?? '') ?>
                  <?php elseif ($log['status'] === 'rejected'): ?>
                    Rejected: <?= htmlspecialchars($log['rejection_reason'] ?? '') ?>
                  <?php elseif ($log['status'] === 'failed'): ?>
                    Dispatch failed — check mail server configuration and retry.
                  <?php elseif ($log['status'] === 'cancelled'): ?>
                    Cancelled: <?= htmlspecialchars($log['cancellation_reason'] ?? '') ?>
                  <?php elseif ($log['status'] === 'approved'): ?>
                    Approved — will send once its scheduled time arrives.
                    <?php if ($canCancelThis): ?>
                    <form method="post" action="/email-log/<?= (int) $log['id'] ?>/cancel" style="margin-top:4px" onsubmit="return confirm('Cancel this send? It will never go out.');">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                      <input type="text" name="reason" placeholder="Cancel reason (mandatory)" required style="width:200px">
                      <button type="submit" class="btn-sm btn-warning">Cancel Send</button>
                    </form>
                    <?php endif; ?>
                  <?php else: ?>
                    Awaiting Level-2 approval.
                    <?php if ($canCancelThis): ?>
                    <form method="post" action="/email-log/<?= (int) $log['id'] ?>/cancel" style="margin-top:4px" onsubmit="return confirm('Cancel this send? It will never go out.');">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                      <input type="text" name="reason" placeholder="Cancel reason (mandatory)" required style="width:200px">
                      <button type="submit" class="btn-sm btn-warning">Cancel Send</button>
                    </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>

        <?php if ($d['status'] === 'approved' && $d['document_type_code'] !== 'AMD' && $d['document_type_code'] !== 'SUPPO' && $d['document_type_code'] !== 'COOPREP' && $d['document_type_code'] !== 'BLI'): ?>
          <?php if ($hasPendingSend): ?>
            <p class="muted">A send to the buyer is already in progress for this document (see above) — no new send can be submitted until it's sent, rejected, or fails.</p>
          <?php else: ?>
            <p><a href="/orders/<?= (int) $order['id'] ?>/documents/<?= (int) $d['id'] ?>/send" class="btn-sm">Send to Buyer (deferred, 2-level approval)</a></p>
          <?php endif; ?>
        <?php elseif ($d['status'] === 'sent'): ?>
          <p class="muted">Already sent to buyer.</p>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
  </div>

  <div class="section">
    <h2>Amendments &amp; Disputes</h2>
    <p><a href="/orders/<?= (int) $order['id'] ?>/amendments">Payment Terms Amendments (<?= (int) $amendmentCount ?>)</a>
       &nbsp;·&nbsp;
       <a href="/orders/<?= (int) $order['id'] ?>/disputes">Disputes (<?= (int) $openDisputeCount ?> open)</a>
       <?php if ($canViewAuditLog): ?>
       &nbsp;·&nbsp;
       <a href="/orders/<?= (int) $order['id'] ?>/audit-log">Audit Log</a>
       <?php endif; ?>
       &nbsp;·&nbsp;
       <a href="/orders/<?= (int) $order['id'] ?>/dossier" class="js-slow-download" data-loading-text="Building ZIP…">Download Full Dossier (ZIP)</a>
    </p>
  </div>

  <?php if ($canEditLockedData): ?>
  <div class="section">
    <h2>Admin Override</h2>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/override-status-lock" onsubmit="return confirmFieldOverride(this, 'this order\'s status/lock');">
      <?= Csrf::field() ?>
      <label>Status
        <select name="status">
          <option value="active" <?= $order['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="complete" <?= $order['status'] === 'complete' ? 'selected' : '' ?>>Complete</option>
          <option value="disputed" <?= $order['status'] === 'disputed' ? 'selected' : '' ?>>Disputed</option>
          <option value="lost" <?= $order['status'] === 'lost' ? 'selected' : '' ?>>Lost</option>
        </select>
      </label>
      <label><input type="checkbox" name="is_locked" value="1" style="display:inline-block;width:auto;" <?= $order['is_locked'] ? 'checked' : '' ?>> Locked (normally set automatically once status = complete)</label>
      <label>Reason for this change * <textarea class="override-reason" name="reason" rows="2" required></textarea></label>
      <button type="submit" class="btn-sm btn-danger">Override Status/Lock</button>
    </form>
  </div>
  <script>
  function confirmFieldOverride(form, label) {
    var reasonEl = form.querySelector('.override-reason');
    if (!reasonEl || reasonEl.value.trim() === '') {
      alert('A reason is required before saving.');
      return false;
    }
    return confirm('Override ' + label + '? This is logged and cannot be undone through this screen.');
  }
  </script>
  <?php endif; ?>

  <div class="section">
    <h2>Stage 2 — Buyer PO</h2>
    <?php if ($hasBuyerPo): ?>
      <p>Buyer PO recorded: <strong><?= htmlspecialchars($order['buyers_po_ref']) ?></strong></p>
    <?php elseif ($stage2 && $stage2['status'] !== 'locked'): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-po">
        <?= Csrf::field() ?>
        <label>Buyer's PO / Reference Number *<input type="text" name="buyers_po_ref" required></label>
        <button type="submit" class="btn-sm">Confirm Buyer PO Received</button>
      </form>
    <?php else: ?>
      <p class="muted">Generate the Quotation first to unlock this gate.</p>
    <?php endif; ?>

    <?php if ($stage2 && $stage2['status'] !== 'locked'): ?>
      <?php if (!empty($buyerPoDocuments)): ?>
        <table class="list" style="margin-top:8px">
          <tr><th>File</th><th>Uploaded</th><th></th></tr>
          <?php foreach ($buyerPoDocuments as $bd): ?>
            <tr>
              <td><?= htmlspecialchars($bd['original_filename']) ?></td>
              <td><?= htmlspecialchars($bd['uploaded_at']) ?></td>
              <td><a href="/file-store/<?= (int) $bd['file_id'] ?>/download">Download</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-po/documents" enctype="multipart/form-data" style="margin-top:6px">
        <?= Csrf::field() ?>
        <input type="file" name="document" required>
        <button type="submit" class="btn-sm">Attach Buyer PO Copy</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Payment Status</h2>
    <?php if ($payment): ?>
    <div class="kv-grid">
      <div><span class="k">Advance Amount</span><span class="v"><?= $payment['advance_amount'] !== null ? number_format((float) $payment['advance_amount'], 2) : '—' ?></span></div>
      <div><span class="k">Advance T/T Received</span><span class="v"><?= htmlspecialchars($payment['advance_remittance_received_at'] ?? '—') ?></span></div>
      <div><span class="k">Advance Cleared</span><span class="v"><?= htmlspecialchars($payment['advance_cleared_at'] ?? '—') ?></span></div>
      <div><span class="k">Balance Amount</span><span class="v"><?= $payment['balance_amount'] !== null ? number_format((float) $payment['balance_amount'], 2) : '—' ?></span></div>
      <div><span class="k">Balance Due</span><span class="v"><?= htmlspecialchars($payment['balance_due_date'] ?? 'event-triggered') ?></span></div>
    </div>
    <?php endif; ?>

    <?php if ($stage3 && $stage3['status'] !== 'locked' && !$advanceCleared): ?>
      <?php if (!$payment || $payment['advance_remittance_received_at'] === null): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/advance">
          <?= Csrf::field() ?>
          <label>Advance Amount Received *<input type="text" name="advance_amount" required></label>
          <label>Received On<input type="date" name="advance_received_at" value="<?= date('Y-m-d') ?>"></label>
          <button type="submit" class="btn-sm">Record Advance Remittance</button>
        </form>
      <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/advance/clear" onsubmit="return confirm('Mark the advance payment cleared? This unlocks Stage 4 and auto-provisions the client portal login — confirm the funds have actually landed first.');">
          <?= Csrf::field() ?>
          <label>Cleared On<input type="date" name="advance_cleared_at" value="<?= date('Y-m-d') ?>"></label>
          <button type="submit" class="btn-sm btn-success">Mark Advance Cleared (unlocks Stage 4)</button>
        </form>
      <?php endif; ?>
    <?php elseif (!$stage3 || $stage3['status'] === 'locked'): ?>
      <p class="muted">Record the Buyer PO first to unlock this gate.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Production &amp; Estimated Shipment</h2>
    <div class="kv-grid">
      <div><span class="k">Production Status</span><span class="v"><?= htmlspecialchars($order['production_status_text'] ?? 'Not yet commenced') ?></span></div>
      <div><span class="k">Est. Shipment</span><span class="v"><?= htmlspecialchars($order['est_shipment_date_text'] ?? 'TBC') ?></span></div>
    </div>
    <?php if ($stage4 && $stage4['status'] !== 'locked'): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/production-status">
        <?= Csrf::field() ?>
        <label>Production Status<input type="text" name="production_status_text" placeholder="e.g. Cutting and finishing in progress — 30% complete"></label>
        <label>Estimated Shipment<input type="text" name="est_shipment_date_text" placeholder="e.g. Week of 15 October 2026"></label>
        <button type="submit" class="btn-sm">Update</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Stage 4 — Order Confirmation: Buyer Acknowledgement</h2>
    <?php if ($buyerAcknowledged): ?>
      <p>Buyer has acknowledged the Order Confirmation.</p>
    <?php elseif ($stage4 && $stage4['status'] !== 'locked'): ?>
      <p class="muted">Generate the Order Confirmation above and send it to the buyer, then confirm their acknowledgement here.</p>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-acknowledged">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-sm btn-success">Confirm Buyer Acknowledged Order (unlocks Stage 5)</button>
      </form>
    <?php else: ?>
      <p class="muted">Clear the advance payment first to unlock this gate.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Stage 5 — Supplier Purchase Order (Material Procurement)</h2>
    <?php if (!$stage5 || $stage5['status'] === 'locked'): ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate.</p>
    <?php else: ?>
      <?php if ($supplierPo): ?>
        <div class="kv-grid">
          <div><span class="k">Supplier</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_legal_name']) ?></span></div>
          <div><span class="k">Supplier PO Ref</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_po_reference']) ?></span></div>
          <div><span class="k">Material</span><span class="v"><?= htmlspecialchars($supplierPo['material_stone_type'] ?? '—') ?></span></div>
          <div><span class="k">Total Payable (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['total_payable_inr'] ?? '—') ?></span></div>
          <div><span class="k">Status</span><span class="v"><?= htmlspecialchars($supplierPo['status']) ?></span></div>
        </div>
      <?php else: ?>
        <details open>
          <summary>Add a new supplier</summary>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/suppliers">
            <?= Csrf::field() ?>
            <label>Supplier Legal Name *<input type="text" name="supplier_legal_name" required></label>
            <label>Address<input type="text" name="address"></label>
            <label>GSTIN<input type="text" name="gstin"></label>
            <label>PAN<input type="text" name="pan"></label>
            <label>Contact Person<input type="text" name="contact_person"></label>
            <label>Phone<input type="text" name="phone"></label>
            <label>Supplier Type
              <select name="supplier_type">
                <option value="">—</option>
                <?php foreach ($supplierTypes as $opt): ?>
                  <option value="<?= htmlspecialchars($opt['option_value']) ?>"><?= htmlspecialchars($opt['option_value']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="btn-sm">Add Supplier</button>
          </form>
        </details>

        <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po">
          <?= Csrf::field() ?>
          <label>Supplier *
            <select name="supplier_id" required>
              <option value="">Select supplier…</option>
              <?php foreach ($suppliers as $sup): ?>
                <option value="<?= (int) $sup['id'] ?>"><?= htmlspecialchars($sup['supplier_legal_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Material / Stone Type<input type="text" name="material_stone_type"></label>
          <label>Grade<input type="text" name="grade" value="Grade A"></label>
          <label>Surface Finish<input type="text" name="surface_finish"></label>
          <label>Dimensions<input type="text" name="dimensions"></label>
          <label>Dimensional Tolerance<input type="text" name="dimensional_tolerance"></label>
          <label>Quantity<input type="text" name="quantity"></label>
          <label>Unit<input type="text" name="unit"></label>
          <label>Colour Reference<input type="text" name="colour_reference"></label>
          <label>Special Requirements<input type="text" name="special_requirements"></label>
          <label>Unit Price (INR)<input type="text" name="unit_price_inr"></label>
          <label>Basic Value (INR)<input type="text" name="basic_value_inr"></label>
          <label>GST Rate (%)<input type="text" name="gst_rate_pct"></label>
          <label>GST Amount (INR)<input type="text" name="gst_amount_inr"></label>
          <label>Total Payable (INR)<input type="text" name="total_payable_inr"></label>
          <label>Advance (%)<input type="text" name="advance_pct"></label>
          <label>Advance Amount (INR)<input type="text" name="advance_amount_inr"></label>
          <label>Balance Amount (INR)<input type="text" name="balance_amount_inr"></label>
          <label>Delivery Location<input type="text" name="delivery_location"></label>
          <label>Required Delivery Date<input type="date" name="required_delivery_date"></label>
          <label>Packing Requirement<input type="text" name="packing_requirement"></label>
          <button type="submit" class="btn-sm">Save Supplier PO Terms</button>
        </form>
      <?php endif; ?>

      <?php if ($supplierPo && $supplierPo['status'] !== 'signed'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/signed">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-success">Confirm Supplier Signed &amp; Returned PO (unlocks Stage 6)</button>
        </form>
      <?php elseif ($supplierPo): ?>
        <p>Supplier has signed and returned the PO.</p>
      <?php endif; ?>

      <?php if ($supplierPo): ?>
        <?php if (!empty($supplierPoDocuments)): ?>
          <table class="list" style="margin-top:8px">
            <tr><th>File</th><th>Uploaded</th><th></th></tr>
            <?php foreach ($supplierPoDocuments as $sd): ?>
              <tr>
                <td><?= htmlspecialchars($sd['original_filename']) ?></td>
                <td><?= htmlspecialchars($sd['uploaded_at']) ?></td>
                <td><a href="/file-store/<?= (int) $sd['file_id'] ?>/download">Download</a></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/documents" enctype="multipart/form-data" style="margin-top:6px">
          <?= Csrf::field() ?>
          <input type="file" name="document" required>
          <button type="submit" class="btn-sm">Attach Supplier PO Acknowledgment</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Stage 6 — Freight Payment<?= $isFob ? ' (skipped — FOB)' : '' ?></h2>
    <?php if ($isFob): ?>
      <p class="muted">FOB order — buyer arranges and pays freight directly. This stage is auto-skipped once Stage 5 passes.</p>
      <?php if ($freightSkipped): ?><p>Confirmed skipped: <?= htmlspecialchars($stage6['skip_reason']) ?></p><?php endif; ?>
    <?php elseif (!$stage6 || $stage6['status'] === 'locked'): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php else: ?>
      <?php if (!$freight): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/freight-terms">
          <?= Csrf::field() ?>
          <label>Confirmed Freight Rate<input type="text" name="confirmed_freight_rate"></label>
          <label>Insurance Amount (CIF only)<input type="text" name="insurance_amount"></label>
          <label>Freight Forwarder Name<input type="text" name="freight_forwarder_name"></label>
          <label>Freight Forwarder Contact<input type="text" name="freight_forwarder_contact"></label>
          <label>GST Treatment
            <select name="gst_treatment">
              <option value="NIL">NIL</option>
              <option value="IGST_18">18% IGST</option>
            </select>
          </label>
          <button type="submit" class="btn-sm">Save Freight Terms</button>
        </form>
      <?php else: ?>
        <div class="kv-grid">
          <div><span class="k">Confirmed Rate</span><span class="v"><?= htmlspecialchars($freight['confirmed_freight_rate'] ?? '—') ?></span></div>
          <div><span class="k">Insurance</span><span class="v"><?= htmlspecialchars($freight['insurance_amount'] ?? 'NIL') ?></span></div>
          <div><span class="k">Forwarder</span><span class="v"><?= htmlspecialchars($freight['freight_forwarder_name'] ?? '—') ?></span></div>
        </div>
        <?php if (!$freightCleared): ?>
          <?php if (!$payment || $payment['freight_remittance_received_at'] === null): ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/freight">
              <?= Csrf::field() ?>
              <label>Freight Amount Received *<input type="text" name="freight_amount" required></label>
              <label>Received On<input type="date" name="freight_received_at" value="<?= date('Y-m-d') ?>"></label>
              <button type="submit" class="btn-sm">Record Freight Remittance</button>
            </form>
          <?php else: ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/freight/clear" onsubmit="return confirm('Mark the freight payment cleared? This unlocks Stage 7 — confirm the funds have actually landed first.');">
              <?= Csrf::field() ?>
              <label>Cleared On<input type="date" name="freight_cleared_at" value="<?= date('Y-m-d') ?>"></label>
              <button type="submit" class="btn-sm btn-success">Mark Freight Cleared (unlocks Stage 7)</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <p>Freight payment cleared.</p>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Stage 7 — Packing &amp; BL Instruction</h2>
    <?php if (!$stage7 || $stage7['status'] === 'locked'): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php else: ?>
      <h3>Packing</h3>
      <?php
        $orderedSummary = \App\Repositories\OrderProductRepository::orderedQuantitySummary((int) $order['id']);
        $shortfallTolerance = \App\Repositories\CompanySettingsRepository::get('quantity_shortfall_tolerance_pct') ?? '5';
      ?>
      <?php if ($orderedSummary['comparable']): ?>
        <p class="muted small">Ordered quantity: <strong><?= htmlspecialchars(rtrim(rtrim(number_format((float) $orderedSummary['total'], 3), '0'), '.')) ?> <?= htmlspecialchars((string) $orderedSummary['unit']) ?></strong> &middot; Shortfall tolerance: <strong><?= htmlspecialchars($shortfallTolerance) ?>%</strong> — the shortfall below is computed automatically from this. A shortfall over tolerance is blocked until the buyer's written approval is uploaded.</p>
      <?php else: ?>
        <p class="muted small">Ordered quantity can't be automatically compared for this order (mixed units across product lines, or quantity not yet confirmed) — enter Shortfall % manually below.</p>
      <?php endif; ?>
      <?php if (!empty($packing['buyer_approval_file_id'])): ?>
        <p class="muted small">&#9989; Buyer's written approval of the quantity shortfall is on file (recorded <?= htmlspecialchars((string) ($packing['shortfall_notice_recorded_at'] ?? '')) ?>).</p>
      <?php endif; ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/packing" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label>Actual Quantity Packed<input type="text" name="actual_quantity_packed" value="<?= htmlspecialchars((string) ($packing['actual_quantity_packed'] ?? '')) ?>"></label>
        <label>Crate Count<input type="text" name="crate_count" value="<?= htmlspecialchars((string) ($packing['crate_count'] ?? '')) ?>"></label>
        <label>Total Net Weight (kg)<input type="text" name="total_net_weight_kg" value="<?= htmlspecialchars((string) ($packing['total_net_weight_kg'] ?? '')) ?>"></label>
        <label>Total Gross Weight (kg)<input type="text" name="total_gross_weight_kg" value="<?= htmlspecialchars((string) ($packing['total_gross_weight_kg'] ?? '')) ?>"></label>
        <label>Total CBM (m&sup3;)<input type="text" name="total_cbm" value="<?= htmlspecialchars((string) ($packing['total_cbm'] ?? '')) ?>"></label>
        <label>Packing Date<input type="date" name="packing_date" value="<?= htmlspecialchars((string) ($packing['packing_date'] ?? '')) ?>"></label>
        <label>Shortfall % <?= $orderedSummary['comparable'] ? '(auto-computed — this field is ignored when the ordered quantity is comparable)' : '' ?><input type="text" name="shortfall_pct" value="<?= htmlspecialchars((string) ($packing['shortfall_pct'] ?? '')) ?>" <?= $orderedSummary['comparable'] ? 'readonly' : '' ?>></label>
        <label>Buyer's Written Approval of Shortfall <small class="muted">(required only if the shortfall exceeds tolerance)</small><input type="file" name="buyer_approval" accept=".pdf,.jpg,.jpeg,.png,.eml,.msg"></label>

        <p class="muted small">Crate-level breakdown (add one row per physical crate):</p>
        <table class="list">
          <tr><th>Crate No.</th><th>Marks &amp; Numbers</th><th>Product</th><th>L×W×H (cm)</th><th>Pcs</th><th>Net (kg)</th><th>Gross (kg)</th><th>CBM</th><th>HS Code</th></tr>
          <?php $existingCrates = $crates ?: [[], [], []]; foreach ($existingCrates as $c): ?>
          <tr>
            <td><input type="text" name="crate_no[]" value="<?= htmlspecialchars($c['crate_no'] ?? '') ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_marks_numbers[]" value="<?= htmlspecialchars($c['marks_numbers'] ?? '') ?>" style="width:180px;"></td>
            <td><input type="text" name="crate_product_description[]" value="<?= htmlspecialchars($c['product_description'] ?? '') ?>" style="width:150px;"></td>
            <td><input type="text" name="crate_dimensions[]" value="<?= htmlspecialchars($c['dimensions_lwh_cm'] ?? '') ?>" style="width:90px;"></td>
            <td><input type="text" name="crate_pcs[]" value="<?= htmlspecialchars((string) ($c['pcs'] ?? '')) ?>" style="width:50px;"></td>
            <td><input type="text" name="crate_net_weight_kg[]" value="<?= htmlspecialchars((string) ($c['net_weight_kg'] ?? '')) ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_gross_weight_kg[]" value="<?= htmlspecialchars((string) ($c['gross_weight_kg'] ?? '')) ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_cbm[]" value="<?= htmlspecialchars((string) ($c['cbm'] ?? '')) ?>" style="width:60px;"></td>
            <td><input type="text" name="crate_hs_code[]" value="<?= htmlspecialchars($c['hs_code'] ?? '') ?>" style="width:70px;"></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <button type="submit" class="btn-sm">Save Packing &amp; Crates</button>
      </form>

      <h3>Shipping / Vessel Details</h3>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/shipping">
        <?= Csrf::field() ?>
        <label>Shipping Line<input type="text" name="shipping_line" value="<?= htmlspecialchars($shipping['shipping_line'] ?? '') ?>"></label>
        <label>Vessel Name<input type="text" name="vessel_name" value="<?= htmlspecialchars($shipping['vessel_name'] ?? '') ?>"></label>
        <label>Voyage Number<input type="text" name="voyage_number" value="<?= htmlspecialchars($shipping['voyage_number'] ?? '') ?>"></label>
        <label>ETD<input type="date" name="etd" value="<?= htmlspecialchars($shipping['etd'] ?? '') ?>"></label>
        <label>ETA<input type="date" name="eta" value="<?= htmlspecialchars($shipping['eta'] ?? '') ?>"></label>
        <label>Container Type<input type="text" name="ship_container_type" value="<?= htmlspecialchars($shipping['container_type'] ?? $order['container_type'] ?? '') ?>"></label>
        <label>Container No.<input type="text" name="container_no" value="<?= htmlspecialchars($shipping['container_no'] ?? '') ?>"></label>
        <label>Seal No.<input type="text" name="seal_no" value="<?= htmlspecialchars($shipping['seal_no'] ?? '') ?>"></label>
        <button type="submit" class="btn-sm">Save Shipping Details</button>
      </form>

      <?php if (!$blIssued): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-issued">
          <?= Csrf::field() ?>
          <label>BL Number *<input type="text" name="bl_number" required></label>
          <label>BL Date<input type="date" name="bl_date" value="<?= date('Y-m-d') ?>"></label>
          <button type="submit" class="btn-sm btn-success">Confirm BL Issued (unlocks Stage 8)</button>
        </form>
      <?php else: ?>
        <p>Bill of Lading recorded: <strong><?= htmlspecialchars($shipping['bl_number']) ?></strong> (<?= htmlspecialchars($shipping['bl_date']) ?>)</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Stage 8 — Commercial Invoice &amp; Balance Payment</h2>
    <?php if (!$stage8 || $stage8['status'] === 'locked'): ?>
      <p class="muted">Confirm BL issuance first to unlock this gate.</p>
    <?php else: ?>
      <?php if ($shipping && !$shipping['scanned_bl_sent_to_buyer_at']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/scanned-bl-sent">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm">Mark Scanned BL Copy Sent to Buyer</button>
        </form>
      <?php elseif ($shipping): ?>
        <p class="muted small">Scanned BL copy sent to buyer.</p>
      <?php endif; ?>

      <?php if (!$balanceCleared): ?>
        <?php if (!$payment || $payment['balance_remittance_received_at'] === null): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/balance">
            <?= Csrf::field() ?>
            <label>Balance Amount Received *<input type="text" name="balance_amount" required value="<?= htmlspecialchars((string) ($payment['balance_amount'] ?? '')) ?>"></label>
            <label>Received On<input type="date" name="balance_received_at" value="<?= date('Y-m-d') ?>"></label>
            <button type="submit" class="btn-sm">Record Balance Remittance</button>
          </form>
        <?php else: ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/balance/clear" onsubmit="return confirm('Mark the balance payment cleared? This unlocks Stage 9 (final closure) — confirm the funds have actually landed first, and that the BL hasn\'t been released early if this is a post-BL preset.');">
            <?= Csrf::field() ?>
            <label>Cleared On<input type="date" name="balance_cleared_at" value="<?= date('Y-m-d') ?>"></label>
            <button type="submit" class="btn-sm btn-success">Mark Balance Cleared (unlocks Stage 9)</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p>Balance payment cleared.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Stage 9 — Document Despatch &amp; Closure</h2>
    <?php if ($orderClosed): ?>
      <p><strong>&#10003; Order closed.</strong> Courier tracking: <?= htmlspecialchars($shipping['courier_tracking_number'] ?? '—') ?></p>
    <?php elseif (!$stage9 || $stage9['status'] === 'locked'): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php else: ?>
      <?php if (!$shipping || !$shipping['bl_originals_received_at']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-originals-received">
          <?= Csrf::field() ?>
          <label>No. of Original BL Copies Received<input type="number" name="bl_originals_count" value="3"></label>
          <button type="submit" class="btn-sm">Record Original BLs Received from CHA</button>
        </form>
      <?php else: ?>
        <p class="muted small">Original BLs received (<?= (int) $shipping['bl_originals_received_count'] ?>).</p>
      <?php endif; ?>

      <?php if ($shipping && $shipping['bl_originals_received_at'] && !$shipping['bl_endorsed_at']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsed">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm">Mark Original BLs Endorsed by NexaCrest</button>
        </form>
      <?php elseif ($shipping && $shipping['bl_endorsed_at']): ?>
        <p class="muted small">Original BLs endorsed.</p>
      <?php endif; ?>

      <form method="post" action="/orders/<?= (int) $order['id'] ?>/close">
        <?= Csrf::field() ?>
        <label>Courier Tracking Number *<input type="text" name="courier_tracking_number" required></label>
        <button type="submit" class="btn-sm btn-success">Close Order — Complete Document Set Couriered to Buyer</button>
      </form>
    <?php endif; ?>
  </div>
</div>
