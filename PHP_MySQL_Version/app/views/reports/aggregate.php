<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Aggregate Report</h1>

  <form method="get" action="/reports/aggregate" class="section">
    <div class="kv-grid">
      <label>Date From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Date To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
      <label>Stage
        <select name="stage_id">
          <option value="">— any —</option>
          <?php foreach ($stages as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= ((int) ($filters['stageId'] ?? 0) === (int) $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['stage_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Incoterm
        <select name="incoterm_id">
          <option value="">— any —</option>
          <?php foreach ($incoterms as $i): ?>
            <option value="<?= (int) $i['id'] ?>" <?= ((int) ($filters['incotermId'] ?? 0) === (int) $i['id']) ? 'selected' : '' ?>><?= htmlspecialchars($i['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Country
        <select name="country">
          <option value="">— any —</option>
          <?php foreach ($countries as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= ($filters['country'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/aggregate?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null, 'stage_id' => $filters['stageId'] ?? null, 'incoterm_id' => $filters['incotermId'] ?? null, 'country' => $filters['country'] ?? null]))) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <h2>Summary</h2>
    <p class="muted small">Computed from exactly the <?= count($rows) ?> order(s) listed below — never a separate count, so this can never disagree with the table.</p>
    <div class="kv-grid">
      <div><span class="k">Total Orders</span><span class="v"><?= (int) $summary['total_orders'] ?></span></div>
      <div>
        <span class="k">Total FOB Value</span>
        <span class="v">
          <?php if (empty($summary['fob_by_currency'])): ?>—<?php else: ?>
            <?php foreach ($summary['fob_by_currency'] as $cc => $amt): ?>
              <?= htmlspecialchars($cc) ?> <?= number_format($amt, 2) ?><br>
            <?php endforeach; ?>
          <?php endif; ?>
        </span>
      </div>
    </div>
    <div class="kv-grid" style="margin-top:0.75rem;">
      <div>
        <span class="k">By Stage</span>
        <span class="v"><?php foreach ($summary['by_stage'] as $stage => $n): ?><?= htmlspecialchars($stage) ?>: <?= (int) $n ?><br><?php endforeach; ?><?= empty($summary['by_stage']) ? '—' : '' ?></span>
      </div>
      <div>
        <span class="k">By Status</span>
        <span class="v"><?php foreach ($summary['by_status'] as $status => $n): ?><?= htmlspecialchars(ucfirst($status)) ?>: <?= (int) $n ?><br><?php endforeach; ?><?= empty($summary['by_status']) ? '—' : '' ?></span>
      </div>
      <div>
        <span class="k">By Country</span>
        <span class="v"><?php foreach ($summary['by_country'] as $country2 => $n): ?><?= htmlspecialchars($country2) ?>: <?= (int) $n ?><br><?php endforeach; ?><?= empty($summary['by_country']) ? '—' : '' ?></span>
      </div>
    </div>
  </div>

  <?php if ($canManageDefinitions): ?>
  <form method="post" action="/reports/save" class="section">
    <?= Csrf::field() ?>
    <input type="hidden" name="report_type" value="aggregate">
    <input type="hidden" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>">
    <input type="hidden" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>">
    <input type="hidden" name="stage_id" value="<?= htmlspecialchars((string) ($filters['stageId'] ?? '')) ?>">
    <input type="hidden" name="incoterm_id" value="<?= htmlspecialchars((string) ($filters['incotermId'] ?? '')) ?>">
    <input type="hidden" name="country" value="<?= htmlspecialchars($filters['country'] ?? '') ?>">
    <label style="display:inline-block;width:auto;margin-right:0.5rem;">Save these filters as <input type="text" name="name" placeholder="Report name" style="display:inline-block;width:220px;"></label>
    <label style="display:inline-block;width:auto;margin-right:0.5rem;">
      <select name="visibility" style="display:inline-block;width:auto;">
        <option value="private">Private</option>
        <option value="shared">Shared</option>
      </select>
    </label>
    <button type="submit" class="btn-sm btn-secondary">Save Report</button>
  </form>
  <?php endif; ?>

  <table class="list">
    <tr><th>Order Ref</th><th>Client</th><th>Country</th><th>Incoterm</th><th>Currency</th><th>Stage</th><th>Status</th><th>Total FOB</th><th>Created</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['order_reference']) ?></td>
      <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
      <td><?= htmlspecialchars($r['country_of_destination'] ?? '—') ?></td>
      <td><?= htmlspecialchars($r['incoterm_code']) ?></td>
      <td><?= htmlspecialchars($r['currency_code']) ?></td>
      <td><?= htmlspecialchars($r['current_stage_name'] ?? 'Quotation') ?></td>
      <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
      <td><?= number_format((float) $r['total_fob_value'], 2) ?></td>
      <td><?= htmlspecialchars($r['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="9" class="muted">No orders match these filters.</td></tr><?php endif; ?>
  </table>
</div>
