<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Associates — standard rates and rules</h1>
  <p class="muted">The standard commission ladder applies to every associate who has no personal rate for that rung (a personal rate of 0 or empty also uses it). The rate is fixed on each order when the order is created, so a change here applies to new orders only. Commission is earned once the buyer has paid the order in full — never on the advance alone — and is paid in the next incentive month against the associate's invoice.</p>
  <form method="post" action="/associates/ladder">
    <?= Csrf::field() ?>
    <fieldset><legend>Standard rate (% of product FOB value) by the associate's Nth client</legend>
      <div class="field-grid">
        <?php foreach (['rate_1_5' => 'Clients 1–5', 'rate_6_10' => 'Clients 6–10', 'rate_11_20' => 'Clients 11–20', 'rate_21_40' => 'Clients 21–40', 'rate_41_plus' => 'Client 41 onward'] as $f => $label): ?>
          <label><?= $label ?> *<input type="number" name="<?= $f ?>" required min="0" max="20" step="0.01" value="<?= htmlspecialchars(number_format((float) $ladder[$f], 2, '.', '')) ?>"></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="field-grid">
      <label>TDS on commission, India (section 194H) % *<input type="number" name="associate_tds_pct" required min="0" max="30" step="0.01" value="<?= htmlspecialchars(number_format($tds, 2, '.', '')) ?>"></label>
      <label>Client protection (months from acceptance) *<input type="number" name="associate_protection_months" required min="1" max="60" step="1" value="<?= (int) $protection ?>"></label>
      <label>Wait before a new bank account is used (days) *<input type="number" name="associate_bank_wait_days" required min="0" max="60" step="1" value="<?= (int) $wait ?>"></label>
    </div>
    <div class="btn-row"><button type="submit">Save</button> <a href="/associates">Back to associates</a></div>
  </form>
</div>
