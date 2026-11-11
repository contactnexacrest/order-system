<?php
use App\Helpers\Csrf;
use App\Repositories\AssociateDocumentRepository;

$__m = static fn ($v): string => number_format((float) $v, 2);
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
?>
<div class="card page-wide">
  <h1>My commission</h1>
  <?php if (!empty($access['commission'])): // D-261 ?>
  <?php if (!$print && (int) ($me['no_commission'] ?? 0) !== 1) { require __DIR__ . '/_my_commission.php'; } // D-292 ?>
  <?php if (!$print && !empty($access['commission_statement'])): ?><p><a class="btn-sm btn-secondary" href="/associate/commission?format=csv">Statement (CSV)</a> <a class="btn-sm btn-secondary" href="/associate/commission?print=1" target="_blank" rel="noopener">Printable statement</a></p><?php endif; ?>
  <?php // D-264: the order currency is the figure every associate sees; rupees only for an associate in India, once fixed. ?>
  <?php $__india = \App\Services\AssociatePayoutService::isIndia($me ?? null); $__box = \App\Services\AssociatePayoutService::howFixedBox($me ?? null); $__fc = static fn (string $k): string => htmlspecialchars(\App\Repositories\AssociatePortalRepository::fcText($totals['fc'][$k] ?? [])); ?>
  <?php if (!$print): ?><div class="alert alert-info" data-how-fixed><b><?= htmlspecialchars($__box['title']) ?></b><br><?= htmlspecialchars($__box['text']) ?></div><?php endif; ?>
  <p>Earned in the last <?= (int) $totals['months'] ?> months: <b><?= $__fc('earned') ?></b> · Paid in the last <?= (int) $totals['months'] ?> months: <b><?= $__fc('paid') ?></b> · Waiting to be paid: <b><?= $__fc('waiting') ?></b><?= $totals['held'] > 0 ? ' · On hold: <b>' . $__fc('held') . '</b>' : '' ?></p>
  <p class="muted small" data-commission-period>This shows the commission of the last <?= (int) $totals['months'] ?> months, and any commission still waiting to be paid.</p>
  <p class="muted small">Each commission is paid in the incentive month shown, against your invoice. Upload your invoice for each line below. Payments go only to your verified bank account.</p>
  <table class="list">
    <?php $__pctCol = !empty($access['commission_pct']); // D-292: the % fixed on each order (with "Show commission %") ?>
    <tr><th>Month</th><th>Order</th><th>Client</th><?php if ($__pctCol): ?><th>Your %</th><?php endif; ?><th>Amount</th><th>Status</th><th>Paid</th><?php if (!$print): ?><th>Your invoice</th><?php endif; ?></tr>
    <?php foreach ($payouts as $p): $inv = AssociateDocumentRepository::invoiceForPayout((int) $p['id']); ?>
      <tr><td><?= htmlspecialchars(\App\Services\IncentiveService::monthLabel((string) $p['incentive_month'])) ?></td><td><?= htmlspecialchars((string) $p['order_reference']) ?><?= $p['note'] ? '<br><small class="muted">' . htmlspecialchars((string) $p['note']) . '</small>' : '' ?></td><td><?= htmlspecialchars((string) $p['company_legal_name']) ?></td>
        <?php if ($__pctCol): ?><td data-order-pct><?= htmlspecialchars(\App\Repositories\AssociatePortalRepository::orderRateText($p) ?? '—') ?></td><?php endif; ?>
        <td><?= $p['amount_fc'] !== null ? htmlspecialchars((string) $p['currency_code']) . ' ' . $__m($p['amount_fc']) : '' ?><?= $p['fixed_rate'] !== null ? ' <span class="badge badge-active">Fixed</span>' : '' ?>
          <?php if ($__india && $p['fixed_rate'] !== null && $p['status'] !== 'paid' && (float) $p['amount_inr'] > 0): ?><br><small data-fixed-rupees><?= htmlspecialchars(\App\Services\AssociatePayoutService::fixedRupeeLine((float) $p['amount_inr'], (float) $p['fixed_rate'], (string) $p['currency_code'], (string) $p['fixed_on'])) ?></small><?php elseif ($__india && $p['fixed_rate'] === null): ?><br><small>₹<?= $__m($p['amount_inr']) ?></small><?php endif; ?></td>
        <td><?= htmlspecialchars((float) $p['amount_inr'] < 0 && $p['status'] === 'due' ? 'To be deducted' : \App\Repositories\AssociatePortalRepository::payoutLabel($p, false, (string) $p['incentive_month'])) ?></td>
        <td><?= $p['status'] === 'paid' && !$__india ? $__d($p['paid_on']) . ' · ' . htmlspecialchars((string) $p['currency_code']) . ' ' . $__m($p['amount_fc']) . ' sent' . ($p['paid_reference'] ? '<br><small>Ref ' . htmlspecialchars((string) $p['paid_reference']) . '</small>' : '') : ($p['status'] === 'paid' ? $__d($p['paid_on']) . ((float) $p['tds_inr'] > 0
            ? '<br><small data-tds-breakdown>Gross ₹' . $__m((float) $p['paid_inr'] + (float) $p['tds_inr']) . ' · TDS ₹' . $__m($p['tds_inr'])
                . (!empty($p['tds_section']) ? ' (u/s ' . htmlspecialchars((string) $p['tds_section']) . (!empty($p['tds_rate']) ? ' at ' . \App\Services\AssociateTds::pct((float) $p['tds_rate']) : '') . ')' : '') . '</small><br>Net paid ₹' . $__m($p['paid_inr'])
            : ' · ₹' . $__m($p['paid_inr'])) . ($p['paid_reference'] ? '<br><small>Ref ' . htmlspecialchars((string) $p['paid_reference']) . '</small>' : '') : '—') ?></td>
        <?php if (!$print): ?><td>
          <?php if ($inv): ?><a href="/associate/documents/<?= (int) $inv['id'] ?>"><?= htmlspecialchars((string) ($inv['reference'] ?? 'Invoice')) ?></a>
          <?php elseif ((float) $p['amount_inr'] > 0 && $p['status'] !== 'paid'): ?>
            <form method="post" action="/associate/commission/<?= (int) $p['id'] ?>/invoice" enctype="multipart/form-data" class="inline-form"><?= Csrf::field() ?>
              <input type="text" name="invoice_no" required maxlength="100" placeholder="Invoice no." aria-label="Invoice number">
              <input type="file" name="invoice_file" required accept=".pdf,.jpg,.jpeg,.png" aria-label="Invoice file"><button type="submit" class="btn-sm">Upload</button></form>
          <?php else: ?>—<?php endif; ?>
        </td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if ($payouts === []): ?><tr><td colspan="8" class="muted">No commission yet. It is earned once a buyer has paid an order in full.</td></tr><?php endif; ?>
  </table>
  <?php if ($__pctCol): ?><p class="muted small">Each order shows the % it was given when its Proforma Invoice was sent. Orders still being paid by the buyer are listed under Orders as "Not earned yet — buyer still paying".</p><?php endif; ?>
  <?php endif; ?>
  <?php if (!$print && !empty($access['tds_certificates'])): ?>
  <h2>TDS certificates (Form 16A) and remittance forms from NexaCrest</h2>
  <p class="small muted">NexaCrest deducts TDS on commission as the law requires and issues your Form 16A each quarter; it appears here.</p>
  <table class="list">
    <?php foreach (array_filter($documents, static fn (array $d): bool => in_array($d['kind'], ['form_16a', 'form_15ca', 'form_15cb'], true)) as $d): ?>
      <tr><td><?= $__d($d['created_at']) ?></td><td><a href="/associate/documents/<?= (int) $d['id'] ?>"><?= htmlspecialchars(AssociateDocumentRepository::KINDS[$d['kind']]) ?></a></td><td><?= htmlspecialchars((string) ($d['reference'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php elseif ($print): ?><script>window.addEventListener('load', () => window.print());</script><?php endif; ?>
</div>
