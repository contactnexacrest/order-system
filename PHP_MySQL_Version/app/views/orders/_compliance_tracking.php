<?php
/**
 * Owner decision (2 Nov 2026, gap-list Q-6): ECGC cover (the buyer's
 * ECGC-approved limit against open orders, and this shipment's monthly
 * declaration) and incentive claim tracking (sanctioned amount, deadline,
 * status). Shown to Export Compliance, Finance and management.
 * Node twin: views/orders/_compliance_tracking.njk (same text).
 *
 * @var array $order
 * @var array $sees
 * @var bool $canFileBenefitClaims
 * @var bool $canManageOrderFinancials
 */

use App\Helpers\Csrf;
use App\Repositories\CaExportBenefitRepository;
use App\Services\AuthService;
use App\Services\ComplianceTrackingService;
use App\Services\PermissionService;

$__u = AuthService::currentUser();
$__rid = $__u['role_id'] !== null ? (int) $__u['role_id'] : null;
$__canCaManage = PermissionService::can((int) $__u['id'], $__rid, 'ca_module_manage');
$__ecgcEdit = $canFileBenefitClaims || $__canCaManage;
$__ecgcSee = $__ecgcEdit || $canManageOrderFinancials;
$__claimsSee = $canFileBenefitClaims || $canManageOrderFinancials;
$__ecgc = $__ecgcSee ? ComplianceTrackingService::ecgcPosition((int) $order['client_id']) : null;
$__decl = $__ecgcSee ? ComplianceTrackingService::declarationStatus((int) $order['id']) : null;
// Claims stay hidden until the order is complete and the balance has landed — the same gate as Step 13g's claim table.
$__claimsSee = $__claimsSee && in_array($order['status'], ['shipped_docs_sent', 'complete'], true) && !empty($payment['balance_remittance_received_at']);
$__claims = $__claimsSee ? array_map([ComplianceTrackingService::class, 'claimView'], CaExportBenefitRepository::forOrder((int) $order['id'])) : [];
$__money = !empty($sees['selling']);
?>
<?php if ($__ecgcSee && ($__ecgc['policy_no'] !== '' || $__ecgc['limit'] !== null)): ?>
<details class="step-card" id="ecgc-cover"<?= ($__ecgc['warning'] !== null || in_array($__decl['state'], ['due', 'overdue'], true)) ? ' open' : '' ?>>
  <summary><h2>ECGC cover</h2><span class="fin-sub-summary"><?php if ($__ecgc['warning'] !== null): ?><span class="phase-pill warn">limit warning</span> <?php endif; ?><?= $__decl['state'] === 'declared' ? 'declared' : ($__decl['state'] === 'overdue' ? '<span class="phase-pill warn">declaration overdue</span>' : ($__decl['state'] === 'due' ? 'declaration due ' . htmlspecialchars((string) $__decl['due']) : 'declare after the BL')) ?></span></summary>
  <p class="muted small">ECGC policy <?= htmlspecialchars($__ecgc['policy_no'] !== '' ? $__ecgc['policy_no'] : 'not set (Admin → Company Settings)') ?><?= $__ecgc['cover_pct'] !== '' ? ' · cover ' . htmlspecialchars($__ecgc['cover_pct']) . '%' : '' ?>.
    Buyer limit: <?php if ($__ecgc['limit'] === null): ?>not recorded — <a href="/clients/<?= (int) $order['client_id'] ?>#ecgc">record it on the buyer's page</a><?php else: ?><?= $__money ? htmlspecialchars((string) $__ecgc['currency']) . ' ' . number_format((float) $__ecgc['limit'], 2) : 'recorded' ?><?= $__ecgc['valid_to'] ? ' (valid until ' . htmlspecialchars((string) $__ecgc['valid_to']) . ')' : '' ?><?php if ($__money): ?> · open orders <?php foreach ($__ecgc['exposure'] as $__c => $__a): ?><?= htmlspecialchars((string) $__c) ?> <?= number_format((float) $__a, 2) ?> <?php endforeach; ?><?php endif; ?><?php endif; ?>.</p>
  <?php if ($__ecgc['warning'] !== null): ?>
    <p class="review-banner warn">&#9888; <?= $__money ? htmlspecialchars($__ecgc['warning']) : 'This buyer\'s open orders are above (or outside) the ECGC-approved credit limit — check with Finance.' ?></p>
  <?php endif; ?>
  <p class="<?= $__decl['state'] === 'overdue' ? 'review-banner warn' : 'muted small' ?>"><?= $__decl['state'] === 'overdue' ? '&#9888; ' : '' ?><?= htmlspecialchars($__decl['text']) ?></p>
  <?php if ($__ecgcEdit && $__decl['state'] !== 'declared' && $__decl['state'] !== 'not_due'): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/ecgc-declaration" class="field-grid">
      <?= Csrf::field() ?>
      <label>Declared to ECGC on *<input type="date" name="ecgc_declared_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
      <label>Declaration reference *<input type="text" name="ecgc_declaration_ref" required minlength="3" maxlength="100" placeholder="e.g. ECGC/DECL/2026-11/0042"></label>
      <div><button type="submit" class="btn-sm">Record declaration</button></div>
    </form>
  <?php endif; ?>
