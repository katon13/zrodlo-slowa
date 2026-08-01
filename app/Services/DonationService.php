<?php
namespace App\Services;

use App\Core\Database;

final class DonationService
{
    public function __construct(private readonly Database $db) {}

    public function getCampaign(string $slug): ?array
    {
        $campaign = $this->db->one('SELECT * FROM donation_campaigns WHERE slug=:slug', ['slug' => $slug]);
        if (!$campaign) return null;

        // Przelicz aktualną sumę wpłat (status 'paid')
        $stats = $this->db->one('
            SELECT SUM(p.amount_minor) as total 
            FROM donations d 
            JOIN payments p ON p.id = d.payment_id 
            WHERE d.campaign_id = :id AND p.status = \'paid\'
        ', ['id' => $campaign['id']]);

        $campaign['current_amount_minor'] = (int)($stats['total'] ?? 0);
        
        // Oblicz progres
        $campaign['progress_percent'] = $campaign['target_amount_minor'] > 0 
            ? min(100, round(($campaign['current_amount_minor'] / $campaign['target_amount_minor']) * 100)) 
            : 0;

        return $campaign;
    }

    public function listActive(): array
    {
        return $this->db->all('SELECT * FROM donation_campaigns WHERE active=1 ORDER BY created_at DESC');
    }
}
