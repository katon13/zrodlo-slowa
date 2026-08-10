<?php
namespace App\Controllers;

use App\Services\LedgerService;
use App\Services\WalletTransferService;

final class WalletTransferController extends BaseController
{
    public function talentToPln(): never
    {
        $userId = $this->requireAuth();
        $lang = public_language();
        try {
            $talentAmount = (int)($_POST['talent_amount'] ?? 0);
            $service = new WalletTransferService($this->app->db, new LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db)));
            $transferId = $service->convertTalentToPln($userId, $talentAmount);
            $this->app->session->flash('success', t('wallet.transfer.success_talent_to_pln', $lang) . ' (#' . $transferId . ')');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.wallettransfer.nie_udao_sie_wykonac_transferu'), 'wallet_transfer'));
        }
        redirect(public_language_url($lang, '/wallet'));
    }
}