</details>
<?php endif; ?>
<?php if ($__claimsSee && $__claims !== []): ?>
<details class="step-card" id="benefit-claim-tracking" open>
  <summary><h2>Incentive claims &mdash; tracking</h2><span class="fin-sub-summary"><?= count($__claims) ?> claim(s)</span></summary>
  <p class="muted small">Claimed, sanctioned and received side by side, with the deadline to complete each claim. A default deadline comes from Admin → Company Settings (per scheme, counted from the shipping bill date — to be confirmed with the CA); change it here when the scheme says otherwise. Reminders go out 30 and 7 days before.</p>
  <table class="list">
    <tr><th>Scheme</th><th>Claimed (&#8377;)</th><th>Sanctioned (&#8377;)</th><th>Received (&#8377;)</th><th>Deadline</th><th>Status</th></tr>
    <?php foreach ($__claims as $__b): ?>
    <tr>
      <td><strong><?= htmlspecialchars($__b['scheme_name']) ?></strong><br><span class="muted small"><?= htmlspecialchars($__b['reference_number'] ?? 'no ref.') ?></span></td>
      <td><?= number_format((float) $__b['claimed_amount'], 2) ?><br><span class="muted small">on <?= htmlspecialchars((string) $__b['claimed_at']) ?></span></td>
      <td><?= $__b['sanctioned_amount'] !== null ? number_format((float) $__b['sanctioned_amount'], 2) . '<br><span class="muted small">on ' . htmlspecialchars((string) ($__b['sanctioned_at'] ?? '—')) . '</span>' : '—' ?></td>
      <td><?= $__b['received_amount'] !== null ? number_format((float) $__b['received_amount'], 2) : '—' ?></td>
      <td><?= $__b['effective_deadline'] !== null ? htmlspecialchars((string) $__b['effective_deadline']) . ($__b['deadline_is_default'] ? ' <span class="muted small">(default)</span>' : '') . ($__b['effective_status'] !== 'received' && $__b['effective_status'] !== 'rejected' && $__b['days_left'] !== null ? '<br><span class="' . ($__b['days_left'] <= 30 ? 'phase-pill warn' : 'muted small') . '">' . ($__b['days_left'] < 0 ? 'passed' : (int) $__b['days_left'] . ' day(s) left') . '</span>' : '') : '<span class="muted">no deadline</span>' ?></td>
      <td><span class="badge<?= $__b['effective_status'] === 'received' ? ' good' : ($__b['effective_status'] === 'rejected' ? ' danger' : '') ?>"><?= htmlspecialchars($__b['status_label']) ?></span><?= $__b['effective_status'] === 'rejected' && $__b['rejected_reason'] ? '<br><span class="muted small">' . htmlspecialchars((string) $__b['rejected_reason']) . '</span>' : '' ?></td>
    </tr>
    <?php if ($__b['effective_status'] !== 'received' || $__b['sanctioned_amount'] === null): ?>
    <tr><td colspan="6">
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefits/<?= (int) $__b['id'] ?>/tracking" class="field-grid">
        <?= Csrf::field() ?>
        <label>Status *
          <select name="claim_status" required>
            <?php foreach (['filed' => 'Filed', 'sanctioned' => 'Sanctioned', 'rejected' => 'Rejected'] as $__k => $__l): ?>
              <option value="<?= $__k ?>"<?= ($__b['effective_status'] === $__k || ($__b['effective_status'] === 'received' && $__k === 'sanctioned')) ? ' selected' : '' ?>><?= $__l ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Sanctioned amount (&#8377;)<input type="number" step="0.01" min="0" name="sanctioned_amount" value="<?= $__b['sanctioned_amount'] !== null ? htmlspecialchars((string) $__b['sanctioned_amount']) : '' ?>" placeholder="e.g. 24500.00"></label>
        <label>Sanctioned on<input type="date" name="sanctioned_at" value="<?= htmlspecialchars((string) ($__b['sanctioned_at'] ?? '')) ?>"></label>
        <label>Claim deadline<input type="date" name="claim_deadline" value="<?= htmlspecialchars((string) ($__b['claim_deadline'] ?? '')) ?>"></label>
        <label>Reason if rejected<input type="text" name="rejected_reason" maxlength="500" placeholder="e.g. Shipping bill not transmitted to DGFT"></label>
        <div><button type="submit" class="btn-sm">Save claim tracking</button></div>
      </form>
    </td></tr>
    <?php endif; ?>
    <?php endforeach; ?>
  </table>
</details>
<?php endif; ?>
