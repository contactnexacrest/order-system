<?php
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Helpers\MoneyFormat;
use App\Helpers\View;

/**
 * Owner decision D-378 (15 Nov 2026): the Targets page (manage_sales_targets). Containers for
 * everyone who opens it; ₹ figures only with view_price_buildup as well ($canMoney).
 * The calculator (#target-calculator) runs in the browser only: its inputs have NO name and sit
 * outside every form, so nothing about a salary is ever posted. "Use this" copies the result into
 * the chosen person's containers field of their edit form below.
 * Node twin: NODE_MySQL_Version/app/views/sales_targets/index.njk
 */
$period = $data['period'];
$company = $data['company'];
$settings = $data['settings'];
$__num = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
$__signed = static fn (int $n): string => ($n > 0 ? '+' : '') . $n;
$__order = static function (int $id) use ($data): string {
    $o = $data['credited_orders'][$id] ?? null;
    return $o === null ? '#' . $id : '<a href="/orders/' . $id . '">' . View::e((string) $o['order_reference']) . '</a>';
};
?>
<div class="card page-wide" id="sales-targets">
  <p class="muted small"><a href="/sales-targets/settings">Sales targets settings</a></p>
  <h1>Sales targets — <?= View::e($period['label']) ?></h1>
  <p class="muted">An order counts for its salesperson on the day it is confirmed (advance received, LC received, or — with no advance — buyer PO received and PI approved), with its estimated containers. Cancellations, losses and a different number of containers shipped are corrections on the day they happen; earlier periods never change.</p>
  <div class="btn-row" id="target-periods">
    <?php foreach ($periods as $p): ?>
      <a class="btn-sm <?= $p['key'] === $period['key'] ? 'btn-accent' : 'btn-secondary' ?>" href="/sales-targets?period=<?= View::e($p['key']) ?>"><?= View::e($p['label']) ?></a>
    <?php endforeach; ?>
  </div>

  <h2>Company</h2>
  <?php if (!$company['on']): ?>
    <p class="muted">No company target for this period (Sales targets settings).</p>
  <?php else: ?>
    <div class="kv-grid">
      <div><span class="k">Containers needed</span><span class="v"><?= $company['containers_needed'] !== null ? (int) $company['containers_needed'] : 'not enough history yet' ?></span></div>
      <div><span class="k">Break-even containers</span><span class="v"><?= $company['breakeven_containers'] !== null ? (int) $company['breakeven_containers'] : '—' ?></span></div>
      <div><span class="k">Counted this period</span><span class="v"><?= (int) $company['counted'] ?></span></div>
      <div><span class="k">Shipped · awaiting shipment</span><span class="v"><?= (int) $company['shipped'] ?> · <?= (int) $company['awaiting'] ?></span></div>
      <?php if ($canMoney): ?>
      <div><span class="k">Needed (₹)</span><span class="v"><?= View::e(MoneyFormat::indian($company['needed'])) ?></span></div>
      <div><span class="k">Earned — profit after incentive of orders shipped (₹)</span><span class="v"><?= View::e(MoneyFormat::indian($company['earned'])) ?></span></div>
      <div><span class="k">Average margin per container, last 3 months (₹)</span><span class="v"><?= $company['avg_margin'] !== null ? View::e(MoneyFormat::indian($company['avg_margin'])) : 'not enough history yet' ?></span></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h2>Team</h2>
  <div class="table-scroll">
  <table class="list" id="target-team">
    <tr><th>Person</th><th>Designation</th><th>Multiple (own)</th><th>Target</th><th>Confirmed</th><th>Shipped</th><th>Pipeline</th><th>New buyers</th><th>Progress</th><th>Last reviewed</th><th></th></tr>
    <?php foreach ($data['rows'] as $r): $t = $r['target']; $c = $r['counts']; $uid = (int) $r['user']['id']; ?>
    <tr data-user-id="<?= $uid ?>">
      <td><?= View::e((string) $r['user']['name']) ?></td>
      <td><?= View::e($t['designation']['title'] ?? '—') ?></td>
      <td><?= $t['own_multiple'] !== null ? View::e($__num($t['own_multiple'])) : '<span class="muted">' . View::e($__num($t['multiple'])) . '</span>' ?></td>
      <td><?= $t['containers'] > 0 ? View::e($__num($t['containers'])) : '—' ?><?php if ($t['in_buildup']): ?> <span class="badge badge-warning" title="Build-up: <?= View::e((string) round($t['factor'] * 100)) ?>% of <?= View::e($__num($t['base_containers'] ?? 0)) ?>">build-up</span><?php endif; ?><?= $t['source'] === 'designation' ? ' <span class="muted small">(designation)</span>' : '' ?></td>
      <td><?= (int) $c['counted'] ?><?= $c['corrections'] !== 0 ? ' <span class="muted small">(' . View::e($__signed($c['corrections'])) . ' corrections)</span>' : '' ?></td>
      <td><?= (int) $c['shipped'] ?></td>
      <td><?= (int) $r['pipeline'] ?></td>
      <td><?= (int) $c['new_buyers'] ?><?= $t['new_buyers'] !== null ? ' / ' . (int) $t['new_buyers'] : '' ?></td>
      <td><?= $r['progress'] !== null ? (int) $r['progress'] . '%' : '—' ?></td>
      <td><?= $t['reviewed_at'] !== null ? View::e(Dates::short((string) $t['reviewed_at'])) : '<span class="muted">never</span>' ?></td>
      <td><a href="#edit-target-<?= $uid ?>" class="btn-sm btn-secondary" onclick="var d=document.getElementById('edit-target-<?= $uid ?>'); if (d) { d.open = true; }">Edit</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($data['rows'] === []): ?><tr><td colspan="11" class="muted">Nobody in Sales &amp; Export yet, and no order credited in this period.</td></tr><?php endif; ?>
    <tr id="target-unassigned">
      <td><strong>Unassigned</strong></td>
      <td colspan="3" class="muted small">Confirmed orders with no Sales &amp; Export owner and no moved credit</td>
      <td><?= (int) $data['unassigned']['counts']['counted'] ?></td>
      <td><?= (int) $data['unassigned']['counts']['shipped'] ?></td>
      <td colspan="5"><?php $__refs = []; foreach ($data['unassigned']['orders'] as $oid => $o) { $__refs[] = $__order((int) $oid); } echo $__refs === [] ? '<span class="muted">none</span>' : implode(', ', $__refs); ?></td>
    </tr>
    <tr id="target-total">
      <td><strong>Total</strong></td>
      <td colspan="2"></td>
      <td><strong><?= View::e($__num($data['team_target'])) ?></strong></td>
      <td><strong><?= (int) $data['total']['counted'] ?></strong></td>
      <td colspan="6"></td>
    </tr>
  </table>
  </div>
  <p id="target-check-line" class="small">
    <?php if ($company['on'] && $company['containers_needed'] !== null): ?>
      Check: the team's targets add up to <strong><?= View::e($__num($data['team_target'])) ?></strong> containers; the company needs <strong><?= (int) $company['containers_needed'] ?></strong> this period<?= $data['team_target'] + 0.0001 < $company['containers_needed'] ? ' — <strong style="color:var(--danger)">' . View::e($__num($company['containers_needed'] - $data['team_target'])) . ' short</strong>' : ' — covered' ?>.
    <?php else: ?>
      <span class="muted">Check line: the company's containers needed are not known (no company target, or not enough shipping history yet).</span>
    <?php endif; ?>
  </p>

  <h2>Associates</h2>
  <p class="muted small">The order's earning associate is credited too (containers only). Associates never see targets.</p>
  <div class="table-scroll">
  <table class="list" id="target-associates">
    <tr><th>Associate</th><th>Target</th><th>Confirmed</th><th>Shipped</th><th>Last reviewed</th><th>Set target</th></tr>
    <?php foreach ($data['associates'] as $a): $aid = (int) $a['associate']['id']; ?>
    <tr>
      <td><?= View::e((string) $a['associate']['name']) ?></td>
      <td><?= $a['target'] > 0 ? View::e($__num($a['target'])) : '—' ?></td>
      <td><?= (int) $a['counts']['counted'] ?></td>
      <td><?= (int) $a['counts']['shipped'] ?></td>
      <td><?= $a['reviewed_at'] !== null ? View::e(Dates::short((string) $a['reviewed_at'])) : '<span class="muted">never</span>' ?></td>
      <td>
        <form method="post" action="/sales-targets/associate/<?= $aid ?>" class="btn-row" style="margin-top:0">
          <?= Csrf::field() ?>
          <input type="hidden" name="period" value="<?= View::e($period['key']) ?>">
          <input type="number" name="containers" min="0" step="0.1" value="<?= $a['target'] > 0 ? View::e($__num($a['target'])) : '' ?>" placeholder="e.g. 2" aria-label="<?= View::e($a['associate']['name'] . ' — containers per period') ?>" style="width:6rem">
          <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="e.g. Agreed at the quarterly review" aria-label="Reason for this change">
          <button type="submit" class="btn-sm">Save</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($data['associates'] === []): ?><tr><td colspan="6" class="muted">No associate credited in this period and no associate target.</td></tr><?php endif; ?>
  </table>
  </div>

  <h2>Edit a person's target</h2>
  <p class="muted small">Empty = the designation default (containers, new buyers) or the company multiple. Saving marks the target reviewed today.</p>
  <?php foreach ($data['rows'] as $r): $t = $r['target']; $uid = (int) $r['user']['id']; $own = $t['own']; ?>
  <details id="edit-target-<?= $uid ?>">
    <summary><?= View::e((string) $r['user']['name']) ?></summary>
    <form method="post" action="/sales-targets/person/<?= $uid ?>" class="field-grid">
      <?= Csrf::field() ?>
      <input type="hidden" name="period" value="<?= View::e($period['key']) ?>">
      <input type="hidden" name="buildup_present" value="1">
      <label>Containers per period
        <input type="number" name="containers" id="target-containers-<?= $uid ?>" min="0" step="0.1" value="<?= $own !== null && $own['containers'] !== null ? View::e($__num($own['containers'])) : '' ?>" placeholder="e.g. 6 (empty = designation default)"></label>
      <label>New buyers per period
        <input type="number" name="new_buyers" min="0" step="1" value="<?= $own !== null && $own['new_buyers'] !== null ? (int) $own['new_buyers'] : '' ?>" placeholder="e.g. 1 (empty = designation default)"></label>
      <label>Own multiple
        <input type="number" name="multiple" min="1" max="10" step="0.1" value="<?= $t['own_multiple'] !== null ? View::e($__num($t['own_multiple'])) : '' ?>" placeholder="e.g. 3 (empty = company setting)"></label>
      <label><input type="checkbox" name="buildup_enabled" value="1"<?= $t['buildup_enabled'] ? ' checked' : '' ?>> Build-up for a new joiner<?= $t['joined_on'] ? ' (joined ' . View::e(Dates::short((string) $t['joined_on'])) . ')' : ' (no joining date on the user)' ?></label>
      <label class="full">Reason for this change *
        <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="e.g. Reviewed with them at the quarterly meeting"></label>
      <div class="btn-row full"><button type="submit" class="btn-sm btn-success">Save target</button></div>
    </form>
  </details>
  <?php endforeach; ?>

  <h2>Move an order's target credit</h2>
  <p class="muted small">The credit goes to the order's Sales &amp; Export owner unless moved here. Only the target credit moves — nothing else on the order changes.</p>
  <details id="target-move-credit">
    <summary>Orders counted in <?= View::e($period['label']) ?> (<?= count($data['credited_orders']) ?>)</summary>
    <div class="table-scroll">
    <table class="list">
      <tr><th>Order</th><th>Credited to</th><th>Move to</th></tr>
      <?php foreach ($data['credited_orders'] as $oid => $o): $__cur = $data['owners'][(int) $oid] ?? null; ?>
      <tr>
        <td><?= $__order((int) $oid) ?></td>
        <td><?php $__name = 'Unassigned'; foreach ($data['staff'] as $s) { if ($s['id'] === $__cur) { $__name = $s['name']; } } echo View::e($__name); ?></td>
        <td>
          <form method="post" action="/sales-targets/credit/<?= (int) $oid ?>" class="btn-row" style="margin-top:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="period" value="<?= View::e($period['key']) ?>">
            <select name="user_id" required aria-label="Move the credit to">
              <option value="">Choose…</option>
              <option value="auto">Back to the Sales &amp; Export owner</option>
              <?php foreach ($data['staff'] as $s): ?><option value="<?= (int) $s['id'] ?>"><?= View::e($s['name']) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="e.g. Closed by them while the owner was on leave" aria-label="Reason for moving the credit">
            <button type="submit" class="btn-sm">Move</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($data['credited_orders'] === []): ?><tr><td colspan="3" class="muted">No order counted in this period.</td></tr><?php endif; ?>
    </table>
    </div>
  </details>

  <h2>Calculator</h2>
  <div id="target-calculator" class="field-grid">
    <p class="muted small full">Works in your browser only — nothing typed here is saved or sent. Containers per period = monthly cost × multiple × <?= (int) $period['months'] ?> month(s) ÷ average margin per container, rounded up. "Use this" puts the result in that person's containers field above; save it there with a reason.</p>
    <label>Person
      <select id="calc-person" aria-label="Person">
        <option value="">Choose…</option>
        <?php foreach ($data['rows'] as $r): ?><option value="<?= (int) $r['user']['id'] ?>" data-multiple="<?= View::e($__num($r['target']['multiple'])) ?>"><?= View::e((string) $r['user']['name']) ?></option><?php endforeach; ?>
      </select></label>
    <label>Monthly cost of the person (₹)
      <input type="number" id="calc-cost" min="0" step="1" placeholder="e.g. 60000" autocomplete="off"></label>
    <label>Multiple
      <input type="number" id="calc-multiple" min="1" max="10" step="0.1" value="<?= View::e($__num($settings['multiple'])) ?>" placeholder="e.g. 3"></label>
    <label>Average margin per container (₹)
      <input type="number" id="calc-margin" min="1" step="1" value="<?= $avgMargin !== null && $avgMargin > 0 ? (int) round($avgMargin) : '' ?>" placeholder="e.g. 150000"></label>
    <p class="full">Result: <strong id="calc-result">—</strong> containers per period
      <button type="button" class="btn-sm" id="calc-use" disabled>Use this</button></p>
  </div>
  <script>
    (function () {
      var months = <?= (int) $period['months'] ?>;
      var person = document.getElementById('calc-person');
      var cost = document.getElementById('calc-cost');
      var mult = document.getElementById('calc-multiple');
      var margin = document.getElementById('calc-margin');
      var out = document.getElementById('calc-result');
      var use = document.getElementById('calc-use');
      var result = null;
      function calc() {
        var c = parseFloat(cost.value), m = parseFloat(mult.value), g = parseFloat(margin.value);
        result = (c > 0 && m > 0 && g > 0) ? Math.ceil((c * m * months / g) * 10) / 10 : null;
        out.textContent = result === null ? '—' : String(result);
        use.disabled = result === null || person.value === '';
      }
      person.addEventListener('change', function () {
        var opt = person.options[person.selectedIndex];
        if (opt && opt.getAttribute('data-multiple')) { mult.value = opt.getAttribute('data-multiple'); }
        calc();
      });
      [cost, mult, margin].forEach(function (el) { el.addEventListener('input', calc); });
      use.addEventListener('click', function () {
        var field = document.getElementById('target-containers-' + person.value);
        if (!field || result === null) { return; }
        field.value = String(result);
        var box = document.getElementById('edit-target-' + person.value);
        if (box) { box.open = true; box.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        field.focus();
      });
    })();
  </script>
</div>
