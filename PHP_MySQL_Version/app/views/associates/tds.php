<?php
use App\Helpers\Csrf;

/**
 * Owner decision 2 Nov 2026 (D-235): TDS on associate commission, by
 * financial-year quarter, with the challan Accounts deposited it with.
 * @var list<array> $rows, string $fyStart, string $fyLabel, ?int $quarter, bool $canEditChallan
 */
$__gross = 0.0;
$__tds = 0.0;
foreach ($rows as $r) {
    $__gross += (float) $r['paid_inr'] + (float) ($r['tds_inr'] ?? 0);
    $__tds += (float) ($r['tds_inr'] ?? 0);
}
$__fyYear = (int) substr($fyStart, 0, 4);
?>
<div class="card">
  <h1>TDS on associate commission</h1>
  <p class="muted"><a href="/associates">&larr; Associates</a> &middot; <a href="/associates/report">Associate report</a></p>
  <form method="get" action="/associates/tds" class="btn-row">
    <label>Financial year
      <select name="fy" aria-label="Financial year">
        <?php for ($y = (int) date('Y') + 1; $y >= 2025; $y--): $v = sprintf('%04d-04-01', $y - 1); ?>
          <option value="<?= $v ?>"<?= $v === $fyStart ? ' selected' : '' ?>>FY <?= $y - 1 ?>-<?= substr((string) $y, 2) ?></option>
        <?php endfor; ?>
      </select></label>
    <label>Quarter
      <select name="q" aria-label="Quarter">
        <option value="">Whole year</option>
        <?php foreach ([1 => 'Q1 Apr–Jun', 2 => 'Q2 Jul–Sep', 3 => 'Q3 Oct–Dec', 4 => 'Q4 Jan–Mar'] as $qk => $ql): ?>
          <option value="<?= $qk ?>"<?= $quarter === $qk ? ' selected' : '' ?>><?= $ql ?></option>
        <?php endforeach; ?>
      </select></label>
    <button type="submit" class="btn-sm">Show</button>
    <a class="btn-sm" href="/associates/tds?fy=<?= rawurlencode($fyStart) ?><?= $quarter !== null ? '&amp;q=' . $quarter : '' ?>&amp;format=csv">Download (CSV)</a>
  </form>
  <p class="small muted">Deposit each month's TDS by the 7th of the next month (challan 281) and enter the challan here; file Form 26Q after each quarter (by 31 Jul, 31 Oct, 31 Jan, 31 May) and upload each associate's Form 16A on their Documents tab — they see it in their portal. Values from Settings &rarr; Associate TDS.</p>
  <table class="list">
    <tr><th>Qtr</th><th>Paid on</th><th>Associate</th><th>PAN</th><th>Order</th><th>Gross</th><th>TDS</th><th>Section · rate</th><th>Net paid</th><th>Reason</th><th>Challan</th><th>Form 16A</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td>Q<?= (int) $r['quarter'] ?></td>
        <td><?= htmlspecialchars(\App\Helpers\Dates::short((string) $r['paid_on'])) ?></td>
        <td><a href="/associates/<?= (int) $r['associate_id'] ?>"><?= htmlspecialchars((string) $r['name']) ?></a><?= $r['based_in'] !== 'india' ? ' <small class="muted">(abroad, ' . htmlspecialchars((string) $r['country']) . ')</small>' : '' ?></td>
        <td><?= htmlspecialchars((string) ($r['pan'] ?: '—')) ?></td>
        <td><?= htmlspecialchars((string) ($r['buyer_inquiry_ref'] ?: $r['order_reference'])) ?></td>
        <td>&#8377;<?= number_format((float) $r['paid_inr'] + (float) ($r['tds_inr'] ?? 0), 2) ?></td>
        <td>&#8377;<?= number_format((float) ($r['tds_inr'] ?? 0), 2) ?></td>
        <td><?= htmlspecialchars(trim((string) ($r['tds_section'] ?? '') . ((float) ($r['tds_rate'] ?? 0) > 0 ? ' · ' . \App\Services\AssociateTds::pct((float) $r['tds_rate']) : ''), ' ·') ?: '—') ?></td>
        <td>&#8377;<?= number_format((float) $r['paid_inr'], 2) ?></td>
        <td class="small"><?= htmlspecialchars((string) ($r['tds_reason'] ?? '—')) ?></td>
        <td>
          <?php if ((float) ($r['tds_inr'] ?? 0) <= 0): ?>
            <span class="muted">—</span>
          <?php elseif ($canEditChallan): ?>
            <form method="post" action="/associates/tds/<?= (int) $r['id'] ?>/challan" style="display:flex;gap:4px;flex-wrap:wrap">
              <?= Csrf::field() ?>
              <input type="hidden" name="fy" value="<?= htmlspecialchars($fyStart) ?>">
              <input type="text" name="tds_challan_no" required minlength="5" maxlength="40" pattern="[0-9A-Za-z\/ \-]+" value="<?= htmlspecialchars((string) ($r['tds_challan_no'] ?? '')) ?>" placeholder="e.g. 0510308 07112026 00045" aria-label="Challan no. (BSR code, date, serial)" style="width:12em">
              <input type="date" name="tds_challan_date" required min="<?= htmlspecialchars((string) $r['paid_on']) ?>" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars((string) ($r['tds_challan_date'] ?? '')) ?>" aria-label="Challan date">
              <button type="submit" class="btn-sm">Save</button>
            </form>
          <?php else: ?>
            <?= htmlspecialchars((string) ($r['tds_challan_no'] ?? 'not yet deposited')) ?>
          <?php endif; ?>
        </td>
        <td><?= (int) $r['form16a_count'] > 0 ? '<span class="badge badge-success">Uploaded</span>' : ((float) ($r['tds_inr'] ?? 0) > 0 ? '<span class="badge badge-warning">Not yet</span>' : '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="12" class="muted">No associate commission paid in <?= htmlspecialchars($fyLabel) ?><?= $quarter !== null ? ' Q' . $quarter : '' ?>.</td></tr><?php endif; ?>
  </table>
  <p><strong>Total:</strong> gross &#8377;<?= number_format($__gross, 2) ?> &middot; TDS &#8377;<?= number_format($__tds, 2) ?> &middot; net paid &#8377;<?= number_format($__gross - $__tds, 2) ?></p>
</div>
