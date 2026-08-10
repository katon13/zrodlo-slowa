<?php
namespace App\Controllers;

use App\Services\EconomyMapService;
use App\Services\PaymentService;
use App\Services\LedgerService;
use App\Services\WalletTransferService;

final class FinanceController extends BaseController
{
    public function payments(): string
    {
        $this->requireAdmin();

        $statusMap = $this->paymentStatusMap();
        $typeMap = [
            'article_payment' => 'Zakup tekstu',
            'wallet_topup' => t('bonus.type.wallet_topup'),
            'premium_access' => t('controller.finance.dostep_premium'),
            'donation' => 'Wsparcie',
            'payout' => t('bonus.type.payout'),
            'talent_purchase' => t('controller.finance.zakup_talentow'),
            'manual_topup' => t('controller.finance.reczne_doadowanie'),
        ];

        $providerMap = [
            'prelewy24_gateway' => 'Przelewy24',
            'przelewy24_gateway' => 'Przelewy24',
            'manual' => t('controller.finance.recznie'),
            'test' => 'Testowa',
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
        ];

        return $this->view('admin/payments', [
            'title' => t('admin.finance_report.patnosci'),
            'payments' => $this->recentPayments(),
            'payment_orders' => $this->paymentOrders(),
            'gateway_events' => $this->gatewayEvents(),
            'wallet_transfers' => $this->walletTransfers(),
            'payment_summary' => $this->paymentPatch4Summary(),
            'payment_settings' => $this->paymentPatch4Settings(),
            'statusMap' => $statusMap,
            'typeMap' => $typeMap,
            'providerMap' => $providerMap,
        ]);
    }


    public function updatePaymentSettings(): never
    {
        $adminId = $this->requireAdmin();

        $allowed = [
            'payments.enabled' => ['type' => 'bool'],
            'stripe.enabled' => ['type' => 'bool'],
            'stripe.mode' => ['type' => 'enum', 'values' => ['test', 'live']],
            'stripe.currency' => ['type' => 'text'],
            'stripe.payment_methods' => ['type' => 'csv_methods'],
            'wallet.transfer.talent_to_pln.enabled' => ['type' => 'bool'],
            'wallet.transfer.talent_to_pln.fee_percent' => ['type' => 'int', 'min' => 0, 'max' => 30],
            'wallet.transfer.talent_to_pln.min_talent' => ['type' => 'int', 'min' => 1, 'max' => 1000000],
            'wallet.transfer.talent_to_pln.max_daily_talent' => ['type' => 'int', 'min' => 1, 'max' => 10000000],
            'wallet.transfer.talent_to_pln.auto_approve_below_pln_minor' => ['type' => 'money_minor', 'min' => 0, 'max' => 100000000],
            'wallet.transfer.pln_to_talent.enabled' => ['type' => 'bool'],
            'wallet.tt_per_pln' => ['type' => 'int', 'min' => 1, 'max' => 1000000],
        ];

        try {
            $plannedUpdates = [];
            foreach ($allowed as $plannedName => $plannedRule) {
                $plannedPostName = str_replace('.', '_', $plannedName);
                if (!isset($_POST[$plannedName]) && !isset($_POST[$plannedPostName])) {
                    continue;
                }
                $plannedUpdates[$plannedName] = $this->normalizePaymentSettingValue(
                    $plannedName,
                    $plannedRule,
                    $_POST[$plannedName] ?? $_POST[$plannedPostName]
                );
            }
            if ($plannedUpdates === []) {
                throw new \InvalidArgumentException(t('controller.finance.brak_ustawien_patnosci_do_zapisania'));
            }
            $beforeRows = $this->app->db->all(
                'SELECT name,value FROM settings WHERE name IN ('
                . implode(',', array_fill(0, count($plannedUpdates), '?')) . ') ORDER BY name',
                array_keys($plannedUpdates)
            );
            $before = [];
            foreach ($beforeRows as $beforeRow) {
                $before[(string)$beforeRow['name']] = (string)$beforeRow['value'];
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'payment_settings.update',
                'settings_group',
                'payments',
                ['keys' => array_keys($plannedUpdates)],
                $before,
                $plannedUpdates,
            );
            $updated = 0;
            foreach ($allowed as $name => $rule) {
                $postName = str_replace('.', '_', $name);
                if (!isset($_POST[$name]) && !isset($_POST[$postName])) {
                    continue;
                }
                
                $rawValue = $_POST[$name] ?? $_POST[$postName];
                $value = $this->normalizePaymentSettingValue($name, $rule, $rawValue);
                
                $sql = $this->app->db->isPostgres()
                    ? 'INSERT INTO settings(name,value,updated_at) VALUES(:name,:value,NOW())
                       ON CONFLICT (name) DO UPDATE
                       SET value=EXCLUDED.value,updated_at=NOW()'
                    : 'INSERT INTO settings(name,value,updated_at) VALUES(:name,:value,NOW())
                       ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=NOW()';
                $this->app->db->query($sql, [
                    'name' => $name,
                    'value' => $value,
                ]);
                $updated++;
            }

            if ($updated > 0) {
                $this->app->session->flash('success', t('controller.finance.ustawienia_patnosci_zostay_zapisane'));
            } else {
                $this->app->session->flash('info', t('controller.finance.brak_zmian_do_zapisania'));
            }
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.finance.nie_udao_sie_zapisac_ustawien_patnosci'), 'payment_settings'));
        }

        redirect('/admin/payments');
    }

