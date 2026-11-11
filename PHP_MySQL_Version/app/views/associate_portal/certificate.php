<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Client registration certificate — <?= htmlspecialchars((string) $c['company_legal_name']) ?></title>
<style>body{font-family:Georgia,serif;max-width:760px;margin:40px auto;color:#1d2433}h1{color:#013090;font-size:26px}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:6px;text-align:left;vertical-align:top}.muted{color:#5b6478;font-size:13px}@media print{.noprint{display:none}}</style></head>
<body>
<p class="noprint"><button onclick="window.print()">Print / save as PDF</button></p>
<h1>NexaCrest International Private Limited<br><small>Client registration certificate</small></h1>
<p>This confirms that the company below registered with NexaCrest and that our associate introduced them, under the associate agreement.</p>
<table>
  <tr><th>Associate</th><td><?= htmlspecialchars((string) $a['name']) ?><?= !empty($a['personal_code']) ? ' (' . htmlspecialchars((string) $a['personal_code']) . ')' : '' ?></td></tr>
  <tr><th>Client</th><td><?= htmlspecialchars((string) $c['company_legal_name']) ?> — <?= htmlspecialchars((string) $c['client_unique_number']) ?></td></tr>
  <tr><th>Country</th><td><?= htmlspecialchars((string) ($c['country_of_destination'] ?? '')) ?></td></tr>
  <tr><th>Registered on</th><td><?= htmlspecialchars(\App\Helpers\Dates::short((string) $c['associate_registered_on'])) ?></td></tr>
  <tr><th>Client of this associate until</th><td><?= $c['associate_locked_until'] ? htmlspecialchars(\App\Helpers\Dates::short((string) $c['associate_locked_until'])) : '—' ?></td></tr>
</table>
<h2>History</h2>
<table><?php foreach (array_reverse($events) as $e): ?><tr><td style="white-space:nowrap"><?= htmlspecialchars((string) $e['created_at']) ?></td><td><?= htmlspecialchars((string) $e['message']) ?></td></tr><?php endforeach; ?></table>
<p class="muted">Printed <?= date('j M Y, H:i') ?> IST from the NexaCrest associate portal. The history above is kept by NexaCrest and cannot be edited.</p>
</body></html>
