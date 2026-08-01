<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class SelectedContentMigrationService
{
    private const GENESIS = LedgerHashService::GENESIS_HASH;

    /** @param array<string,mixed> $manifest */
    public function __construct(
        private readonly PDO $source,
        private readonly Database $target,
        private readonly array $manifest,
    ) {}

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $this->assertManifestCoverage();
        $admin = $this->sourceOne('SELECT * FROM users WHERE id=:id', ['id' => $this->adminId()]);
        if (!$admin || (int)$admin['legacy_id'] !== (int)$this->manifest['source_admin_legacy_id']) {
            throw new RuntimeException('Nie znaleziono jednoznacznie wskazanego administratora źródłowego.');
        }
        $wallet = $this->sourceOne('SELECT * FROM wallets WHERE user_id=:id', ['id' => $this->adminId()]);
        if (!$wallet) {
            throw new RuntimeException('Administrator źródłowy nie ma portfela.');
        }

        $ledger = $this->verifySourceGlobalLedger();
        $adminTransactions = (int)$this->sourceCell(
            'SELECT COUNT(*) FROM wallet_transactions WHERE user_id=:id',
            ['id' => $this->adminId()]
        );
        $translations = $this->translationInventory();

        return [
            'ok' => $ledger['ok'],
            'manifest_hash' => $this->manifestHash(),
            'source' => [
                'database' => (string)$this->manifest['_source_database'],
                'admin' => [
                    'id' => (int)$admin['id'],
                    'legacy_id' => (int)$admin['legacy_id'],
                    'email' => (string)$admin['email'],
                    'display_name' => (string)$admin['display_name'],
                    'status' => (string)$admin['status'],
                ],
                'content_counts' => $this->sourceContentCounts(),
                'translations' => $translations,
                'public_routes' => $this->sourcePublicRoutes(),
                'route_diagnostics' => $this->routeDiagnostics($this->sourcePublicRoutes()),
                'wallet' => [
                    'id' => (int)$wallet['id'],
                    'main_available_minor' => (int)$wallet['main_available_minor'],
                    'main_reserved_minor' => (int)$wallet['main_reserved_minor'],
                    'slowo_available_minor' => (int)$wallet['slowo_available_minor'],
                    'slowo_reserved_minor' => (int)$wallet['slowo_reserved_minor'],
                    'points_balance' => (int)$wallet['points_balance'],
                    'transaction_count' => $adminTransactions,
                ],
                'global_ledger' => $ledger,
            ],
            'target_before' => $this->targetInventory(),
            'decisions' => [
                'content' => 'copy_all_selected_content_and_all_existing_translations',
                'article_authors' => 'archive_original_and_reassign_to_admin',
                'legacy_wallet_transactions' => 'immutable_archive_only',
                'active_wallet' => 'single_audited_opening_transaction',
                'opening_points' => $this->openingPoints(),
                'other_users_and_wallets' => 'not_imported',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function import(string $mode): array
    {
        if (!in_array($mode, ['dry-run', 'apply', 'resume'], true)) {
            throw new RuntimeException('Nieprawidłowy tryb selektywnej migracji.');
        }
        $plan = $this->plan();
        if (!$plan['ok']) {
            throw new RuntimeException('Globalny łańcuch źródłowy nie przeszedł weryfikacji HMAC.');
        }

        $this->target->cell(
            'SELECT pg_advisory_lock(hashtextextended(:name,0))',
            ['name' => 'zrodlo_slowa_selected_admin_restore']
        );
        $runId = null;
        try {
            if ($mode === 'resume' && $this->isCompletedTarget()) {
                return $this->validateCompletedTarget($plan, true);
            }

            $this->assertTargetReady();
            $publicId = sprintf('selected-admin-%s-%s', gmdate('YmdHis'), bin2hex(random_bytes(4)));
            $runId = $this->target->insert(
                'INSERT INTO selected_migration_runs(public_id,mode,source_database,source_admin_id,source_manifest_hash,financial_approval,status,started_at)
                 VALUES(:public_id,:mode,:database,:admin,:manifest,:approval,\'running\',NOW())',
                [
                    'public_id' => $publicId,
                    'mode' => $mode,
                    'database' => (string)$this->manifest['_source_database'],
                    'admin' => $this->adminId(),
                    'manifest' => $this->manifestHash(),
                    'approval' => (string)$this->manifest['financial_approval'],
                ]
            );

            $report = $this->target->transaction(function () use ($runId, $plan): array {
                $this->removeKnownEmptySeedState();
                $counts = $this->copySelectedData($runId);
                $finance = $this->openAdministratorWallet($runId, $plan);
                $this->advanceSequences();
                return $this->validateCompletedTarget($plan, false) + [
                    'run_id' => $runId,
                    'copied_counts' => $counts,
                    'finance' => $finance,
                ];
            });

            $this->target->query(
                'UPDATE selected_migration_runs
                 SET status=\'completed\',report_json=:report,completed_at=NOW()
                 WHERE id=:id',
                ['report' => $this->json($report), 'id' => $runId]
            );
            return $report;
        } catch (Throwable $error) {
            if ($runId !== null) {
                $this->target->query(
                    'UPDATE selected_migration_runs
                     SET status=\'failed\',report_json=:report,completed_at=NOW()
                     WHERE id=:id AND status=\'running\'',
                    ['report' => $this->json(['ok' => false, 'error' => $error->getMessage()]), 'id' => $runId]
                );
            }
            throw $error;
        } finally {
            $this->target->cell(
                'SELECT pg_advisory_unlock(hashtextextended(:name,0))',
                ['name' => 'zrodlo_slowa_selected_admin_restore']
            );
        }
    }

    private function assertManifestCoverage(): void
    {
        $statement = $this->source->prepare(
            'SELECT table_name FROM information_schema.tables
             WHERE table_schema=:schema AND table_type=\'BASE TABLE\' ORDER BY table_name'
        );
        $statement->execute(['schema' => (string)$this->manifest['_source_database']]);
        $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $classified = array_keys((array)$this->manifest['tables']);
        sort($classified, SORT_STRING);
        if ($actual !== $classified) {
            throw new RuntimeException('Manifest nie klasyfikuje dokładnie wszystkich tabel źródłowych: ' . $this->json([
                'unclassified' => array_values(array_diff($actual, $classified)),
                'missing_in_source' => array_values(array_diff($classified, $actual)),
            ]));
        }
    }

    /** @return array<string,mixed> */
    private function verifySourceGlobalLedger(): array
    {
        $hashes = LedgerHashService::fromEnvironment();
        $rows = $this->sourceAll(
            'SELECT wt.*,w.currency FROM wallet_transactions wt
             JOIN wallets w ON w.id=wt.wallet_id ORDER BY wt.id'
        );
        $previous = self::GENESIS;
        $errors = [];
        $last = null;
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $storedPrevious = (string)($row['previous_hash'] ?? '');
            $storedHash = (string)($row['entry_hash'] ?? '');
            if (!hash_equals($previous, $storedPrevious)) {
                $errors[] = "Transakcja źródłowa #{$id} przerywa globalny łańcuch.";
            }
            $meta = json_decode((string)($row['meta_json'] ?? '{}'), true);
            $balanceType = is_array($meta) ? (string)($meta['balance_type'] ?? 'available') : 'available';
            $version = (int)($row['hash_version'] ?? 1);
            $valid = $version >= LedgerHashService::VERSION
                ? $hashes->verifyStored($row, (string)$row['currency'], $balanceType, $storedPrevious, $storedHash)
                : $hashes->verifyLegacyV1($row, (string)$row['currency'], $balanceType, $storedPrevious, $storedHash);
            if (!$valid) {
                $errors[] = "Transakcja źródłowa #{$id} ma nieprawidłowy podpis HMAC.";
            }
            if ($storedHash !== '') {
                $previous = $storedHash;
            }
            $last = $row;
        }
        $head = $this->sourceOne('SELECT * FROM financial_ledger_head WHERE id=1');
        if (!$head || ($last !== null && (
            (int)$head['last_transaction_id'] !== (int)$last['id']
            || !hash_equals((string)$head['last_entry_hash'], (string)$last['entry_hash'])
        ))) {
            $errors[] = 'Głowa źródłowej księgi nie odpowiada ostatniej transakcji.';
        }
        return [
            'ok' => $errors === [],
            'transaction_count' => count($rows),
            'last_transaction_id' => $last !== null ? (int)$last['id'] : null,
            'last_entry_hash' => $last !== null ? (string)$last['entry_hash'] : self::GENESIS,
            'errors' => $errors,
        ];
    }

    /** @return array<string,int> */
    private function copySelectedData(int $runId): array
    {
        $adminId = $this->adminId();
        $counts = [];

        $admin = $this->sourceOne('SELECT * FROM users WHERE id=:id', ['id' => $adminId]);
        if (!$admin) {
            throw new RuntimeException('Brak administratora podczas importu.');
        }
        foreach (['status' => 'active', 'can_write' => 1, 'talent_enabled' => 1, 'wallet_enabled' => 1, 'payout_enabled' => 1,
                     'deleted_at' => null, 'deleted_by_admin_id' => null, 'deletion_mode' => 'none', 'anonymized_at' => null,
                     'article_submit_blocked_until' => null, 'article_submit_block_reason' => null,
                     'article_submit_blocked_by' => null, 'article_submit_blocked_at' => null] as $key => $value) {
            $admin[$key] = $value;
        }
        $admin['session_version'] = (int)$admin['session_version'] + 1;
        $counts['users'] = $this->copyRows('users', [$admin]);
        $this->target->query('INSERT INTO user_roles(user_id,role) VALUES(:id,\'admin\'),(:id,\'author\')', ['id' => $adminId]);
        $counts['user_roles'] = 2;
        $counts['user_profiles'] = $this->copyRows('user_profiles', $this->sourceAll(
            'SELECT * FROM user_profiles WHERE user_id=:id ORDER BY id', ['id' => $adminId]
        ));

        $counts['categories'] = $this->copyRows('categories', $this->sourceAll('SELECT * FROM categories ORDER BY id'));
        $counts['category_translations'] = $this->copyRows('category_translations', $this->sourceAll('SELECT * FROM category_translations ORDER BY id'));

        $articles = $this->sourceAll('SELECT * FROM articles ORDER BY id');
        foreach ($articles as &$article) {
            $this->target->query(
                'INSERT INTO selected_migration_article_authors(run_id,source_article_id,original_author_id,target_admin_id)
                 VALUES(:run,:article,:original,:admin)',
                ['run' => $runId, 'article' => $article['id'], 'original' => $article['author_id'], 'admin' => $adminId]
            );
            $article['author_id'] = $adminId;
            if ($article['valued_by_admin_id'] !== null) {
                $article['valued_by_admin_id'] = $adminId;
            }
        }
        unset($article);
        $counts['articles'] = $this->copyRows('articles', $articles);
        $counts['article_categories'] = $this->copyRows('article_categories', $this->sourceAll('SELECT * FROM article_categories ORDER BY article_id,category_id'));
        $counts['article_versions'] = $this->copyRows('article_versions', $this->sourceAll('SELECT * FROM article_versions ORDER BY id'));

        $translations = $this->sourceAll('SELECT * FROM article_translations ORDER BY id');
        foreach ($translations as &$translation) {
            $translation['ai_job_id'] = null;
            foreach (['created_by', 'updated_by', 'reviewed_by', 'published_by'] as $column) {
                if ($translation[$column] !== null) {
                    $translation[$column] = $adminId;
                }
            }
        }
        unset($translation);
        $counts['article_translations'] = $this->copyRows('article_translations', $translations);

        $translationVersions = $this->sourceAll('SELECT * FROM article_translation_versions ORDER BY id');
        foreach ($translationVersions as &$version) {
            if ($version['changed_by'] !== null) {
                $version['changed_by'] = $adminId;
            }
        }
        unset($version);
        $counts['article_translation_versions'] = $this->copyRows('article_translation_versions', $translationVersions);

        $events = $this->sourceAll('SELECT * FROM article_events ORDER BY id');
        foreach ($events as &$event) {
            if ($event['user_id'] !== null) {
                $event['user_id'] = $adminId;
            }
        }
        unset($event);
        $counts['article_events'] = $this->copyRows('article_events', $events);

        $counts['main_banners'] = $this->copyRows('main_banners', $this->sourceAll('SELECT * FROM main_banners ORDER BY id'));
        $counts['main_banner_translations'] = $this->copyRows('main_banner_translations', $this->sourceAll('SELECT * FROM main_banner_translations ORDER BY id'));

        $surveys = $this->sourceAll('SELECT * FROM surveys ORDER BY id');
        foreach ($surveys as &$survey) {
            if ($survey['created_by_admin_id'] !== null) {
                $survey['created_by_admin_id'] = $adminId;
            }
        }
        unset($survey);
        $counts['surveys'] = $this->copyRows('surveys', $surveys);
        $counts['survey_questions'] = $this->copyRows('survey_questions', $this->sourceAll('SELECT * FROM survey_questions ORDER BY id'));

        $campaigns = $this->sourceAll('SELECT * FROM campaigns ORDER BY id');
        foreach ($campaigns as &$campaign) {
            if ($campaign['created_by_admin_id'] !== null) {
                $campaign['created_by_admin_id'] = $adminId;
            }
        }
        unset($campaign);
        $counts['campaigns'] = $this->copyRows('campaigns', $campaigns);

        $media = $this->sourceAll('SELECT * FROM media ORDER BY id');
        foreach ($media as &$item) {
            if ($item['owner_user_id'] !== null) {
                $item['owner_user_id'] = $adminId;
            }
        }
        unset($item);
        $counts['media'] = $this->copyRows('media', $media);

        $allowed = array_values((array)$this->manifest['presentation_settings']);
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $statement = $this->source->prepare("SELECT * FROM settings WHERE name IN ({$placeholders}) ORDER BY name");
        $statement->execute($allowed);
        $settings = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($settings as $setting) {
            $this->target->query(
                'INSERT INTO settings(name,value,updated_at) VALUES(:name,:value,:updated)
                 ON CONFLICT(name) DO UPDATE SET value=EXCLUDED.value,updated_at=EXCLUDED.updated_at',
                ['name' => $setting['name'], 'value' => $setting['value'], 'updated' => $setting['updated_at']]
            );
        }
        $counts['settings'] = count($settings);
        return $counts;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function openAdministratorWallet(int $runId, array $plan): array
    {
        $adminId = $this->adminId();
        $sourceWallet = $this->sourceOne('SELECT * FROM wallets WHERE user_id=:id', ['id' => $adminId]);
        if (!$sourceWallet) {
            throw new RuntimeException('Brak portfela źródłowego.');
        }
        $this->target->query(
            'INSERT INTO wallets(id,user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,
                available_minor,pending_minor,reserved_minor,points_balance,currency,legacy_wallet_id,legacy_wallet_name,
                created_at,updated_at,is_locked,locked_reason,locked_at,locked_by)
             VALUES(:id,:user,0,0,0,0,0,0,0,0,:currency,:legacy_id,:legacy_name,:created,:updated,0,NULL,NULL,NULL)',
            [
                'id' => $sourceWallet['id'], 'user' => $adminId, 'currency' => $sourceWallet['currency'],
                'legacy_id' => $sourceWallet['id'], 'legacy_name' => 'selective-source-wallet',
                'created' => $sourceWallet['created_at'], 'updated' => $sourceWallet['updated_at'],
            ]
        );

        $sourceTransactions = $this->sourceAll(
            'SELECT * FROM wallet_transactions WHERE user_id=:id ORDER BY id', ['id' => $adminId]
        );
        foreach ($sourceTransactions as $transaction) {
            $json = $this->canonicalJson($transaction);
            $this->target->query(
                'INSERT INTO selected_migration_legacy_wallet_transactions(
                    run_id,source_transaction_id,source_wallet_id,source_user_id,original_previous_hash,original_entry_hash,
                    original_hash_algorithm,original_hash_version,source_row_json,source_row_checksum,archived_at
                 ) VALUES(:run,:tx,:wallet,:user,:previous,:entry,:algorithm,:version,:row,:checksum,NOW())',
                [
                    'run' => $runId, 'tx' => $transaction['id'], 'wallet' => $transaction['wallet_id'],
                    'user' => $transaction['user_id'], 'previous' => $transaction['previous_hash'],
                    'entry' => $transaction['entry_hash'], 'algorithm' => $transaction['hash_algorithm'],
                    'version' => $transaction['hash_version'], 'row' => $json, 'checksum' => hash('sha256', $json),
                ]
            );
        }

        $ledgerReport = [
            'strategy' => 'selective_archive_and_new_wallet_chain',
            'approval' => (string)$this->manifest['financial_approval'],
            'source_global_ledger' => $plan['source']['global_ledger'],
            'archived_admin_transactions' => count($sourceTransactions),
            'opening_points' => $this->openingPoints(),
            'manifest_hash' => $this->manifestHash(),
        ];
        $ledgerReportJson = $this->canonicalJson($ledgerReport);
        $this->target->query(
            'UPDATE financial_ledger_head SET last_transaction_id=NULL,last_entry_hash=:genesis,hash_version=2,updated_at=NOW() WHERE id=1',
            ['genesis' => self::GENESIS]
        );
        $this->target->query(
            'UPDATE financial_ledger_migration_state
             SET mode=\'per_wallet\',legacy_cutover_transaction_id=NULL,compliance_report_json=:report,
                 compliance_report_hash=:hash,verified_at=NOW(),activated_at=NOW(),updated_at=NOW()
             WHERE id=1',
            ['report' => $ledgerReportJson, 'hash' => hash('sha256', $ledgerReportJson)]
        );

        $ledger = new LedgerService($this->target, new FinancialService($this->target));
        $transactionId = $ledger->post(
            $adminId,
            'selective_migration_opening',
            0,
            $this->openingPoints(),
            'Kontrolowane otwarcie portfela po selektywnej migracji.',
            [
                'account_type' => 'points',
                'source_module' => 'selected_migration',
                'idempotency_key' => 'selected-migration-admin-4-opening-v1',
                'ref_type' => 'selected_migration_run',
                'ref_id' => $runId,
                'meta' => [
                    'approval' => (string)$this->manifest['financial_approval'],
                    'source_wallet_id' => (int)$sourceWallet['id'],
                    'source_transaction_count' => count($sourceTransactions),
                    'source_global_last_transaction_id' => $plan['source']['global_ledger']['last_transaction_id'],
                    'source_global_last_entry_hash' => $plan['source']['global_ledger']['last_entry_hash'],
                    'manifest_hash' => $this->manifestHash(),
                ],
            ]
        );

        $this->target->query(
            'INSERT INTO financial_audit_log(wallet_id,user_id,action,actor_id,actor_role,amount,before_json,after_json,context_json,created_at)
             VALUES(:wallet,:user,\'selective_migration_opening\',:actor,\'admin\',:amount,:before,:after,:context,NOW())',
            [
                'wallet' => $sourceWallet['id'], 'user' => $adminId, 'actor' => $adminId,
                'amount' => $this->openingPoints(), 'before' => $this->json(['points_balance' => 0]),
                'after' => $this->json(['points_balance' => $this->openingPoints()]),
                'context' => $ledgerReportJson,
            ]
        );
        $this->target->query(
            'INSERT INTO admin_audit_logs(user_id,action,payload,created_at)
             VALUES(:user,\'selected_content_admin_restore\',:payload,NOW())',
            ['user' => $adminId, 'payload' => $ledgerReportJson]
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $anchor = (new LedgerAnchorService($this->target))->create($now->modify('-1 minute'), $now);
        $integrity = (new LedgerIntegrityService($this->target))->verify(true);
        if (!$integrity['ok']) {
            throw new RuntimeException('Nowy łańcuch portfela nie przeszedł weryfikacji: ' . $this->json($integrity['errors']));
        }
        return [
            'source_transactions_archived' => count($sourceTransactions),
            'opening_transaction_id' => $transactionId,
            'opening_points' => $this->openingPoints(),
            'anchor_id' => (int)$anchor['id'],
            'integrity' => $integrity,
        ];
    }

    private function removeKnownEmptySeedState(): void
    {
        if ((int)$this->target->cell('SELECT COUNT(*) FROM wallet_transactions') !== 0) {
            throw new RuntimeException('Docelowa księga nie jest pusta.');
        }
        $this->target->query('DELETE FROM financial_ledger_anchors');
        $this->target->query('DELETE FROM financial_operations');
        $this->target->query('DELETE FROM financial_wallet_ledger_heads');
        $this->target->query('DELETE FROM users');
        $this->target->query(
            'UPDATE financial_ledger_head SET last_transaction_id=NULL,last_entry_hash=:genesis,hash_version=2,updated_at=NOW() WHERE id=1',
            ['genesis' => self::GENESIS]
        );
    }

    private function assertTargetReady(): void
    {
        $contentTables = [
            'articles', 'article_categories', 'article_events', 'article_translation_versions', 'article_translations',
            'article_versions', 'categories', 'category_translations', 'main_banners', 'main_banner_translations',
            'media', 'surveys', 'survey_questions', 'campaigns',
        ];
        foreach ($contentTables as $table) {
            if ((int)$this->target->cell('SELECT COUNT(*) FROM ' . $this->target->quoteIdentifier($table)) !== 0) {
                throw new RuntimeException("Docelowa tabela {$table} nie jest pusta; odmowa niejawnego scalania.");
            }
        }
        if ((int)$this->target->cell('SELECT COUNT(*) FROM wallet_transactions') !== 0) {
            throw new RuntimeException('Docelowa historia portfeli nie jest pusta.');
        }
        $users = $this->target->all('SELECT id,email FROM users ORDER BY id');
        $allowed = [
            [['id' => 1, 'email' => 'docker-admin@zrodlo-slowa.local'], ['id' => 2, 'email' => 'platform@zrodlo-slowa.local']],
            [],
        ];
        if ($users !== $allowed[0] && $users !== $allowed[1]) {
            throw new RuntimeException('Docelowi użytkownicy nie odpowiadają pustemu stanowi startowemu; odmowa scalania.');
        }
        foreach ($this->target->all('SELECT * FROM wallets ORDER BY id') as $wallet) {
            foreach (['main_available_minor','main_reserved_minor','slowo_available_minor','slowo_reserved_minor','points_balance'] as $field) {
                if ((int)$wallet[$field] !== 0) {
                    throw new RuntimeException('Portfel startowy zawiera saldo; migracja została zatrzymana.');
                }
            }
        }
        if ((int)$this->target->cell('SELECT COUNT(*) FROM financial_wallet_ledger_heads WHERE transaction_count<>0') !== 0) {
            throw new RuntimeException('Docelowa głowa portfela zawiera transakcje.');
        }
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function validateCompletedTarget(array $plan, bool $resumed): array
    {
        $expected = $plan['source']['content_counts'];
        $actual = $this->targetInventory();
        $errors = [];
        foreach ($expected as $table => $count) {
            if (!array_key_exists($table, $actual['counts']) || (int)$actual['counts'][$table] !== (int)$count) {
                $errors[] = "Niezgodna liczba rekordów {$table}.";
            }
        }
        if ((int)$actual['counts']['users'] !== 1 || (int)$actual['counts']['wallets'] !== 1 || (int)$actual['counts']['wallet_transactions'] !== 1) {
            $errors[] = 'Docelowy stan użytkowników lub aktywnej księgi nie jest selektywny.';
        }
        $wallet = $this->target->one('SELECT * FROM wallets WHERE user_id=:id', ['id' => $this->adminId()]);
        if (!$wallet || (int)$wallet['points_balance'] !== $this->openingPoints()) {
            $errors[] = 'Saldo punktowe administratora nie odpowiada zatwierdzonemu otwarciu.';
        }
        $targetTranslations = $this->targetTranslationInventory();
        if ($targetTranslations !== $plan['source']['translations']) {
            $errors[] = 'Inwentarz tłumaczeń nie jest zgodny ze źródłem.';
        }
        $integrity = (new LedgerIntegrityService($this->target))->verify(true);
        if (!$integrity['ok']) {
            $errors = [...$errors, ...$integrity['errors']];
        }
        $walletVerification = (new SelectedWalletVerificationService(
            $this->target,
            $this->adminId(),
            $this->openingPoints(),
            $this->manifestHash()
        ))->verify();
        if (!$walletVerification['ok']) {
            $errors = [...$errors, ...$walletVerification['errors']];
        }
        if ($errors !== []) {
            throw new RuntimeException('Walidacja selektywnej migracji nie powiodła się: ' . $this->json($errors));
        }
        return [
            'ok' => true,
            'resumed_existing' => $resumed,
            'manifest_hash' => $this->manifestHash(),
            'target' => $actual,
            'translations' => $targetTranslations,
            'public_routes' => $this->targetPublicRoutes(),
            'route_diagnostics' => $this->routeDiagnostics($this->targetPublicRoutes()),
            'ledger_integrity' => $integrity,
            'wallet_verification' => $walletVerification,
        ];
    }

    private function isCompletedTarget(): bool
    {
        return (int)$this->target->cell(
            'SELECT COUNT(*) FROM selected_migration_runs WHERE status=\'completed\' AND source_manifest_hash=:hash',
            ['hash' => $this->manifestHash()]
        ) > 0;
    }

    /** @param list<array<string,mixed>> $rows */
    private function copyRows(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $targetColumns = $this->targetColumns($table);
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $column => $value) {
                if (!in_array($column, $targetColumns, true)) {
                    continue;
                }
                if (is_string($value) && preg_match('/^0{4}-0{2}-0{2}/', $value) === 1) {
                    $value = null;
                }
                $values[$column] = $value;
            }
            if ($values === []) {
                throw new RuntimeException("Brak wspólnych kolumn dla tabeli {$table}.");
            }
            $columns = array_keys($values);
            $parameters = [];
            $placeholders = [];
            foreach (array_values($values) as $index => $value) {
                $key = 'p' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $value;
            }
            $this->target->query(
                'INSERT INTO ' . $this->target->quoteIdentifier($table)
                . ' (' . implode(',', array_map($this->target->quoteIdentifier(...), $columns)) . ')'
                . ' VALUES (' . implode(',', $placeholders) . ')',
                $parameters
            );
        }
        return count($rows);
    }

    /** @return list<string> */
    private function targetColumns(string $table): array
    {
        return array_map(
            static fn(array $row): string => (string)$row['column_name'],
            $this->target->all(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema=:schema AND table_name=:table ORDER BY ordinal_position',
                ['schema' => $this->target->schema(), 'table' => $table]
            )
        );
    }

    private function advanceSequences(): void
    {
        foreach (['users','user_profiles','categories','category_translations','articles','article_versions','article_translations',
                     'article_translation_versions','article_events','main_banners','main_banner_translations','surveys','survey_questions',
                     'campaigns','media','wallets','wallet_transactions','admin_audit_logs','financial_audit_log','financial_ledger_anchors'] as $table) {
            $sequence = $this->target->cell('SELECT pg_get_serial_sequence(:table,\'id\')', ['table' => $table]);
            if (is_string($sequence) && $sequence !== '') {
                $this->target->query(
                    'SELECT setval(CAST(:sequence AS regclass),GREATEST(COALESCE((SELECT MAX(id) FROM '
                    . $this->target->quoteIdentifier($table) . '),1),1),true)',
                    ['sequence' => $sequence]
                );
            }
        }
    }

    /** @return array<string,int> */
    private function sourceContentCounts(): array
    {
        $tables = ['articles','article_categories','article_events','article_translation_versions','article_translations','article_versions',
            'categories','category_translations','main_banners','main_banner_translations','media','surveys','survey_questions','campaigns'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int)$this->sourceCell('SELECT COUNT(*) FROM `' . $table . '`');
        }
        return $counts;
    }

    /** @return array<string,mixed> */
    private function targetInventory(): array
    {
        $tables = ['users','wallets','wallet_transactions','articles','article_categories','article_events','article_translation_versions',
            'article_translations','article_versions','categories','category_translations','main_banners','main_banner_translations',
            'media','surveys','survey_questions','campaigns'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int)$this->target->cell('SELECT COUNT(*) FROM ' . $this->target->quoteIdentifier($table));
        }
        return ['schema' => $this->target->schema(), 'counts' => $counts];
    }

    /** @return array<string,array<string,int>> */
    private function translationInventory(): array
    {
        return [
            'article_translations' => $this->sourceLanguageCounts('article_translations', 'language'),
            'article_translation_versions' => $this->sourceTranslationVersionLanguageCounts(),
            'category_translations' => $this->sourceLanguageCounts('category_translations', 'language'),
            'main_banner_translations' => $this->sourceLanguageCounts('main_banner_translations', 'language'),
        ];
    }

    /** @return array<string,array<string,int>> */
    private function targetTranslationInventory(): array
    {
        $result = [];
        foreach ([['article_translations','language'],['category_translations','language'],['main_banner_translations','language']] as [$table,$column]) {
            $result[$table] = [];
            foreach ($this->target->all("SELECT {$column} AS language,COUNT(*) AS total FROM {$table} GROUP BY {$column} ORDER BY {$column}") as $row) {
                $result[$table][(string)$row['language']] = (int)$row['total'];
            }
        }
        $result['article_translation_versions'] = [];
        foreach ($this->target->all(
            'SELECT t.language,COUNT(*) AS total FROM article_translation_versions v
             JOIN article_translations t ON t.id=v.translation_id GROUP BY t.language ORDER BY t.language'
        ) as $row) {
            $result['article_translation_versions'][(string)$row['language']] = (int)$row['total'];
        }
        $ordered = [
            'article_translations' => $result['article_translations'],
            'article_translation_versions' => $result['article_translation_versions'],
            'category_translations' => $result['category_translations'],
            'main_banner_translations' => $result['main_banner_translations'],
        ];
        return $ordered;
    }

    /** @return array<string,int> */
    private function sourceLanguageCounts(string $table, string $column): array
    {
        $rows = $this->sourceAll("SELECT {$column} AS language,COUNT(*) AS total FROM {$table} GROUP BY {$column} ORDER BY {$column}");
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['language']] = (int)$row['total'];
        }
        return $result;
    }

    /** @return array<string,int> */
    private function sourceTranslationVersionLanguageCounts(): array
    {
        $rows = $this->sourceAll(
            'SELECT t.language,COUNT(*) AS total FROM article_translation_versions v
             JOIN article_translations t ON t.id=v.translation_id GROUP BY t.language ORDER BY t.language'
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['language']] = (int)$row['total'];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function sourcePublicRoutes(): array
    {
        $routes = $this->sourceAll(
            "SELECT a.id AS article_id,a.status,'pl' AS language,a.slug
             FROM articles a
             UNION ALL
             SELECT a.id,a.status,t.language,COALESCE(NULLIF(t.slug,''),a.slug)
             FROM article_translations t JOIN articles a ON a.id=t.article_id
             ORDER BY article_id,language"
        );
        return $this->withPublicPaths($routes);
    }

    /** @return list<array<string,mixed>> */
    private function targetPublicRoutes(): array
    {
        $routes = $this->target->all(
            "SELECT a.id AS article_id,a.status,'pl' AS language,a.slug
             FROM articles a
             UNION ALL
             SELECT a.id,a.status,t.language,COALESCE(NULLIF(t.slug,''),a.slug)
             FROM article_translations t JOIN articles a ON a.id=t.article_id
             ORDER BY article_id,language"
        );
        return $this->withPublicPaths($routes);
    }

    /** @param list<array<string,mixed>> $routes @return list<array<string,mixed>> */
    private function withPublicPaths(array $routes): array
    {
        foreach ($routes as &$route) {
            $language = (string)$route['language'];
            $slug = (string)$route['slug'];
            $route['path'] = public_language_url(
                $language,
                '/' . seo_article_path($language) . '/' . rawurlencode($slug)
            );
        }
        unset($route);
        return $routes;
    }

    /** @param list<array<string,mixed>> $routes @return array<string,mixed> */
    private function routeDiagnostics(array $routes): array
    {
        $paths = [];
        $statuses = [];
        foreach ($routes as $route) {
            $path = (string)$route['path'];
            $paths[$path] = ($paths[$path] ?? 0) + 1;
            $status = (string)$route['status'];
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }
        ksort($statuses, SORT_STRING);
        return [
            'route_count' => count($routes),
            'unique_path_count' => count($paths),
            'duplicate_paths' => array_filter($paths, static fn(int $count): bool => $count > 1),
            'status_counts' => $statuses,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function sourceAll(string $sql, array $params = []): array
    {
        $statement = $this->source->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function sourceOne(string $sql, array $params = []): ?array
    {
        $statement = $this->source->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function sourceCell(string $sql, array $params = []): mixed
    {
        $statement = $this->source->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    private function adminId(): int
    {
        return (int)$this->manifest['source_admin_id'];
    }

    private function openingPoints(): int
    {
        return (int)$this->manifest['opening_points'];
    }

    private function manifestHash(): string
    {
        $manifest = $this->manifest;
        unset($manifest['_source_database']);
        return hash('sha256', $this->canonicalJson($manifest));
    }

    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