    public function approveWalletTransfer(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $transferId = (int)($_POST['transfer_id'] ?? 0);
            $transfer = $this->app->db->one('SELECT * FROM wallet_transfers WHERE id=:id', ['id' => $transferId]);
            if ($transfer === null) {
                throw new \RuntimeException(t('controller.finance.nie_znaleziono_transferu_portfela'));
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'wallet_transfer.approve',
                'wallet_transfer',
                (string)$transferId,
                [
                    'user_id' => (int)$transfer['user_id'],
                    'source_amount' => (int)$transfer['source_amount'],
                    'target_amount' => (int)$transfer['target_amount'],
                    'direction' => (string)$transfer['direction'],
                ],
                ['status' => (string)$transfer['status']],
                ['status' => 'completed'],
            );
            (new WalletTransferService($this->app->db, new LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db))))->approveTransfer($transferId, $adminId);
            $this->app->session->flash('success', str_replace('{id}', (string)$transferId, t('controller.finance.transfer_approved')));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.finance.nie_udao_sie_zatwierdzic_transferu'), 'wallet_transfer_approve'));
        }
        redirect('/admin/payments');
    }

    public function rejectWalletTransfer(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $transferId = (int)($_POST['transfer_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? t('controller.finance.odrzucone_przez_administracje')));
            $transfer = $this->app->db->one('SELECT * FROM wallet_transfers WHERE id=:id', ['id' => $transferId]);
            if ($transfer === null) {
                throw new \RuntimeException(t('controller.finance.nie_znaleziono_transferu_portfela'));
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'wallet_transfer.reject',
                'wallet_transfer',
                (string)$transferId,
                ['reason' => $reason, 'user_id' => (int)$transfer['user_id']],
                ['status' => (string)$transfer['status']],
                ['status' => 'rejected'],
            );
            (new WalletTransferService($this->app->db, new LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db))))->rejectTransfer($transferId, $adminId, $reason);
            $this->app->session->flash('success', str_replace('{id}', (string)$transferId, t('controller.finance.transfer_rejected')));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.finance.nie_udao_sie_odrzucic_transferu'), 'wallet_transfer_reject'));
        }
        redirect('/admin/payments');
    }

    public function ledger(): string
    {
        $this->requireAdmin();

        $rows = $this->app->db->all('SELECT wt.*, u.display_name, u.email FROM wallet_transactions wt LEFT JOIN users u ON u.id=wt.user_id ORDER BY wt.created_at DESC, wt.id DESC LIMIT 200');

        return $this->view('admin/ledger', [
            'title' => t('admin.ledger.ledger_portfeli'),
            'transactions' => $rows,
            'typeMap' => \App\Services\LedgerService::typeMap(),
        ]);
    }

    public function financialApprovals(): string
    {
        $this->requireAdminOrRoles(['publisher', 'wydawca']);

        $approvals = $this->app->db->all('SELECT fa.*, u.display_name, u.email, rb.display_name as requester_name FROM financial_approvals fa LEFT JOIN users u ON u.id=fa.user_id LEFT JOIN users rb ON rb.id=fa.requested_by WHERE fa.status=\'pending\' ORDER BY fa.created_at DESC');

        return $this->view('admin/financial_approvals', [
            'title' => t('admin.financial_approvals.zlecenia_finansowe_do_zatwierdzenia'),
            'approvals' => $approvals,
            'current_user_id' => $_SESSION['user_id']
        ]);
    }

    public function executeFinancialApproval(): never
    {
        $actorId = $this->requireAdminOrRoles(['publisher', 'wydawca']);
        $approvalId = (int)($_POST['approval_id'] ?? 0);
        $note = trim((string)($_POST['admin_note'] ?? ''));

        try {
            $approval = $this->app->db->one('SELECT * FROM financial_approvals WHERE id=:id', ['id' => $approvalId]);
            if ($approval === null) {
                throw new \RuntimeException(t('controller.finance.nie_znaleziono_zlecenia_finansowego'));
            }
            $this->authorizeCriticalOperation(
                $actorId,
                'financial_approval.execute',
                'financial_approval',
                (string)$approvalId,
                [
                    'operation_type' => (string)$approval['operation_type'],
                    'amount' => (int)$approval['amount'],
                    'currency' => (string)$approval['currency'],
                    'recipient_user_id' => (int)$approval['user_id'],
                    'admin_note' => $note,
                ],
                ['status' => (string)$approval['status']],
                ['status' => 'approved'],
            );
            $service = new \App\Services\FinancialService($this->app->db);
            $service->approve($approvalId, $note);
            $this->app->session->flash('success', t('controller.finance.zlecenie_zostao_zatwierdzone_i_wykonane'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.finance.nie_udao_sie_wykonac_zlecenia_finansowego'), 'financial_approval_execute'));
        }

        redirect('/admin/finance/approvals');
    }

    public function rejectFinancialApproval(): never
    {
        $actorId = $this->requireAdminOrRoles(['publisher', 'wydawca']);
        $approvalId = (int)($_POST['approval_id'] ?? 0);
        $reason = trim((string)($_POST['reject_reason'] ?? t('controller.finance.odrzucone_przez_administracje')));

        try {
            $approval = $this->app->db->one('SELECT * FROM financial_approvals WHERE id=:id', ['id' => $approvalId]);
            if ($approval === null) {
                throw new \RuntimeException(t('controller.finance.nie_znaleziono_zlecenia_finansowego'));
            }
            $this->authorizeCriticalOperation(
                $actorId,
                'financial_approval.reject',
                'financial_approval',
                (string)$approvalId,
                ['reason' => $reason, 'amount' => (int)$approval['amount'], 'currency' => (string)$approval['currency']],
                ['status' => (string)$approval['status']],
                ['status' => 'rejected'],
            );
            $service = new \App\Services\FinancialService($this->app->db);
            $service->reject($approvalId, $reason);
            $this->app->session->flash('success', t('controller.finance.zlecenie_zostao_odrzucone'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.finance.nie_udao_sie_odrzucic_zlecenia_finansowego'), 'financial_approval_reject'));
        }

        redirect('/admin/finance/approvals');
    }

    public function report(): string
    {
        $this->requireAdmin();
        $db = $this->app->db;
        
        $wallets = $db->one('SELECT COUNT(*) as cnt, SUM(available_minor) as sum_available, SUM(reserved_minor) as sum_reserved, SUM(points_balance) as sum_points FROM wallets');
        $ledger = $db->one('SELECT COUNT(*) as cnt FROM wallet_transactions');
        $payouts = $db->all('SELECT status, COUNT(*) as cnt, SUM(amount_minor) as sum_amount FROM payouts GROUP BY status');
        $payments = $db->all('SELECT status, COUNT(*) as cnt, SUM(amount_minor) as sum_amount FROM payments GROUP BY status');
        
        $campaigns = $db->all('SELECT * FROM donation_campaigns WHERE active=1');
        foreach ($campaigns as &$c) {
            $stats = $db->one('SELECT SUM(p.amount_minor) as total FROM donations d JOIN payments p ON p.id = d.payment_id WHERE d.campaign_id = :id AND p.status = \'paid\'', ['id' => $c['id']]);
            $c['current_amount_minor'] = (int)($stats['total'] ?? 0);
        }

        $premium = $db->one('SELECT COUNT(*) as total_sales, SUM(total_amount_minor) as total_revenue, SUM(author_income_minor) as total_author_income, SUM(publisher_fee_minor) as total_publisher_fee, SUM(safety_fund_amount_minor) as total_safety_fund, AVG(publisher_fee_percent) as avg_fee_percent FROM platform_revenues');
        $accessStats = $db->one('SELECT COUNT(CASE WHEN status=\'active\' AND expires_at IS NOT NULL AND expires_at > NOW() THEN 1 END) as active_grants, COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired_grants FROM article_access_grants');

        $payoutStatusMap = [
            'requested' => ['label' => t('wallet.status.pending'), 'class' => 'pending'],
            'pending' => ['label' => t('wallet.status.pending'), 'class' => 'pending'],
            'approved' => ['label' => t('wallet.status.approved'), 'class' => 'paid'],
            'paid' => ['label' => t('wallet.status.paid'), 'class' => 'paid'],
            'rejected' => ['label' => t('wallet.status.rejected'), 'class' => 'failed'],
            'cancelled' => ['label' => t('wallet.status.cancelled'), 'class' => 'cancelled'],
        ];

        return $this->view('admin/finance_report', [
            'title' => t('admin.finance_report.raport_finansowy'),
            'wallets' => $wallets,
            'ledger' => $ledger,
            'payouts' => $payouts,
            'payments' => $payments,
            'campaigns' => $campaigns,
            'premium' => $premium,
            'access' => $accessStats,
            'money_flows' => (new EconomyMapService($this->app->db))->publicFlows(),
            'economy_summary' => (new EconomyMapService($this->app->db))->adminSummary(),
            'statusMap' => $this->paymentStatusMap(),
            'payoutStatusMap' => $payoutStatusMap,
        ]);
    }

    private function paymentStatusMap(): array
    {
        return [
            'paid' => ['label' => t('controller.finance.opacona'), 'class' => 'paid'],
            'PAID' => ['label' => t('controller.finance.opacona'), 'class' => 'paid'],
            'credited' => ['label' => t('controller.finance.zaksiegowana'), 'class' => 'paid'],
            'redirected' => ['label' => t('controller.finance.przekierowana'), 'class' => 'pending'],
            'pending' => ['label' => t('wallet.status.pending'), 'class' => 'pending'],
            'PENDING' => ['label' => t('wallet.status.pending'), 'class' => 'pending'],
            'received' => ['label' => t('controller.finance.odebrana'), 'class' => 'pending'],
            'processed' => ['label' => t('controller.finance.przetworzona'), 'class' => 'paid'],
            'ignored' => ['label' => t('controller.finance.pominieta'), 'class' => 'cancelled'],
            'failed' => ['label' => t('controller.finance.bad'), 'class' => 'failed'],
            'FAILED' => ['label' => t('controller.finance.bad'), 'class' => 'failed'],
            'expired' => ['label' => t('safety_fund.status.expired'), 'class' => 'cancelled'],
            'cancelled' => ['label' => t('wallet.status.cancelled'), 'class' => 'cancelled'],
            'CANCELLED' => ['label' => t('wallet.status.cancelled'), 'class' => 'cancelled'],
            'refunded' => ['label' => t('controller.finance.zwrot'), 'class' => 'refunded'],
            'REFUNDED' => ['label' => t('controller.finance.zwrot'), 'class' => 'refunded'],
            'approved' => ['label' => t('wallet.status.approved'), 'class' => 'paid'],
            'completed' => ['label' => t('admin.partials.campaign_form.zakonczona'), 'class' => 'paid'],
            'held' => ['label' => t('controller.finance.wstrzymana'), 'class' => 'pending'],
            'rejected' => ['label' => t('wallet.status.rejected'), 'class' => 'failed'],
        ];
    }

    private function recentPayments(): array
    {
        try {
            return (new PaymentService($this->app->db))->listRecent();
        } catch (\Throwable) {
            return [];
        }
    }

    private function paymentOrders(): array
    {
        try {
            return $this->app->db->all('SELECT po.*, u.display_name, u.email, p.name AS package_name FROM payment_orders po LEFT JOIN users u ON u.id=po.user_id LEFT JOIN wallet_topup_packages p ON p.id=po.topup_package_id ORDER BY po.created_at DESC, po.id DESC LIMIT 80');
        } catch (\Throwable) {
            return [];
        }
    }

    private function gatewayEvents(): array
    {
        try {
            return $this->app->db->all('SELECT ge.*, po.public_id AS order_public_id, u.display_name, u.email FROM payment_gateway_events ge LEFT JOIN payment_orders po ON po.id=ge.payment_order_id LEFT JOIN users u ON u.id=po.user_id ORDER BY ge.received_at DESC, ge.id DESC LIMIT 80');
        } catch (\Throwable) {
            return [];
        }
    }

    private function walletTransfers(): array
    {
        try {
            return $this->app->db->all('SELECT wt.*, u.display_name, u.email FROM wallet_transfers wt LEFT JOIN users u ON u.id=wt.user_id ORDER BY wt.created_at DESC, wt.id DESC LIMIT 80');
        } catch (\Throwable) {
            return [];
        }
    }


    private function paymentPatch4Settings(): array
    {
        $defaults = [
            'payments.enabled' => '0',
            'stripe.enabled' => '0',
            'stripe.mode' => 'test',
            'stripe.currency' => 'pln',
            'stripe.payment_methods' => 'card,p24',
            'wallet.transfer.talent_to_pln.enabled' => '1',
            'wallet.transfer.talent_to_pln.fee_percent' => '5',
            'wallet.transfer.talent_to_pln.min_talent' => '100',
            'wallet.transfer.talent_to_pln.max_daily_talent' => '5000',
            'wallet.transfer.talent_to_pln.auto_approve_below_pln_minor' => '5000',
            'wallet.transfer.pln_to_talent.enabled' => '1',
            'wallet.tt_per_pln' => '10',
        ];
        try {
            $names = array_keys($defaults);
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $rows = $this->app->db->all(
                'SELECT name,value FROM settings WHERE name IN (' . $placeholders . ')',
                $names
            );
            foreach ($rows as $row) {
                $defaults[(string)$row['name']] = (string)$row['value'];
            }
        } catch (\Throwable) {}
        return $defaults;
    }

    private function normalizePaymentSettingValue(string $name, array $rule, mixed $raw): string
    {
        return match ($rule['type']) {
            'bool' => in_array((string)$raw, ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            'enum' => in_array((string)$raw, $rule['values'], true) ? (string)$raw : throw new \InvalidArgumentException(t('controller.finance.nieprawidowa_wartosc_ustawienia') . $name),
            'csv_methods' => $this->normalizePaymentMethods((string)$raw),
            'money_minor' => (string)$this->normalizeMoneyMinor($raw, (int)$rule['min'], (int)$rule['max'], $name),
            'text' => trim((string)$raw),
            default => (string)$this->normalizeInt($raw, (int)$rule['min'], (int)$rule['max'], $name),
        };
    }

    private function normalizeInt(mixed $raw, int $min, int $max, string $name): int
    {
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException(t('controller.admin.ustawienie') . $name . t('controller.admin.musi_byc_liczba'));
        }
        $value = (int)$raw;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(t('controller.admin.ustawienie') . $name . t('controller.finance.jest_poza_zakresem'));
        }
        return $value;
    }

    private function normalizeMoneyMinor(mixed $raw, int $min, int $max, string $name): int
    {
        $text = str_replace(',', '.', trim((string)$raw));
        if (!is_numeric($text)) {
            throw new \InvalidArgumentException(t('controller.admin.ustawienie') . $name . t('controller.finance.musi_byc_kwota'));
        }
        $value = (int)round(((float)$text) * 100);
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(t('controller.admin.ustawienie') . $name . t('controller.finance.jest_poza_zakresem'));
        }
        return $value;
    }

    private function normalizePaymentMethods(string $raw): string
    {
        $allowed = ['card', 'p24'];
        $parts = array_filter(array_map(static fn($v) => strtolower(trim($v)), explode(',', $raw)));
        $parts = array_values(array_unique(array_intersect($parts, $allowed)));
        if (empty($parts)) {
            $parts = ['card', 'p24'];
        }
        return implode(',', $parts);
    }

    private function paymentPatch4Summary(): array
    {
        $empty = [
            'orders_count' => 0,
            'credited_sum' => 0,
            'events_count' => 0,
            'failed_events_count' => 0,
            'transfers_count' => 0,
            'transfer_fees_sum' => 0,
        ];

        try {
            $orders = $this->app->db->one('SELECT COUNT(*) AS cnt, SUM(CASE WHEN status=\'credited\' THEN amount_minor ELSE 0 END) AS credited_sum FROM payment_orders') ?: [];
            $events = $this->app->db->one('SELECT COUNT(*) AS cnt, SUM(CASE WHEN processing_status=\'failed\' THEN 1 ELSE 0 END) AS failed_cnt FROM payment_gateway_events') ?: [];
            $transfers = $this->app->db->one('SELECT COUNT(*) AS cnt, SUM(fee_amount) AS fee_sum FROM wallet_transfers') ?: [];
            return [
                'orders_count' => (int)($orders['cnt'] ?? 0),
                'credited_sum' => (int)($orders['credited_sum'] ?? 0),
                'events_count' => (int)($events['cnt'] ?? 0),
                'failed_events_count' => (int)($events['failed_cnt'] ?? 0),
                'transfers_count' => (int)($transfers['cnt'] ?? 0),
                'transfer_fees_sum' => (int)($transfers['fee_sum'] ?? 0),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }
}
