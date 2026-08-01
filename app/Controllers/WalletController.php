<?php
namespace App\Controllers;

use App\Services\WalletService;
use App\Services\PayoutService;
use App\Services\FraudGuardService;
use App\Core\SlowoSnajperConfig;
use App\Services\PayoutMethodService;
use App\Services\UserService;
use App\Services\TalentService;
use App\Services\LedgerService;
use App\Services\WalletTransferService;

final class WalletController extends BaseController
{
    public function show(): string
    {
        $userId = $this->requireAuth();
        $userService = new UserService($this->app->db);
        $walletService = new WalletService($this->app->db);
        $methodService = new PayoutMethodService($this->app->db);
        $permissions = $userService->operationalPermissions($userId);
        $wallet = $walletService->optionalWalletForUser($userId);

        $ledger = new LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db));
        $talentService = new TalentService($this->app->db, $ledger);
        $transferQuote = (new WalletTransferService($this->app->db, $ledger))->quoteTalentToPln(100);
        $lang = function_exists('public_language') ? public_language() : 'pl';

        return $this->view('wallet/show', [
            'title' => t('wallet.title', $lang),
            'current_language' => $lang,
            'permissions' => $permissions,
            'wallet' => $wallet,
            'transactions' => $wallet ? $walletService->transactions($userId, $this->slowoSnajperConfig()->limit('wallet_transactions', 20, 100)) : [],
            'methods' => $wallet ? $methodService->forUser($userId) : [],
            'payouts' => $wallet ? (new PayoutService($this->app->db, new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath))))->forUser($userId, $this->slowoSnajperConfig()->limit('wallet_payouts', 10, 50)) : [],
            'bonus_notifications' => $talentService->recentNotifications($userId, $this->slowoSnajperConfig()->limit('bonus_notifications', 12, 50)),
            'transferQuote' => $transferQuote,
            'typeMap' => \App\Services\LedgerService::typeMap(),
            'payoutStatusMap' => [
                'pending' => ['label' => t('wallet.status.pending', $lang), 'class' => 'pending'],
                'approved' => ['label' => t('wallet.status.approved', $lang), 'class' => 'paid'],
                'paid' => ['label' => t('wallet.status.paid', $lang), 'class' => 'paid'],
                'rejected' => ['label' => t('wallet.status.rejected', $lang), 'class' => 'failed'],
                'cancelled' => ['label' => t('wallet.status.cancelled', $lang), 'class' => 'cancelled'],
            ],
            'flash_success'=>$this->app->session->pullFlash('success'),
            'flash_error'=>$this->app->session->pullFlash('error'),
        ]);
    }

    public function createPayoutMethod(): never
    {
        $userId = $this->requireAuth();
        try {
            (new PayoutMethodService($this->app->db))->create($userId, (string)($_POST['type'] ?? 'manual'), trim($_POST['label'] ?? ''), trim($_POST['account_ref'] ?? ''));
            $lang = public_language();
            $this->app->session->flash('success', t('wallet.payout_method_saved', $lang));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się zapisać metody wypłaty.', 'payout_method'));
        }
        $lang ??= public_language();
        redirect(public_language_url($lang, '/wallet'));
    }

    public function requestPayout(): never
    {
        $userId = $this->requireAuth();
        try {
            (new PayoutService($this->app->db, new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath))))->request($userId, (int)($_POST['amount_minor'] ?? 0), trim($_POST['note'] ?? ''), (int)($_POST['payout_method_id'] ?? 0) ?: null);
            $lang = public_language();
            $this->app->session->flash('success', t('wallet.payout_request_saved', $lang));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się zlecić wypłaty.', 'payout_request'));
        }
        $lang ??= public_language();
        redirect(public_language_url($lang, '/wallet'));
    }
}
