<?php
use App\Helpers\Csrf;
use App\Repositories\AssociateDocumentRepository;

$__m = static fn ($v): string => number_format((float) $v, 2);
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
?>
<div class="card page-wide">
  <h1>My commission</h1>
  <?php if (!$print): ?><p><a class="btn-sm btn-secondary" href="/associate/commission?format=csv">Statement (CSV)</a> <a class="btn-sm btn-secondary" href="/associate/commission?print=1" target="_blank" rel="noopener">Printable statement</a></p><?php endif; ?>
  <p>Waiting to be paid: <b>₹<?= $__m($totals['waiting']) ?></b> · Paid this year: <b>₹<?= $__m($totals['paid_year']) ?></b><?= $totals['held'] > 0 ? ' · On hold: <b>₹' . $__m($totals['held']) . '</b>' : '' ?></p>
  <p class="muted small">Each commission is paid in the incentive month shown, against your invoice. Upload your invoice for each line below. Payments go only to your verified bank account.</p>
  <table class="list">
    <tr><th>Month</th><th>Order</th><th>Client</th><th>Amount</th><th>Status</th><th>Paid</th><?php if (!$print): ?><th>Your invoice</th><?php endif; ?></tr>
    <?php foreach ($payouts as $p): $inv = AssociateDocumentRepository::invoiceForPayout((int) $p['id']); ?>
      <tr><td><?= htmlspecialchars(\App\Services\IncentiveService::monthLabel((string) $p['incentive_month'])) ?></td><td><?= htmlspecialchars((string) $p['order_reference']) ?><?= $p['note'] ? '<br><small class="muted">' . htmlspecialchars((string) $p['note']) . '</small>' : '' ?></td><td><?= htmlspecialchars((string) $p['company_legal_name']) ?></td>
        <td><?= $p['amount_fc'] !== null ? htmlspecialchars((string) $p['currency_code']) . ' ' . $__m($p['amount_fc']) . '<br>' : '' ?><small>₹<?= $__m($p['amount_inr']) ?></small></td>
        <td><?= htmlspecialchars(['due' => (float) $p['amount_inr'] < 0 ? 'To be deducted' : 'Waiting', 'paid' => 'Paid', 'held' => 'On hold'][$p['status']] ?? $p['status']) ?><?= $p['hold_reason'] ? '<br><small>' . htmlspecialchars((string) $p['hold_reason']) . '</small>' : '' ?></td>
        <td><?= $p['status'] === 'paid' ? $__d($p['paid_on']) . ((float) $p['tds_inr'] > 0
            ? '<br><small data-tds-breakdown>Gross ₹' . $__m((float) $p['paid_inr'] + (float) $p['tds_inr']) . ' · TDS ₹' . $__m($p['tds_inr'])
                . (!empty($p['tds_section']) ? ' (u/s ' . htmlspecialchars((string) $p['tds_section']) . (!empty($p['tds_rate']) ? ' at ' . \App\Services\AssociateTds::pct((float) $p['tds_rate']) : '') . ')' : '') . '</small><br>Net paid ₹' . $__m($p['paid_inr'])
            : ' · ₹' . $__m($p['paid_inr'])) . ($p['paid_reference'] ? '<br><small>Ref ' . htmlspecialchars((string) $p['paid_reference']) . '</small>' : '') : '—' ?></td>
        <?php if (!$print): ?><td>
          <?php if ($inv): ?><a href="/associate/documents/<?= (int) $inv['id'] ?>"><?= htmlspecialchars((string) ($inv['reference'] ?? 'Invoice')) ?></a>
          <?php elseif ((float) $p['amount_inr'] > 0 && $p['status'] !== 'paid'): ?>
            <form method="post" action="/associate/commission/<?= (int) $p['id'] ?>/invoice" enctype="multipart/form-data" class="inline-form"><?= Csrf::field() ?>
              <input type="text" name="invoice_no" required maxlength="100" placeholder="Invoice no." aria-label="Invoice number">
              <input type="file" name="invoice_file" required accept=".pdf,.jpg,.jpeg,.png" aria-label="Invoice file"><button type="submit" class="btn-sm">Upload</button></form>
          <?php else: ?>—<?php endif; ?>
        </td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if ($payouts === []): ?><tr><td colspan="7" class="muted">No commission yet. It is earned once a buyer has paid an order in full.</td></tr><?php endif; ?>
  </table>
  <?php if (!$print): ?>
  <h2>TDS certificates (Form 16A) and remittance forms from NexaCrest</h2>
  <p class="small muted">NexaCrest deducts TDS on commission as the law requires and issues your Form 16A each quarter; it appears here.</p>
  <table class="list">
    <?php foreach (array_filter($documents, static fn (array $d): bool => in_array($d['kind'], ['form_16a', 'form_15ca', 'form_15cb'], true)) as $d): ?>
      <tr><td><?= $__d($d['created_at']) ?></td><td><a href="/associate/documents/<?= (int) $d['id'] ?>"><?= htmlspecialchars(AssociateDocumentRepository::KINDS[$d['kind']]) ?></a></td><td><?= htmlspecialchars((string) ($d['reference'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><script>window.addEventListener('load', () => window.print());</script><?php endif; ?>
</div>
