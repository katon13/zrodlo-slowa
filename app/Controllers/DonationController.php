<?php
namespace App\Controllers;

use App\Services\PaymentService;

final class DonationController extends BaseController
{
    public function campaign(): string
    {
        $slug = $_GET['slug'] ?? 'budowa-zrodla-slowa';
        $campaign = (new \App\Services\DonationService($this->app->db))->getCampaign($slug);
        
        return $this->view('donations/campaign', [
            'title' => $campaign ? $campaign['name'] : 'Wesprzyj nas',
            'campaign' => $campaign
        ]);
    }

    public function manualDonation(): never
    {
        $userId = $this->app->session->userId();
        (new PaymentService($this->app->db))->createManualDonation($userId, [
            'amount_minor' => (int)($_POST['amount_minor'] ?? 0),
            'campaign_id' => (int)($_POST['campaign_id'] ?? 0),
            'email' => trim($_POST['email'] ?? ''),
            'note' => trim($_POST['note'] ?? ''),
        ]);
        redirect('/donations');
    }
}
