<?php
$fund = is_array($fund ?? null) ? $fund : [];
$policy = is_array($fund['policy'] ?? null) ? $fund['policy'] : [];
$summary = is_array($fund['summary'] ?? null) ? $fund['summary'] : [];
$money = static fn($minor): string => number_format(((int)$minor) / 100, 2, ',', ' ') . ' PLN';
$percent = static fn($basisPoints): string => number_format(((int)$basisPoints) / 100, 2, ',', ' ') . '%';
$tr = static fn(string $key): string => t($key, 'pl');
$categoryLabel = static fn(string $category): string => t('safety_fund.category.' . $category, 'pl');
$resourceLabel = static fn(string $resource): string => t('safety_fund.resource.' . $resource, 'pl');
$resultLabel = static fn(string $result): string => t('safety_fund.result.' . $result, 'pl');
$approvalStatus = static function (array $approval): string {
    if (!empty($approval['rejected_at'])) return t('safety_fund.status.rejected', 'pl');
    if (!empty($approval['approved_at'])) return t('safety_fund.status.approved', 'pl');
    if ((string)($approval['status'] ?? '') === 'expired') return t('safety_fund.status.expired', 'pl');
    return t('safety_fund.status.pending', 'pl');
};
?>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker"><?= e($tr('safety_fund.admin.kicker')) ?></p>
  <h1><?= e($tr('safety_fund.admin.title')) ?></h1>
  <p><?= e($tr('safety_fund.admin.description')) ?></p>
</section>

<?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>

<section class="settlement-grid safety-fund-summary">
  <div class="settlement-card is-red">
    <span><?= e($tr('safety_fund.admin.balance')) ?></span>
    <strong><?= e($money($fund['balance_minor'] ?? 0)) ?></strong>
    <small><?= e($tr('safety_fund.admin.balance_note')) ?></small>
  </div>
  <div class="settlement-card">
    <span><?= e($tr('safety_fund.admin.total_inflow')) ?></span>
    <strong><?= e($money($summary['total_inflow_minor'] ?? 0)) ?></strong>
    <small><?= (int)($summary['inflow_count'] ?? 0) ?> <?= e($tr('safety_fund.admin.operations')) ?></small>
  </div>
  <div class="settlement-card">
    <span><?= e($tr('safety_fund.admin.total_outflow')) ?></span>
    <strong><?= e($money($summary['total_outflow_minor'] ?? 0)) ?></strong>
    <small><?= (int)($summary['outflow_count'] ?? 0) ?> <?= e($tr('safety_fund.admin.operations')) ?></small>
  </div>
  <div class="settlement-card">
    <span><?= e($tr('safety_fund.admin.current_policy')) ?></span>
    <strong><?= e($percent($policy['author_basis_points'] ?? 0)) ?> / <?= e($percent($policy['platform_basis_points'] ?? 0)) ?> / <?= e($percent($policy['safety_fund_basis_points'] ?? 0)) ?></strong>
    <small><?= e($tr('safety_fund.admin.policy_version')) ?> #<?= (int)($policy['version'] ?? 0) ?></small>
  </div>
</section>

<section class="admin-panel-block zs-operator-panel" id="policy">
  <div class="admin-section-head">
    <div><p class="kicker"><?= e($tr('safety_fund.admin.policy_kicker')) ?></p><h2><?= e($tr('safety_fund.admin.policy_title')) ?></h2></div>
    <span><?= e($tr('safety_fund.admin.dors3_required')) ?></span>
  </div>
  <p><?= e($tr('safety_fund.admin.policy_explanation')) ?></p>
  <form method="post" action="/admin/safety-fund/policy" class="safety-fund-policy-form">
    <?= csrf_field() ?>
    <label><span><?= e($tr('safety_fund.admin.author_percent')) ?></span><input name="author_percent" inputmode="decimal" required value="<?= e(number_format(((int)($policy['author_basis_points'] ?? 4000)) / 100, 2, '.', '')) ?>"></label>
    <label><span><?= e($tr('safety_fund.admin.platform_percent')) ?></span><input name="platform_percent" inputmode="decimal" required value="<?= e(number_format(((int)($policy['platform_basis_points'] ?? 4000)) / 100, 2, '.', '')) ?>"></label>
    <label><span><?= e($tr('safety_fund.admin.fund_percent')) ?></span><input name="safety_fund_percent" inputmode="decimal" required value="<?= e(number_format(((int)($policy['safety_fund_basis_points'] ?? 2000)) / 100, 2, '.', '')) ?>"></label>
    <button class="btn-red" type="submit"><?= e($tr('safety_fund.admin.request_policy_change')) ?></button>
  </form>
