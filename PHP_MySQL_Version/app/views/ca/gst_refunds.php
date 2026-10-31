<?php $__canEdit = \App\Helpers\PermittedLinks::currentUserHas('inr_actual_edit'); ?>
<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>GST refunds (LUT)</h1>
  <p class="muted">NexaCrest exports under LUT and sells nothing in India, so the GST paid on purchases comes back as a refund, claimed for a whole period (form RFD-01), not order by order. Each order's cost keeps its GST (it left the bank). Record each claim here, then what the bank actually credited. A credit goes to Zoho Books at the next sync (hourly, or Sync Now) as a journal: the bank account received, <em>GST Refund Receivable</em> reduced.</p>

  <div class="kv-grid">
    <div><span class="k">Claimed</span><span class="v">&#8377;<?= number_format($totals['claimed'], 2) ?></span></div>
    <div><span class="k">Credited to the bank</span><span class="v">&#8377;<?= number_format($totals['credited'], 2) ?></span></div>
    <div><span class="k">Refused</span><span class="v">&#8377;<?= number_format($totals['refused'], 2) ?></span></div>
    <div><span class="k">Still to come back</span><span class="v">&#8377;<?= number_format($totals['outstanding'], 2) ?></span></div>
  </div>

  <div class="section">
    <h2>Claims</h2>
    <?php if ($claims === []): ?>
      <p class="muted">No claim recorded yet.</p>
    <?php else: ?>
    <table class="list">
      <tr><th>Period</th><th>RFD-01 claim no.</th><th>Filed</th><th>GST on bills in the period</th><th>Claimed</th><th>Credited</th><th>Refused</th><th>Status</th></tr>
      <?php foreach ($claims as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['period_label']) ?></td>
        <td><?= htmlspecialchars((string) $c['claim_arn']) ?></td>
        <td><?= htmlspecialchars(\App\Helpers\Dates::short((string) $c['filed_on'])) ?></td>
        <td>&#8377;<?= number_format((float) $c['gst_on_bills'], 2) ?></td>
        <td>&#8377;<?= number_format((float) $c['amount_claimed'], 2) ?></td>
        <td>&#8377;<?= number_format((float) $c['amount_credited'], 2) ?>
          <?php foreach ($c['credit_rows'] as $r): ?>
            <br><small class="muted"><?= htmlspecialchars(\App\Helpers\Dates::short((string) $r['credited_on'])) ?> · <?= htmlspecialchars($parts[$r['part']] ?? $r['part']) ?> · &#8377;<?= number_format((float) $r['amount'], 2) ?> · bank ref <?= htmlspecialchars((string) $r['bank_reference']) ?> · <?= $r['zoho_journal_id'] ? 'in Zoho Books (journal ' . htmlspecialchars((string) $r['zoho_journal_id']) . ')' : 'goes to Zoho Books at the next sync' ?></small>
            <?php if (!$r['zoho_journal_id'] && $__canEdit): ?>
              <form method="post" action="/ca/gst-refunds/credits/<?= (int) $r['id'] ?>/delete" style="display:inline" data-confirm="Remove this refund credit? Record it again with the right figures."><?= \App\Helpers\Csrf::field() ?><button type="submit" class="btn-link small">Remove</button></form>
            <?php endif; ?>
          <?php endforeach; ?>
        </td>
        <td><?= (float) $c['amount_refused'] > 0 ? '&#8377;' . number_format((float) $c['amount_refused'], 2) . '<br><small class="muted">' . htmlspecialchars((string) $c['refused_note']) . '</small>' : '—' ?></td>
        <td><?php if ($c['outstanding'] > 0): ?><span class="badge badge-warning"><?= (float) $c['amount_credited'] > 0 ? 'Balance pending' : 'Claimed' ?></span><br><small class="muted">&#8377;<?= number_format($c['outstanding'], 2) ?> to come</small><?php else: ?><span class="badge badge-success">Closed</span><?php endif; ?></td>
      </tr>
      <?php if ($__canEdit && $c['outstanding'] > 0): ?>
      <tr>
        <td colspan="8">
          <details>
            <summary class="small"><strong>Record money credited to the bank</strong> for claim <?= htmlspecialchars((string) $c['claim_arn']) ?></summary>
            <form method="post" action="/ca/gst-refunds/<?= (int) $c['id'] ?>/credits">
              <?= \App\Helpers\Csrf::field() ?>
              <div class="field-grid">
                <label>Amount credited (&#8377;) *<input type="number" name="amount" required min="0.01" max="<?= number_format($c['outstanding'], 2, '.', '') ?>" step="0.01"></label>
                <label>Credited on *<input type="date" name="credited_on" required min="<?= htmlspecialchars((string) $c['filed_on']) ?>" max="<?= date('Y-m-d') ?>"></label>
                <label>Bank reference (RFD-05 / UTR) *<input type="text" name="bank_reference" required minlength="4" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9 ./_#:()\-]*" placeholder="as on the bank statement"></label>
                <label>Part *
                  <select name="part" required><option value="">Choose…</option><?php foreach ($parts as $__k => $__v): ?><option value="<?= $__k ?>"><?= htmlspecialchars(ucfirst($__v)) ?></option><?php endforeach; ?></select>
                </label>
                <label>Amount refused, if any (&#8377;)<input type="number" name="amount_refused" min="0" step="0.01"><small class="muted">Refused GST is a real cost — the CA books it in Zoho Books by hand.</small></label>
              </div>
              <button type="submit" class="btn-sm">Record credit</button>
            </form>
          </details>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </table>
    <p class="muted small">Each credit goes to Zoho Books with a description built from its facts (rule D-212), e.g. <em>GST refund under LUT, Oct–Dec 2026, claim AA2701260012345 · provisional · ₹185,400.00 credited 12 Feb 2027 · bank ref RFD05-AA270226004411</em>.</p>
    <?php endif; ?>
  </div>

  <?php if ($__canEdit): ?>
  <div class="section">
    <h2>Record a claim</h2>
    <form method="post" action="/ca/gst-refunds">
      <?= \App\Helpers\Csrf::field() ?>
      <div class="field-grid">
        <label>Period from *<input type="date" name="period_from" required></label>
        <label>Period to *<input type="date" name="period_to" required data-not-before="[name=period_from]" data-not-before-message="The period cannot end before it starts."></label>
        <label>RFD-01 claim no. (ARN) *<input type="text" name="claim_arn" required minlength="6" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9\-]*" placeholder="as on the GST portal"></label>
        <label>Filed on *<input type="date" name="filed_on" required max="<?= date('Y-m-d') ?>"></label>
        <label>Amount claimed (&#8377;) *<input type="number" name="amount_claimed" required min="0.01" step="0.01"></label>
      </div>
      <button type="submit" class="btn-sm">Save claim</button>
    </form>
  </div>
  <?php endif; ?>
</div>
