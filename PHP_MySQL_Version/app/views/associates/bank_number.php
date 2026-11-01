<div class="card">
  <h1>Bank account — <?= htmlspecialchars((string) $a['name']) ?></h1>
  <p class="muted">Shown to Finance and the MD only. This view is recorded in the audit log.</p>
  <table class="kv">
    <tr><th>Holder</th><td><?= htmlspecialchars((string) $b['holder_name']) ?></td></tr>
    <tr><th>Bank</th><td><?= htmlspecialchars((string) $b['bank_name']) ?><?= $b['bank_branch_address'] ? '<br>' . htmlspecialchars((string) $b['bank_branch_address']) : '' ?></td></tr>
    <tr><th>Account / IBAN</th><td><code><?= htmlspecialchars((string) $number) ?></code></td></tr>
    <tr><th>IFSC / SWIFT</th><td><?= htmlspecialchars(trim((string) $b['ifsc'] . ' ' . (string) $b['swift'])) ?></td></tr>
    <tr><th>Intermediary bank</th><td><?= htmlspecialchars((string) ($b['intermediary_bank'] ?? '—')) ?></td></tr>
    <tr><th>Currency / status</th><td><?= htmlspecialchars((string) $b['account_currency']) ?> · <?= htmlspecialchars(ucfirst((string) $b['status'])) ?></td></tr>
  </table>
  <p><a href="/associates/<?= (int) $a['id'] ?>#bank">Back</a></p>
</div>
