<?php
$statusMap = [
    'queued' => ['label' => 'W kolejce', 'class' => 'mail-status-queued'],
    'sent' => ['label' => 'Wysłany', 'class' => 'mail-status-sent'],
    'failed' => ['label' => 'Błąd wysyłki', 'class' => 'mail-status-failed'],
    'dead_letter' => ['label' => 'Dead letter — kontrola ręczna', 'class' => 'mail-status-failed'],
    'processing' => ['label' => 'Wysyłanie', 'class' => 'mail-status-processing'],
    'cancelled' => ['label' => 'Anulowany', 'class' => 'mail-status-cancelled'],
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
    <p class="kicker">Komunikacja</p>
    <h1>Kolejka maili</h1>
    <p>Przegląd wiadomości systemowych i statusów ich doręczenia.</p>
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
                        <span style="color:#999; font-size:10px; text-transform:uppercase; letter-spacing:0.05em;">Do:</span> <?= e($m['email']) ?>
                    </div>
                    <div class="mail-queue-date"><?= $date ?></div>
                    <div class="mail-status-badge <?= $st['class'] ?>"><?= $st['label'] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($mails)): ?>
            <div style="padding: 60px 20px; text-align: center; color: #999; font-style: italic; background: #fff;">
                Brak wiadomości w kolejce.
            </div>
        <?php endif; ?>
    </div>
</div>
