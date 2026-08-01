<?php
namespace App\Services;

use App\Core\Database;

final class SupportService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?NotificationOutboxDispatcher $notificationOutbox = null,
    ) {}

    public function supportArticle(int $readerId, int $articleId, int $amountMinor, string $note = ''): int
    {
        if ($amountMinor < 100) throw new \InvalidArgumentException('Minimalne wsparcie to 1 PLN.');
        return $this->db->transaction(function(Database $db) use ($readerId, $articleId, $amountMinor, $note) {
            $article = $db->one('SELECT id,title,author_id FROM articles WHERE id=:id AND status=\'published\'', ['id'=>$articleId]);
            if (!$article) throw new \RuntimeException('Nie znaleziono opublikowanego tekstu.');
            if ((int)$article['author_id'] === $readerId) throw new \RuntimeException('Autor nie może wspierać własnego tekstu.');
            $ledger = new LedgerService($db, new \App\Services\FinancialService($db));
            $ledger->lockWalletsForUsers([$readerId, (int)$article['author_id']]);
            $payment = new PaymentService($db);
            $paymentId = $payment->createPayment($readerId, 'manual', 'article_payment', 'paid', $amountMinor, [
                'note'=>$note, 'completed_at'=>date('Y-m-d H:i:s'), 'mode'=>'manual_support'
            ]);
            $payment->addItem($paymentId, 'article_support', $articleId, 'Wsparcie tekstu: ' . $article['title'], $amountMinor);
            $db->query('INSERT INTO article_supports(article_id,reader_id,author_id,payment_id,amount_minor,note,created_at) VALUES(:article,:reader,:author,:payment,:amount,:note,NOW())', [
                'article'=>$articleId,'reader'=>$readerId,'author'=>$article['author_id'],'payment'=>$paymentId,'amount'=>$amountMinor,'note'=>$note
            ]);
            // Obciążenie czytelnika
            $ledger->post($readerId, 'article_charge', -$amountMinor, 0, 'Wsparcie tekstu #' . $articleId, [
                'source_module'=>'article','counterparty_user_id'=>(int)$article['author_id'],'ref_type'=>'payment','ref_id'=>$paymentId,'idempotency_key'=>'article-support-charge-'.$paymentId
            ]);
            // Przychód autora
            $ledger->post((int)$article['author_id'], 'article_income', $amountMinor, 0, 'Wsparcie tekstu #' . $articleId, [
                'source_module'=>'article','counterparty_user_id'=>$readerId,'ref_type'=>'payment','ref_id'=>$paymentId,'idempotency_key'=>'article-support-income-'.$paymentId
            ]);
            ($this->notificationOutbox ?? $this->fallbackOutbox($db))->articleSupport(
                (int)$article['author_id'],
                $readerId,
                $articleId,
                $paymentId,
                $amountMinor,
            );
            return $paymentId;
        });
    }

    private function fallbackOutbox(Database $db): NotificationOutboxDispatcher
    {
        return new NotificationOutboxDispatcher(
            $db,
            new DurableJobQueue($db),
            new \App\Infrastructure\Valkey\NullQueueSignal(),
            \App\Core\SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
        );
    }
}
