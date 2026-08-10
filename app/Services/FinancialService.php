<?php
namespace App\Services;

use App\Core\Database;
use RuntimeException;
use App\Services\ActivityUiHelper;
use App\Services\PayoutService;
use App\Services\LedgerService;
use App\Core\RequestContext;
use App\Infrastructure\Logging\JsonErrorLogger;
use PDOException;
use Throwable;

final class FinancialService
{
    private readonly LedgerHashService $hashService;

    public function __construct(private readonly Database $db)
    {
        $this->hashService = LedgerHashService::fromEnvironment();
    }

    /** Otwiera atomową operację domenową bez globalnej blokady księgi. */
    public function synchronized(callable $operation): mixed
    {
        return $this->db->transaction($operation);
    }

    /**
     * @param list<int> $userIds
     * @return list<array<string,mixed>>
     */
    public function lockWalletsForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        sort($userIds, SORT_NUMERIC);

        foreach ($userIds as $userId) {
            $this->createWallet($this->db, $userId);
        }

        $params = [];
        $placeholders = [];
        foreach ($userIds as $index => $userId) {
            $key = 'user_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $userId;
        }
        $wallets = $this->db->all(
            'SELECT * FROM wallets WHERE user_id IN (' . implode(',', $placeholders) . ') ORDER BY id FOR UPDATE',
            $params
        );
        if (count($wallets) !== count($userIds)) {
            throw new RuntimeException('Nie udało się zablokować wszystkich portfeli operacji.');
        }
        return $wallets;
    }

    /**
     * Główna metoda księgująca transakcję (Model Bankowy)
     */
    public function postTransaction(int $userId, string $type, int $amountMinor, string $accountType, string $description, array $ctx = []): int
    {
        $this->assertTransactionInput($userId, $type, $accountType, $ctx);

        return $this->db->transaction(function (Database $db) use ($userId, $type, $amountMinor, $accountType, $description, $ctx): int {
            $state = $this->lockLedgerMigrationState($db);
            $mode = (string)$state['mode'];
            $idempotencyKey = $this->nullableString($ctx['idempotency_key'] ?? null, 190);
            $sourceModule = (string)($ctx['source_module'] ?? 'system');
            $status = (string)($ctx['status'] ?? 'posted');
            $balanceType = $accountType === 'points' ? 'available' : (string)($ctx['balance_type'] ?? 'available');

            if ($idempotencyKey !== null) {
                $existing = $db->one('SELECT * FROM wallet_transactions WHERE idempotency_key=:key LIMIT 1', [
                    'key' => $idempotencyKey,
                ]);
                if ($existing) {
                    $this->assertIdempotentMatch($existing, $userId, $type, $amountMinor, $accountType, $balanceType, $sourceModule, $status, $ctx);
                    return (int)$existing['id'];
                }
            }

            if ($idempotencyKey !== null) {
                $completedTransactionId = $this->claimOperation(
                    $db,
                    $idempotencyKey,
                    FinancialOperationFingerprint::calculate(
                        $userId,
                        $type,
                        $amountMinor,
                        $accountType,
                        $balanceType,
                        $sourceModule,
                        $status,
                        $ctx
                    ),
                    $userId,
                    $type,
                    $amountMinor,
                    $accountType,
                    $balanceType,
                    $sourceModule,
                    $status,
                    $ctx
                );
                if ($completedTransactionId !== null) {
                    return $completedTransactionId;
                }
            }

            $wallet = $this->walletForUpdate($db, $userId);
            if ((int)$wallet['is_locked'] === 1) {
                throw new RuntimeException('Portfel użytkownika jest zablokowany: ' . ($wallet['locked_reason'] ?: 'Brak powodu.'));
            }

            if ($accountType === 'points') {
                $balanceField = 'points_balance';
            } else {
                $prefix = $accountType === 'main' ? 'main_' : 'slowo_';
                $balanceField = $prefix . $balanceType . '_minor';
            }

            if (!array_key_exists($balanceField, $wallet)) {
                throw new RuntimeException("Nieprawidłowy typ konta lub salda: $accountType / $balanceType.");
            }

            $balanceBefore = (int)$wallet[$balanceField];
            $balanceAfter = $balanceBefore + $amountMinor;
            if ($balanceAfter < 0 && ($ctx['allow_negative'] ?? false) !== true) {
                throw new RuntimeException("Niewystarczające środki na koncie $accountType ($balanceType).");
            }

            if ($mode === 'legacy_global') {
                $head = $this->lockLedgerHead($db);
            } elseif ($mode === 'per_wallet') {
                $head = $this->lockWalletLedgerHead($db, (int)$wallet['id']);
            } else {
                throw new RuntimeException('Nieobsługiwany tryb księgi finansowej.');
            }
            $previousHash = (string)$head['last_entry_hash'];
            $now = date('Y-m-d H:i:s');
            $keys = ActivityUiHelper::keysFor($type);
            $titleKey = $this->nullableString($ctx['title_key'] ?? $keys['title_key'], 160);
            $messageKey = $this->nullableString($ctx['message_key'] ?? $keys['message_key'], 160);
            $descriptionKey = $this->nullableString($ctx['description_key'] ?? $keys['description_key'], 160);
            $storedDescription = ActivityUiHelper::isMapped($type) ? null : $this->nullableString($description, 255);
            $refType = $this->nullableString($ctx['ref_type'] ?? null, 80);
            $refId = isset($ctx['ref_id']) ? (int)$ctx['ref_id'] : null;
            $counterpartyUserId = isset($ctx['counterparty_user_id']) ? (int)$ctx['counterparty_user_id'] : null;
            $meta = ['balance_type' => $balanceType] + (array)($ctx['meta'] ?? []);
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $txId = $db->insert('INSERT INTO wallet_transactions (
                wallet_id, user_id, source_module, type, account_type, status,
                amount_minor, balance_before_minor, balance_after_minor, points, points_after,
                description, title_key, message_key, description_key,
                counterparty_user_id, ref_type, ref_id, idempotency_key,
                created_at, previous_hash, meta_json, hash_algorithm, hash_version,
                wallet_previous_hash, wallet_hash_algorithm, wallet_hash_version
            ) VALUES (
                :wallet_id, :user_id, :source, :type, :acc_type, :status,
                :amount, :bal_before, :bal_after, :points, :points_after,
                :desc, :title_k, :msg_k, :desc_k,
                :counterparty, :ref_type, :ref_id, :idem,
                :created_at, :legacy_prev_hash, :meta, :legacy_hash_algorithm, :legacy_hash_version,
                :wallet_prev_hash, :wallet_hash_algorithm, :wallet_hash_version
            )', [
                'wallet_id' => $wallet['id'],
                'user_id' => $userId,
                'source' => $sourceModule,
                'type' => $type,
                'acc_type' => $accountType,
                'status' => $status,
                'amount' => $amountMinor,
                'bal_before' => $balanceBefore,
                'bal_after' => $balanceAfter,
                'points' => $accountType === 'points' ? $amountMinor : 0,
                'points_after' => $accountType === 'points' ? $balanceAfter : 0,
                'desc' => $storedDescription,
                'title_k' => $titleKey,
                'msg_k' => $messageKey,
                'desc_k' => $descriptionKey,
                'counterparty' => $counterpartyUserId,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'idem' => $idempotencyKey,
                'created_at' => $now,
                'legacy_prev_hash' => $mode === 'legacy_global' ? $previousHash : null,
                'meta' => $metaJson,
                'legacy_hash_algorithm' => $mode === 'legacy_global' ? LedgerHashService::ALGORITHM : null,
                'legacy_hash_version' => $mode === 'legacy_global' ? LedgerHashService::VERSION : null,
                'wallet_prev_hash' => $mode === 'per_wallet' ? $previousHash : null,
                'wallet_hash_algorithm' => $mode === 'per_wallet' ? LedgerHashService::ALGORITHM : null,
                'wallet_hash_version' => $mode === 'per_wallet' ? LedgerHashService::VERSION : null,
            ]);

            $transaction = [
                'id' => $txId,
                'wallet_id' => $wallet['id'],
                'user_id' => $userId,
                'source_module' => $sourceModule,
                'type' => $type,
                'account_type' => $accountType,
                'status' => $status,
                'amount_minor' => $amountMinor,
                'balance_before_minor' => $balanceBefore,
                'balance_after_minor' => $balanceAfter,
                'description' => $storedDescription,
                'title_key' => $titleKey,
                'message_key' => $messageKey,
                'description_key' => $descriptionKey,
                'counterparty_user_id' => $counterpartyUserId,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'idempotency_key' => $idempotencyKey,
                'meta_json' => $metaJson,
                'created_at' => $now,
            ];
            $entryHash = $this->hashService->sign(
                $transaction,
                (string)($wallet['currency'] ?? 'PLN'),
                $balanceType,
                $previousHash
            );
            if ($mode === 'legacy_global') {
                $db->query('UPDATE wallet_transactions SET entry_hash=:h,signed_at=NOW() WHERE id=:id', [
                    'h' => $entryHash,
                    'id' => $txId,
                ]);
            } else {
                $db->query('UPDATE wallet_transactions SET wallet_entry_hash=:h,wallet_signed_at=NOW() WHERE id=:id', [
                    'h' => $entryHash,
                    'id' => $txId,
                ]);
            }

            $legacyField = null;
            $newLegacyValue = null;
            if ($accountType !== 'points') {
                $legacyField = $balanceType === 'available' ? 'available_minor' : 'reserved_minor';
                $newLegacyValue = (int)$wallet[$legacyField] + $amountMinor;
                if ($newLegacyValue < 0 && ($ctx['allow_negative'] ?? false) !== true) {
                    throw new RuntimeException("Niewystarczające środki w łącznym saldzie $balanceType.");
                }
                $db->query("UPDATE wallets SET $balanceField=:new_balance, $legacyField=:new_legacy, updated_at=NOW() WHERE id=:id", [
                    'new_balance' => $balanceAfter,
                    'new_legacy' => $newLegacyValue,
                    'id' => $wallet['id'],
                ]);
            } else {
                $db->query('UPDATE wallets SET points_balance=:new_balance, updated_at=NOW() WHERE id=:id', [
                    'new_balance' => $balanceAfter,
                    'id' => $wallet['id'],
                ]);
            }

            if ($mode === 'legacy_global') {
                $db->query('UPDATE financial_ledger_head SET last_transaction_id=:tx,last_entry_hash=:hash,hash_version=:version,updated_at=NOW() WHERE id=1', [
                    'tx' => $txId,
                    'hash' => $entryHash,
                    'version' => LedgerHashService::VERSION,
                ]);
            } else {
                $db->query('UPDATE financial_wallet_ledger_heads
                            SET last_transaction_id=:tx,last_entry_hash=:hash,
                                transaction_count=transaction_count+1,hash_version=:version,updated_at=NOW()
                            WHERE wallet_id=:wallet', [
                    'tx' => $txId,
                    'hash' => $entryHash,
                    'version' => LedgerHashService::VERSION,
                    'wallet' => $wallet['id'],
                ]);
            }

            if ($idempotencyKey !== null) {
                $db->query(
                    'UPDATE financial_operations
                     SET status=\'completed\',wallet_id=:wallet,transaction_id=:transaction,completed_at=NOW(),updated_at=NOW()
                     WHERE idempotency_key=:key AND status=\'processing\'',
                    ['wallet' => $wallet['id'], 'transaction' => $txId, 'key' => $idempotencyKey]
                );
            }

            $walletAfter = $wallet;
            $walletAfter[$balanceField] = $balanceAfter;
            if ($legacyField !== null) {
                $walletAfter[$legacyField] = $newLegacyValue;
            }
            $this->logAudit($wallet['id'], $userId, 'transaction', $amountMinor, $wallet, [
                $balanceField => $balanceAfter,
                'updated_at' => $now,
            ], [
                'tx_id' => $txId,
                'type' => $type,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $txId;
        });
    }

    private function logAudit(int $walletId, int $userId, string $action, int $amount, ?array $before, ?array $after, array $context): void
    {
        $actor = $this->actorContext();
        $structuredEvent = [
            'event_type' => 'financial_audit',
            'user_id' => $userId,
            'actor_user_id' => $actor['id'],
            'actor_role' => $actor['role'],
            'operation' => $action,
            'wallet_id' => $walletId,
            'amount_minor' => $amount,
            'ip' => RequestContext::ipAddress(),
            'request_id' => RequestContext::requestId(),
            'result' => 'success',
        ];
        $context['audit'] = $structuredEvent;

        $this->db->query('INSERT INTO financial_audit_log (
            wallet_id, user_id, action, actor_id, actor_role, amount,
            before_json, after_json, context_json, ip_address, user_agent, created_at
        ) VALUES (
            :w, :u, :a, :aid, :ar, :amt, :bj, :aj, :cj, :ip, :ua, NOW()
        )', [
            'w' => $walletId,
            'u' => $userId,
            'a' => $action,
            'aid' => $actor['id'],
            'ar' => $actor['role'],
            'amt' => $amount,
            'bj' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
            'aj' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
            'cj' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $this->nullableString($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
        ]);
        (new JsonErrorLogger())->log('info', 'finance.' . $action, $structuredEvent + [
            'context' => $context,
        ]);
    }

    /**
     * Maker-Checker: Tworzenie zlecenia do zatwierdzenia
     */
    public function requestApproval(
        string $type,
        int $amount,
        string $currency,
        int $walletId,
        int $userId,
        array $payload,
        string $reason,
        ?array $verifiedActor = null,
        string $source = 'web',
        ?string $externalRequestId = null,
        ?string $correlationId = null,
    ): int
    {
        if ($userId <= 0 || trim($type) === '') {
            throw new \InvalidArgumentException('Nieprawidłowe dane zlecenia finansowego.');
        }
        if ($type === 'manual_reward' && (int)($payload['points'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Ręczne naliczenie Talentów musi być większe od zera.');
        }

        $source = mb_substr(strtolower(trim($source)), 0, 40);
        $externalRequestId = $this->nullableString($externalRequestId, 36);
        $correlationId = $this->nullableString($correlationId, 128);
        if ($source === '' || ($source === 'dors3_mobile' && $externalRequestId === null)) {
            throw new \InvalidArgumentException('Źródło mobilne wymaga identyfikatora podpisanego żądania.');
        }

        return $this->synchronized(function (Database $db) use (
            $type,
            $amount,
            $currency,
            $walletId,
            $userId,
            $payload,
            $reason,
            $verifiedActor,
            $source,
            $externalRequestId,
            $correlationId,
        ): int {
            $actor = $verifiedActor === null
                ? $this->actorContext()
                : $this->verifiedActorContext($verifiedActor);
            if ($actor['id'] === 0) {
                throw new RuntimeException('Musisz być zalogowany, aby utworzyć zlecenie finansowe.');
            }
            $requestedRole = $this->requesterRole($actor);
            $wallet = $this->walletForUpdate($db, $userId);
            if ($walletId > 0 && $walletId !== (int)$wallet['id']) {
                throw new RuntimeException('Zlecenie wskazuje portfel innego użytkownika.');
            }

            if ($externalRequestId !== null) {
                $existing = $db->one(
                    'SELECT * FROM financial_approvals WHERE source=:source AND external_request_id=:request FOR UPDATE',
                    ['source' => $source, 'request' => $externalRequestId],
                );
                if ($existing !== null) {
                    if (
                        (string)$existing['operation_type'] !== mb_substr(trim($type), 0, 50)
                        || (int)$existing['amount'] !== $amount
                        || (string)$existing['currency'] !== mb_substr(strtoupper(trim($currency)), 0, 3)
                        || (int)$existing['wallet_id'] !== (int)$wallet['id']
                        || (int)$existing['user_id'] !== $userId
                        || (int)$existing['requested_by'] !== (int)$actor['id']
                    ) {
                        throw new RuntimeException('Identyfikator żądania finansowego został użyty dla innej operacji.');
                    }
                    return (int)$existing['id'];
                }
            }

            return $db->insert('INSERT INTO financial_approvals (
                operation_type, operation_payload, amount, currency, wallet_id, user_id,
                requested_by, requested_role, status, reason, source, external_request_id,
                correlation_id, created_at
            ) VALUES (
                :type, :payload, :amount, :currency, :wid, :uid, :req_by, :req_role, \'pending\', :reason,
                :source, :external_request_id, :correlation_id, NOW()
            )', [
                'type' => mb_substr(trim($type), 0, 50),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'amount' => $amount,
                'currency' => mb_substr(strtoupper(trim($currency)), 0, 3),
                'wid' => $wallet['id'],
                'uid' => $userId,
                'req_by' => $actor['id'],
                'req_role' => $requestedRole,
                'reason' => trim($reason),
                'source' => $source,
                'external_request_id' => $externalRequestId,
                'correlation_id' => $correlationId,
            ]);
        });
    }

    public function approve(int $approvalId, string $note = ''): bool
    {
        if ($approvalId <= 0) {
            throw new \InvalidArgumentException('Brak ID zlecenia finansowego.');
        }

        $executionStarted = false;
        try {
            return $this->synchronized(function (Database $db) use ($approvalId, $note, &$executionStarted): bool {
                $actor = $this->actorContext();
                if ($actor['id'] === 0) {
                    throw new RuntimeException('Musisz być zalogowany, aby zatwierdzić zlecenie.');
                }

                $approval = $db->one('SELECT * FROM financial_approvals WHERE id=:id FOR UPDATE', ['id' => $approvalId]);
                if (!$approval) {
                    throw new RuntimeException('Zlecenie nie istnieje.');
                }
                if ($approval['status'] !== 'pending') {
                    throw new RuntimeException('To zlecenie zostało już przetworzone.');
                }
                $approverRole = $this->checkerRole($approval, $actor);

                $db->query('UPDATE financial_approvals SET status=\'approved\', approved_by=:aid, approved_role=:role, approved_at=NOW(), admin_note=:note WHERE id=:id', [
                    'aid' => $actor['id'],
                    'role' => $approverRole,
                    'note' => trim($note),
                    'id' => $approvalId,
                ]);
                $executionStarted = true;
                $payload = json_decode((string)$approval['operation_payload'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new RuntimeException('Zlecenie zawiera nieprawidłowy payload.');
                }

                if ($approval['operation_type'] === 'payout_status_update') {
                    $payoutService = new PayoutService($db);
                    $payoutService->setStatus(
                        (int)($payload['payout_id'] ?? 0),
                        (string)($payload['target_status'] ?? ''),
                        (string)($payload['admin_note'] ?? '') . " (zatwierdził użytkownik #{$actor['id']})",
                        $actor['id']
                    );
                } elseif ($approval['operation_type'] === 'manual_reward') {
                    $ledger = new LedgerService($db, $this);
                    $ledger->post(
                        (int)$approval['user_id'],
                        'manual_reward',
                        0,
                        (int)($payload['points'] ?? 0),
                        (string)($payload['description'] ?? 'Ręczna korekta'),
                        [
                            'account_type' => 'points',
                            'source_module' => 'admin',
                            'ref_type' => 'financial_approval',
                            'ref_id' => $approvalId,
                            'idempotency_key' => "financial-approval-$approvalId:manual-reward",
                        ]
                    );
                } else {
                    $this->postTransaction(
                        (int)$approval['user_id'],
                        (string)$approval['operation_type'],
                        (int)$approval['amount'],
                        (string)($payload['account_type'] ?? 'main'),
                        (string)$approval['reason'] . " (zatwierdził użytkownik #{$actor['id']})",
                        $payload + [
                            'source_module' => 'admin',
                            'ref_type' => 'financial_approval',
                            'ref_id' => $approvalId,
                            'idempotency_key' => "financial-approval-$approvalId:execution",
                        ]
                    );
                }

                $db->query('UPDATE financial_approvals SET status=\'executed\', executed_at=NOW() WHERE id=:id', ['id' => $approvalId]);
                return true;
            });
        } catch (Throwable $e) {
            if ($executionStarted) {
                $this->markApprovalFailed($approvalId, $e);
            }
            throw $e;
        }
    }

    public function reject(int $approvalId, string $reason): bool
    {
        if ($approvalId <= 0) {
            throw new \InvalidArgumentException('Brak ID zlecenia finansowego.');
        }

        return $this->synchronized(function (Database $db) use ($approvalId, $reason): bool {
            $actor = $this->actorContext();
            if ($actor['id'] === 0) {
                throw new RuntimeException('Musisz być zalogowany.');
            }
            $approval = $db->one('SELECT * FROM financial_approvals WHERE id=:id FOR UPDATE', ['id' => $approvalId]);
            if (!$approval) {
                throw new RuntimeException('Zlecenie nie istnieje.');
            }
            if ($approval['status'] !== 'pending') {
                throw new RuntimeException('To zlecenie zostało już przetworzone.');
            }
            $checkerRole = $this->checkerRole($approval, $actor);
            $statement = $db->query('UPDATE financial_approvals SET status=\'rejected\', approved_by=:aid, approved_role=:role, rejected_at=NOW(), reject_reason=:reason WHERE id=:id AND status=\'pending\'', [
                'aid' => $actor['id'],
                'role' => $checkerRole,
                'reason' => trim($reason),
                'id' => $approvalId,
            ]);
            return $statement->rowCount() === 1;
        });
    }

    private function lockLedgerMigrationState(Database $db): array
    {
        try {
            $state = $db->one('SELECT * FROM financial_ledger_migration_state WHERE id=1 FOR SHARE');
        } catch (Throwable $error) {
            throw new RuntimeException('Brak migracji ETAPU 7. Uruchom migracje bazy przed księgowaniem.', 0, $error);
        }
        if (!$state) {
            throw new RuntimeException('Brak stanu migracji księgi finansowej.');
        }
        return $state;
    }

    private function claimOperation(
        Database $db,
        string $key,
        string $requestHash,
        int $userId,
        string $type,
        int $amountMinor,
        string $accountType,
        string $balanceType,
        string $sourceModule,
        string $status,
        array $context,
    ): ?int {
        $statement = $db->query(
            $db->isPostgres()
                ? 'INSERT INTO financial_operations(idempotency_key,request_hash,status,created_at,updated_at)
                   VALUES(:key,:hash,\'processing\',NOW(),NOW()) ON CONFLICT(idempotency_key) DO NOTHING'
                : 'INSERT IGNORE INTO financial_operations(idempotency_key,request_hash,status,created_at,updated_at)
                   VALUES(:key,:hash,\'processing\',NOW(),NOW())',
            ['key' => $key, 'hash' => $requestHash]
        );
        $claimed = $statement->rowCount() === 1;
        $operation = $db->one('SELECT * FROM financial_operations WHERE idempotency_key=:key FOR UPDATE', ['key' => $key]);
        if (!$operation) {
            throw new RuntimeException('Nie udało się zarejestrować operacji idempotentnej.');
        }
        if (!hash_equals((string)$operation['request_hash'], $requestHash)) {
            throw new RuntimeException('Klucz idempotencji został już użyty dla innej operacji.');
        }
        if (isset($operation['transaction_id'])) {
            $transaction = $db->one('SELECT * FROM wallet_transactions WHERE id=:id', ['id' => $operation['transaction_id']]);
            if (!$transaction) {
                throw new RuntimeException('Operacja idempotentna wskazuje brakującą transakcję.');
            }
            $this->assertIdempotentMatch(
                $transaction,
                $userId,
                $type,
                $amountMinor,
                $accountType,
                $balanceType,
                $sourceModule,
                $status,
                $context
            );
            return (int)$transaction['id'];
        }
        if (!$claimed) {
            throw new RuntimeException('Niedokończona operacja idempotentna wymaga kontroli administracyjnej.');
        }
        return null;
    }

    private function lockWalletLedgerHead(Database $db, int $walletId): array
    {
        $params = [
            'wallet' => $walletId,
            'genesis' => LedgerHashService::GENESIS_HASH,
            'version' => LedgerHashService::VERSION,
        ];
        $db->query(
            $db->isPostgres()
                ? 'INSERT INTO financial_wallet_ledger_heads(wallet_id,last_transaction_id,last_entry_hash,transaction_count,hash_version,updated_at)
                   VALUES(:wallet,NULL,:genesis,0,:version,NOW()) ON CONFLICT(wallet_id) DO NOTHING'
                : 'INSERT IGNORE INTO financial_wallet_ledger_heads(wallet_id,last_transaction_id,last_entry_hash,transaction_count,hash_version,updated_at)
                   VALUES(:wallet,NULL,:genesis,0,:version,NOW())',
            $params
        );
        $head = $db->one('SELECT * FROM financial_wallet_ledger_heads WHERE wallet_id=:wallet FOR UPDATE', ['wallet' => $walletId]);
        if (!$head) {
            throw new RuntimeException("Brak głowy księgi portfela #$walletId.");
        }
        $latest = $db->one(
            'SELECT id,wallet_entry_hash FROM wallet_transactions WHERE wallet_id=:wallet ORDER BY id DESC LIMIT 1',
            ['wallet' => $walletId]
        );
        if ($head['last_transaction_id'] === null) {
            if ($latest) {
                throw new RuntimeException("Pusta głowa portfela #$walletId nie odpowiada jego historii.");
            }
            if (!hash_equals(LedgerHashService::GENESIS_HASH, (string)$head['last_entry_hash'])) {
                throw new RuntimeException("Portfel #$walletId ma nieprawidłowy hash początkowy.");
            }
        } elseif (
            !$latest
            || (int)$latest['id'] !== (int)$head['last_transaction_id']
            || !hash_equals((string)$latest['wallet_entry_hash'], (string)$head['last_entry_hash'])
        ) {
            throw new RuntimeException("Głowa księgi portfela #$walletId jest niespójna. Księgowanie zatrzymano.");
        }
        return $head;
    }

    private function lockLedgerHead(Database $db): array
    {
        try {
            $head = $db->one('SELECT id,last_transaction_id,last_entry_hash,hash_version FROM financial_ledger_head WHERE id=1 FOR UPDATE');
        } catch (Throwable $e) {
            throw new RuntimeException('Brak tabeli financial_ledger_head. Uruchom migracje bazy przed operacjami finansowymi.', 0, $e);
        }
        if (!$head) {
            throw new RuntimeException('Brak wiersza głowy księgi finansowej.');
        }

        $lastTransactionId = isset($head['last_transaction_id']) ? (int)$head['last_transaction_id'] : null;
        $latest = $db->one('SELECT id,entry_hash FROM wallet_transactions ORDER BY id DESC LIMIT 1');
        if ($lastTransactionId === null) {
            if ($latest) {
                throw new RuntimeException('Głowa księgi jest pusta, ale księga zawiera transakcje.');
            }
            if ((string)$head['last_entry_hash'] !== LedgerHashService::GENESIS_HASH) {
                throw new RuntimeException('Nieprawidłowy hash początkowy księgi.');
            }
        } elseif (
            !$latest
            || (int)$latest['id'] !== $lastTransactionId
            || !hash_equals((string)$latest['entry_hash'], (string)$head['last_entry_hash'])
        ) {
            throw new RuntimeException('Głowa księgi nie odpowiada ostatniej transakcji. Księgowanie zostało zatrzymane.');
        }

        return $head;
    }

    private function walletForUpdate(Database $db, int $userId): array
    {
        $wallet = $db->one('SELECT * FROM wallets WHERE user_id=:id FOR UPDATE', ['id' => $userId]);
        if (!$wallet) {
            try {
                $this->createWallet($db, $userId);
            } catch (PDOException $e) {
                if (!$this->isUniqueViolation($e)) {
                    throw $e;
                }
            }
            $wallet = $db->one('SELECT * FROM wallets WHERE user_id=:id FOR UPDATE', ['id' => $userId]);
        }
        if (!$wallet) {
            throw new RuntimeException('Nie udało się utworzyć ani zablokować portfela użytkownika.');
        }
        return $wallet;
    }

    private function createWallet(Database $db, int $userId): void
    {
        $sql = 'INSERT INTO wallets(user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at) VALUES(:id,0,0,0,0,0,0,0,0,\'PLN\',NOW())';
        $sql .= $db->isPostgres()
            ? ' ON CONFLICT (user_id) DO NOTHING'
            : ' ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)';
        $db->query($sql, [
            'id' => $userId,
        ]);
    }

    private function isUniqueViolation(PDOException $error): bool
    {
        return in_array((string)$error->getCode(), ['23000', '23505'], true);
    }

    private function assertTransactionInput(int $userId, string $type, string $accountType, array $ctx): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Nieprawidłowy użytkownik transakcji.');
        }
        if (!preg_match('/^[a-z0-9_]{1,80}$/', $type)) {
            throw new \InvalidArgumentException('Nieprawidłowy typ transakcji.');
        }
        if (!in_array($accountType, ['main', 'slowo', 'points'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy typ konta.');
        }
        $balanceType = (string)($ctx['balance_type'] ?? 'available');
        if ($accountType !== 'points' && !in_array($balanceType, ['available', 'reserved'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy typ salda.');
        }
        if (!in_array((string)($ctx['status'] ?? 'posted'), ['pending', 'posted', 'reserved', 'cancelled', 'failed'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy status transakcji.');
        }
        if (!preg_match('/^[a-z0-9_]{1,40}$/', (string)($ctx['source_module'] ?? 'system'))) {
            throw new \InvalidArgumentException('Nieprawidłowe źródło transakcji.');
        }
    }

    private function assertIdempotentMatch(array $existing, int $userId, string $type, int $amountMinor, string $accountType, string $balanceType, string $sourceModule, string $status, array $ctx): void
    {
        $meta = json_decode((string)($existing['meta_json'] ?? '{}'), true);
        $matches = (int)$existing['user_id'] === $userId
            && (string)$existing['type'] === $type
            && (int)$existing['amount_minor'] === $amountMinor
            && (string)$existing['account_type'] === $accountType
            && (string)$existing['status'] === $status
            && (string)$existing['source_module'] === $sourceModule
            && (string)($meta['balance_type'] ?? 'available') === $balanceType
            && (string)($existing['ref_type'] ?? '') === (string)($ctx['ref_type'] ?? '')
            && (int)($existing['ref_id'] ?? 0) === (int)($ctx['ref_id'] ?? 0);
        if (!$matches) {
            throw new RuntimeException('Klucz idempotencji został już użyty dla innej operacji.');
        }
    }

    private function actorContext(): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['id' => 0, 'role' => 'system', 'roles' => []];
        }
        $rows = $this->db->all('SELECT role FROM user_roles WHERE user_id=:id ORDER BY role', ['id' => $userId]);
        $roles = [];
        foreach ($rows as $row) {
            $role = $this->normalizeFinancialRole((string)$row['role']);
            if ($role !== null) {
                $roles[$role] = true;
            }
        }
        $roles = array_keys($roles);
        $sessionRole = $this->normalizeFinancialRole((string)($_SESSION['role'] ?? ''));
        $auditRole = $sessionRole !== null && in_array($sessionRole, $roles, true)
            ? $sessionRole
            : ($roles[0] ?? 'user');
        return ['id' => $userId, 'role' => $auditRole, 'roles' => $roles];
    }

    /** @param array<string,mixed> $verifiedActor @return array{id:int,role:string,roles:list<string>} */
    private function verifiedActorContext(array $verifiedActor): array
    {
        $userId = (int)($verifiedActor['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Zweryfikowany aktor mobilny nie ma poprawnego identyfikatora.');
        }
        $user = $this->db->one('SELECT status FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if ($user === null || (string)$user['status'] !== 'active') {
            throw new RuntimeException('Zweryfikowany aktor mobilny nie ma aktywnego konta.');
        }
        $roles = [];
        foreach ($this->db->all('SELECT role FROM user_roles WHERE user_id=:id ORDER BY role', ['id' => $userId]) as $row) {
            $role = $this->normalizeFinancialRole((string)$row['role']);
            if ($role !== null) {
                $roles[$role] = true;
            }
        }
        $roles = array_keys($roles);
        $claimedRole = $this->normalizeFinancialRole((string)($verifiedActor['role'] ?? ''));
        if ($claimedRole === null || !in_array($claimedRole, $roles, true)) {
            throw new RuntimeException('Rola zweryfikowanego aktora nie zgadza się z rolami konta.');
        }
        return ['id' => $userId, 'role' => $claimedRole, 'roles' => $roles];
    }

    private function requesterRole(array $actor): string
    {
        if (in_array($actor['role'], ['admin', 'publisher'], true) && in_array($actor['role'], $actor['roles'], true)) {
            return $actor['role'];
        }
        if (in_array('admin', $actor['roles'], true)) {
            return 'admin';
        }
        if (in_array('publisher', $actor['roles'], true)) {
            return 'publisher';
        }
        throw new RuntimeException('Zlecenie finansowe może utworzyć tylko Administrator lub Wydawca.');
    }

    private function checkerRole(array $approval, array $actor): string
    {
        if ((int)$approval['requested_by'] === (int)$actor['id']) {
            throw new RuntimeException('Nie możesz rozstrzygnąć własnego zlecenia (Maker–Checker).');
        }
        $requesterRole = $this->normalizeFinancialRole((string)$approval['requested_role']);
        $requiredRole = $requesterRole === 'admin' ? 'publisher' : ($requesterRole === 'publisher' ? 'admin' : null);
        if ($requiredRole === null || !in_array($requiredRole, $actor['roles'], true)) {
            throw new RuntimeException('Rozstrzygnięcie wymaga pary ról Administrator–Wydawca.');
        }
        return $requiredRole;
    }

    private function normalizeFinancialRole(string $role): ?string
    {
        return match (strtolower(trim($role))) {
            'admin', 'administrator' => 'admin',
            'publisher', 'wydawca' => 'publisher',
            default => null,
        };
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function markApprovalFailed(int $approvalId, Throwable $error): void
    {
        try {
            $this->db->query('UPDATE financial_approvals SET status=\'failed\', reject_reason=:error WHERE id=:id AND status=\'pending\'', [
                'error' => mb_substr($error->getMessage(), 0, 2000),
                'id' => $approvalId,
            ]);
        } catch (Throwable) {
            error_log("Nie udało się utrwalić błędu zlecenia finansowego #$approvalId.");
        }
    }
}