</section>

<section class="admin-panel-block zs-operator-panel" id="disbursement">
  <div class="admin-section-head">
    <div><p class="kicker"><?= e($tr('safety_fund.admin.disbursement_kicker')) ?></p><h2><?= e($tr('safety_fund.admin.disbursement_title')) ?></h2></div>
    <span><?= e($tr('safety_fund.admin.dors3_required')) ?></span>
  </div>
  <p><?= e($tr('safety_fund.admin.disbursement_explanation')) ?></p>
  <form method="post" action="/admin/safety-fund/disbursements" class="safety-fund-disbursement-form">
    <?= csrf_field() ?>
    <label><span><?= e($tr('safety_fund.admin.amount')) ?></span><input name="amount" inputmode="decimal" required placeholder="0,00"></label>
    <label><span><?= e($tr('safety_fund.admin.category')) ?></span><select name="category" required>
      <?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"><?= e($categoryLabel($category)) ?></option><?php endforeach; ?>
    </select></label>
    <label class="is-wide"><span><?= e($tr('safety_fund.admin.expense_description')) ?></span><textarea name="description" required maxlength="500"></textarea></label>
    <label class="is-wide"><span><?= e($tr('safety_fund.admin.evidence_reference')) ?></span><input name="evidence_reference" required maxlength="255" placeholder="<?= e($tr('safety_fund.admin.evidence_placeholder')) ?>"></label>
    <button class="btn-red" type="submit"><?= e($tr('safety_fund.admin.request_disbursement')) ?></button>
  </form>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head"><div><p class="kicker"><?= e($tr('safety_fund.admin.inflow_kicker')) ?></p><h2><?= e($tr('safety_fund.admin.inflow_history')) ?></h2></div><span><?= count($fund['allocations'] ?? []) ?></span></div>
  <?php if (empty($fund['allocations'])): ?>
    <div class="empty-state"><h3><?= e($tr('safety_fund.admin.no_inflows')) ?></h3></div>
  <?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table admin-table-wide"><thead><tr>
      <th><?= e($tr('safety_fund.admin.date')) ?></th><th><?= e($tr('safety_fund.admin.article')) ?></th><th><?= e($tr('safety_fund.admin.base_amount')) ?></th><th><?= e($tr('safety_fund.admin.fund_share')) ?></th><th><?= e($tr('safety_fund.admin.policy_version')) ?></th><th><?= e($tr('safety_fund.admin.ledger_reference')) ?></th>
    </tr></thead><tbody>
      <?php foreach ($fund['allocations'] as $allocation): ?><tr>
        <td><?= e((string)$allocation['created_at']) ?></td><td><strong><?= e((string)$allocation['title']) ?></strong><small>#<?= (int)$allocation['article_id'] ?></small></td>
        <td><?= e($money($allocation['base_amount_minor'])) ?></td><td><strong><?= e($money($allocation['safety_fund_amount_minor'])) ?></strong><small><?= e($percent($allocation['safety_fund_basis_points'])) ?></small></td>
        <td>#<?= (int)$allocation['version'] ?></td><td>#<?= (int)$allocation['ledger_transaction_id'] ?></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head"><div><p class="kicker"><?= e($tr('safety_fund.admin.outflow_kicker')) ?></p><h2><?= e($tr('safety_fund.admin.outflow_history')) ?></h2></div><span><?= count($fund['disbursements'] ?? []) ?></span></div>
  <?php if (empty($fund['disbursements'])): ?>
    <div class="empty-state"><h3><?= e($tr('safety_fund.admin.no_outflows')) ?></h3></div>
  <?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table admin-table-wide"><thead><tr>
      <th><?= e($tr('safety_fund.admin.date')) ?></th><th><?= e($tr('safety_fund.admin.amount')) ?></th><th><?= e($tr('safety_fund.admin.category')) ?></th><th><?= e($tr('safety_fund.admin.expense_description')) ?></th><th><?= e($tr('safety_fund.admin.administrator')) ?></th><th>3DORS</th><th><?= e($tr('safety_fund.admin.ledger_reference')) ?></th>
    </tr></thead><tbody>
      <?php foreach ($fund['disbursements'] as $disbursement): ?><tr>
        <td><?= e((string)$disbursement['executed_at']) ?></td><td><strong><?= e($money($disbursement['amount_minor'])) ?></strong></td><td><?= e($categoryLabel((string)$disbursement['category'])) ?></td>
        <td><?= e((string)$disbursement['description']) ?><small><?= e((string)$disbursement['evidence_reference']) ?></small></td><td><?= e((string)($disbursement['administrator_name'] ?? '—')) ?></td>
        <td><code><?= e((string)$disbursement['approval_request_public_id']) ?></code></td><td>#<?= (int)$disbursement['ledger_transaction_id'] ?></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head"><div><p class="kicker"><?= e($tr('safety_fund.admin.policy_history_kicker')) ?></p><h2><?= e($tr('safety_fund.admin.policy_history')) ?></h2></div><span><?= count($fund['policies'] ?? []) ?></span></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th><?= e($tr('safety_fund.admin.policy_version')) ?></th><th><?= e($tr('safety_fund.admin.split')) ?></th><th><?= e($tr('safety_fund.admin.status')) ?></th><th><?= e($tr('safety_fund.admin.effective_from')) ?></th><th>3DORS</th></tr></thead><tbody>
    <?php foreach ($fund['policies'] ?? [] as $historyPolicy): ?><tr><td>#<?= (int)$historyPolicy['version'] ?></td><td><?= e($percent($historyPolicy['author_basis_points'])) ?> / <?= e($percent($historyPolicy['platform_basis_points'])) ?> / <?= e($percent($historyPolicy['safety_fund_basis_points'])) ?></td><td><?= e($tr('safety_fund.status.' . (string)$historyPolicy['status'])) ?></td><td><?= e((string)$historyPolicy['effective_from']) ?></td><td><?= e((string)($historyPolicy['approval_request_public_id'] ?? '—')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head"><div><p class="kicker"><?= e($tr('safety_fund.admin.audit_kicker')) ?></p><h2><?= e($tr('safety_fund.admin.audit_title')) ?></h2></div><span><?= count($fund['approvals'] ?? []) + count($fund['audit'] ?? []) ?></span></div>
  <div class="admin-table-wrap"><table class="admin-table admin-table-wide"><thead><tr><th><?= e($tr('safety_fund.admin.date')) ?></th><th><?= e($tr('safety_fund.admin.operation')) ?></th><th><?= e($tr('safety_fund.admin.status')) ?></th><th><?= e($tr('safety_fund.admin.resource')) ?></th><th><?= e($tr('safety_fund.admin.correlation')) ?></th></tr></thead><tbody>
    <?php foreach ($fund['approvals'] ?? [] as $approval): ?><tr><td><?= e((string)$approval['issued_at']) ?></td><td><?= e(t('safety_fund.operation.' . (string)$approval['action_type'], 'pl')) ?></td><td><?= e($approvalStatus($approval)) ?></td><td><?= e($resourceLabel((string)$approval['resource_type'])) ?> <small><?= e((string)$approval['resource_id']) ?></small></td><td><code><?= e((string)$approval['correlation_id']) ?></code></td></tr><?php endforeach; ?>
    <?php foreach ($fund['audit'] ?? [] as $event): ?><tr><td><?= e((string)$event['occurred_at']) ?></td><td><?= e(t('safety_fund.event.' . (string)$event['action'], 'pl')) ?></td><td><?= e($resultLabel((string)$event['result'])) ?></td><td><?= e($resourceLabel((string)$event['resource_type'])) ?> <small><?= e((string)$event['resource_id']) ?></small></td><td><code><?= e((string)$event['correlation_id']) ?></code></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
