<?php
$statusMap = [
    'queued' => ['label' => t('admin.mails.status_queued'), 'class' => 'mail-status-queued'],
    'sent' => ['label' => t('admin.mails.wysany'), 'class' => 'mail-status-sent'],
    'failed' => ['label' => t('admin.mails.bad_wysyki'), 'class' => 'mail-status-failed'],
    'dead_letter' => ['label' => t('admin.mails.dead_letter_kontrola_reczna'), 'class' => 'mail-status-failed'],
    'processing' => ['label' => t('admin.mails.wysyanie'), 'class' => 'mail-status-processing'],
    'cancelled' => ['label' => t('admin.mails.status_cancelled'), 'class' => 'mail-status-cancelled'],
];

if (!function_exists('getMailPreview')) {
    function getMailPreview($body) {
        $preview = strip_tags($body);
        // Skracanie tokenów i linków
        $preview = preg_replace('/[a-f0-9]{32,}/i', '[token]', $preview);
        $preview = preg_replace('/https?:\/\/[^\s]+/', '[link]', $preview);
        return mb_substr($preview, 0, 100) . (mb_strlen($preview) > 100 ? '...' : '');
    }
}
?>

<section class="admin-page-head">
    <p class="kicker"><?= e(t('admin.mails.komunikacja')) ?></p>
    <h1><?= e(t('admin.mails.kolejka_maili')) ?></h1>
    <p><?= e(t('admin.mails.przeglad_wiadomosci_systemowych_i_statusow_ich_doreczenia')) ?></p>
</section>

<div class="mail-queue-page">
    <div class="mail-queue-list">
        <?php foreach ($mails as $m): 
            $st = $statusMap[$m['status']] ?? ['label' => $m['status'], 'class' => 'mail-status-queued'];
            $date = date('d.m.Y H:i', strtotime($m['created_at']));
        ?>
            <div class="mail-queue-row">
                <div class="mail-queue-main">
                    <div class="mail-queue-title"><?= e($m['subject']) ?></div>
                    <div class="mail-queue-preview"><?= e(getMailPreview($m['body'])) ?></div>
                </div>
                <div class="mail-queue-meta">
                    <div class="mail-queue-recipient">
                        <span style="color:#999; font-size:10px; text-transform:uppercase; letter-spacing:0.05em;"><?= e(t('admin.mails.do')) ?></span> <?= e($m['email']) ?>
                    </div>
                    <div class="mail-queue-date"><?= $date ?></div>
                    <div class="mail-status-badge <?= $st['class'] ?>"><?= $st['label'] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($mails)): ?>
            <div style="padding: 60px 20px; text-align: center; color: #999; font-style: italic; background: #fff;">
                <?= e(t('admin.mails.brak_wiadomosci_w_kolejce')) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
