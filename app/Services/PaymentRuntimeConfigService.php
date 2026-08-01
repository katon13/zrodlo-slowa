<?php
namespace App\Services;

use App\Core\Database;

/**
 * PATCH 4 final hardening:
 * runtime configuration for payments is composed from:
 * - config/payments.php and .env for secrets/URLs/defaults,
 * - settings table for admin-controlled switches, methods and limits.
 *
 * Secrets stay outside DB. Admin panel controls only operational flags.
 */
final class PaymentRuntimeConfigService
{
    public function __construct(private readonly Database $db, private readonly array $baseConfig = []) {}

    public function config(): array
    {
        $config = $this->baseConfig;
        $settings = $this->settings([
            'payments.enabled',
            'stripe.enabled',
            'stripe.mode',
            'stripe.currency',
            'stripe.payment_methods',
            'stripe.success_url',
            'stripe.cancel_url',
            'stripe.webhook_url',
            'wallet.transfer.talent_to_pln.enabled',
            'wallet.transfer.talent_to_pln.fee_percent',
            'wallet.transfer.talent_to_pln.min_talent',
            'wallet.transfer.talent_to_pln.max_daily_talent',
            'wallet.transfer.talent_to_pln.auto_approve_below_pln_minor',
            'wallet.transfer.pln_to_talent.enabled',
            'wallet.tt_per_pln',
        ]);

        $paymentsEnabled = $this->bool($settings['payments.enabled'] ?? null, (bool)($config['enabled'] ?? false));
        $stripeEnabled = $this->bool($settings['stripe.enabled'] ?? null, (bool)($config['stripe']['enabled'] ?? false));
        $effectiveStripeEnabled = $paymentsEnabled && $stripeEnabled;

        $config['enabled'] = $paymentsEnabled;
        $config['providers']['stripe'] = $effectiveStripeEnabled;

        $config['stripe']['enabled'] = $effectiveStripeEnabled;
        $config['stripe']['mode'] = $this->enum($settings['stripe.mode'] ?? null, ['test', 'live'], (string)($config['stripe']['mode'] ?? 'test'));
        $config['stripe']['currency'] = strtolower($this->text($settings['stripe.currency'] ?? null, (string)($config['stripe']['currency'] ?? 'pln')));
        $config['stripe']['payment_methods'] = $this->methods($settings['stripe.payment_methods'] ?? null, (string)($config['stripe']['payment_methods'] ?? 'card,p24'));
        $config['stripe']['success_url'] = $this->text($settings['stripe.success_url'] ?? null, (string)($config['stripe']['success_url'] ?? ''));
        $config['stripe']['cancel_url'] = $this->text($settings['stripe.cancel_url'] ?? null, (string)($config['stripe']['cancel_url'] ?? ''));
        $config['stripe']['webhook_url'] = $this->text($settings['stripe.webhook_url'] ?? null, (string)($config['stripe']['webhook_url'] ?? ''));

        $config['wallet_transfer']['talent_to_pln']['enabled'] = $this->bool($settings['wallet.transfer.talent_to_pln.enabled'] ?? null, (bool)($config['wallet_transfer']['talent_to_pln']['enabled'] ?? true));
        $config['wallet_transfer']['talent_to_pln']['fee_percent'] = $this->int($settings['wallet.transfer.talent_to_pln.fee_percent'] ?? null, (int)($config['wallet_transfer']['talent_to_pln']['fee_percent'] ?? 5), 0, 30);
        $config['wallet_transfer']['talent_to_pln']['min_talent'] = $this->int($settings['wallet.transfer.talent_to_pln.min_talent'] ?? null, (int)($config['wallet_transfer']['talent_to_pln']['min_talent'] ?? 100), 1, 1000000);
        $config['wallet_transfer']['talent_to_pln']['max_daily_talent'] = $this->int($settings['wallet.transfer.talent_to_pln.max_daily_talent'] ?? null, (int)($config['wallet_transfer']['talent_to_pln']['max_daily_talent'] ?? 5000), 1, 10000000);
        $config['wallet_transfer']['talent_to_pln']['auto_approve_below_pln_minor'] = $this->int($settings['wallet.transfer.talent_to_pln.auto_approve_below_pln_minor'] ?? null, (int)($config['wallet_transfer']['talent_to_pln']['auto_approve_below_pln_minor'] ?? 5000), 0, 100000000);

        $config['wallet']['tt_per_pln'] = $this->int($settings['wallet.tt_per_pln'] ?? null, (int)($config['wallet']['tt_per_pln'] ?? 10), 1, 1000000);

        $config['wallet_transfer']['pln_to_talent']['enabled'] = $this->bool($settings['wallet.transfer.pln_to_talent.enabled'] ?? null, (bool)($config['wallet_transfer']['pln_to_talent']['enabled'] ?? true));
        $config['wallet_transfer']['pln_to_talent']['rate'] = $config['wallet']['tt_per_pln'];

        return $config;
    }

    public function getTtPerPln(): int
    {
        return $this->config()['wallet']['tt_per_pln'] ?? 10;
    }

    public function formatTtRateLabel(string $currency = '', ?string $localValue = null): string
    {
        $rate = $this->getTtPerPln();
        if ($localValue !== null) {
            return "{$rate} TT = {$localValue}";
        }
        $val = number_format(1.0, 1, ',', ' ');
        $suffix = $currency ? " {$currency}" : " PLN";
        return "{$rate} TT = {$val}{$suffix}";
    }

    public function formatValue(int|float $tt, string $currency = 'PLN', float $rateToPln = 1.0, string $language = 'pl'): string
    {
        $rate = $this->getTtPerPln();
        if ($rate <= 0) return "0,0 {$currency}";
        $pln = $tt / $rate;
        $value = $pln / $rateToPln;

        // Ujednolicenie: zaokrąglanie w dół, 1 miejsce po przecinku
        $rounded = floor(round($value, 6) * 10) / 10;
        if ($value > 0 && $rounded < 0.1) {
            $rounded = 0.1;
        }

        $decimalSeparator = in_array(strtolower($language), ['pl', 'de', 'fr', 'it', 'es']) ? ',' : '.';
        return number_format($rounded, 1, $decimalSeparator, ' ') . ' ' . $currency;
    }

    public function formatTtToPlnValue(int|float $tt): string
    {
        return $this->formatValue($tt, 'PLN', 1.0, 'pl');
    }

    private function settings(array $names): array
    {
        if ($names === []) {
            return [];
        }

        try {
            $placeholders = [];
            $params = [];
            foreach ($names as $i => $name) {
                $key = 'n' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $name;
            }
            $rows = $this->db->all('SELECT name,value FROM settings WHERE name IN (' . implode(',', $placeholders) . ')', $params);
            $out = [];
            foreach ($rows as $row) {
                $out[(string)$row['name']] = (string)$row['value'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function bool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    private function text(mixed $value, string $default): string
    {
        $value = trim((string)($value ?? ''));
        return $value !== '' ? $value : $default;
    }

    private function enum(mixed $value, array $allowed, string $default): string
    {
        $value = strtolower($this->text($value, $default));
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function int(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }

    private function methods(mixed $value, string $default): string
    {
        $raw = $this->text($value, $default);
        $allowed = ['card', 'p24'];
        $methods = [];
        foreach (explode(',', $raw) as $part) {
            $method = strtolower(trim($part));
            if (in_array($method, $allowed, true) && !in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }
        return implode(',', $methods ?: ['card', 'p24']);
    }
}
