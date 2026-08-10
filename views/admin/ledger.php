<section class="admin-page-head">
    <p class="kicker"><?= e(t('admin.ledger.finanse_i_portfele')) ?></p>
    <h1><?= e(t('admin.ledger.ledger_portfeli')) ?></h1>
    <p><?= e(t('admin.ledger.pena_historia_operacji_finansowych_bonusow_i_korekt_w_c_def002f7')) ?></p>
</section>

<?php
use App\Services\ActivityUiHelper;
?>

<div class="wallet-ledger-list">
    <?php foreach ($transactions as $t): ?>
        <?php 
        $iconName = ActivityUiHelper::getIconName($t['type']);
        $humanType = ActivityUiHelper::getLabel($t['type']);
        $amountMinor = (int)$t['amount_minor'];
        $amount = $amountMinor / 100;
        $isZero = abs($amount) < 0.001;
        $userName = $t['display_name'] ?? str_replace('{id}', (string)($t['user_id'] ?? '?'), t('admin.ledger.deleted_user'));
        $isDeleted = empty($t['display_name']);
        ?>
        <article class="wallet-ledger-row">
            <div class="wallet-ledger-left">
                <div class="wallet-ledger-icon">
                    <?= zs_icon($iconName) ?>
                </div>
                <div class="wallet-ledger-info">
                    <div class="wallet-ledger-title">
                        <span class="wallet-ledger-user"><?= e($userName) ?></span>
                        <?php if ($isDeleted): ?><span class="zs-user-deleted"><?= e(t('admin.ledger.usuniety')) ?></span><?php endif; ?>
                        <span class="wallet-ledger-sep">·</span>
                        <span class="wallet-ledger-type"><?= e($humanType) ?></span>
                    </div>
                    <div class="wallet-ledger-desc"><?= zs_clean_description($t['description'] ?? null) ?></div>
                </div>
            </div>
            <div class="wallet-ledger-right">
                <div class="wallet-ledger-amount <?= $isZero ? 'is-zero' : '' ?>">
                    <?= ($amountMinor > 0 ? '+' : '') . number_format($amount, 2, ',', ' ') ?> PLN
                </div>
                <div class="wallet-ledger-points">
                    <?php if ((int)$t['points'] !== 0): ?>
                        <?= ((int)$t['points'] > 0 ? '+' : '') . (int)$t['points'] ?> TT
                    <?php else: ?>
                        <span style="opacity: 0.4"><?= e(t('admin.ledger.bez_tt')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="wallet-ledger-meta">
                    <?= e($t['source_module']) ?> · <?= date('H:i', strtotime($t['created_at'])) ?> · <?= date('d.m.Y', strtotime($t['created_at'])) ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
