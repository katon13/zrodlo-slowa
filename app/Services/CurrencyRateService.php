<?php

namespace App\Services;

class CurrencyRateService
{
    private string $cachePath;
    private ?array $memoryCache = null;
    private const NBP_API_URL = 'https://api.nbp.pl/api/exchangerates/tables/a/?format=json';

    public function __construct(private readonly ?CacheService $sharedCache = null)
    {
        $this->cachePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'currency_rates_nbp.json';
    }

    /**
     * Pobiera kursy z NBP API i aktualizuje cache.
     */
    public function updateFromNbp(): bool
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'ZrodloSlowa/1.0',
                    'ignore_errors' => true
                ]
            ]);
            
            $response = @file_get_contents(self::NBP_API_URL, false, $context);
            
            if ($response === false || empty($response)) {
                return false;
            }

            $data = json_decode($response, true);
            // NBP zwraca tablicę tablic (tabela A)
            if (!is_array($data) || !isset($data[0]['rates'])) {
                return false;
            }

            $table = $data[0];
            $rates = [];
            foreach ($table['rates'] as $rate) {
                if (isset($rate['code'], $rate['mid'])) {
                    $rates[strtoupper((string)$rate['code'])] = [
                        'mid' => (float)$rate['mid']
                    ];
                }
            }

            // Dodajemy PLN jako bazę 1:1
            $rates['PLN'] = ['mid' => 1.0];

            $cacheData = [
                'source' => 'nbp',
                'base_currency' => 'PLN',
                'effective_date' => $table['effectiveDate'] ?? date('Y-m-d'),
                'table_no' => $table['no'] ?? 'unknown',
                'updated_at' => date('Y-m-d H:i:s'),
                'rates' => $rates
            ];

            $this->memoryCache = $cacheData;
            if ($this->sharedCache !== null) {
                $this->sharedCache->set('currency_rates:nbp', $cacheData, 172800);
                return true;
            }

            $dir = dirname($this->cachePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return file_put_contents($this->cachePath, json_encode($cacheData, JSON_PRETTY_PRINT)) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Wczytuje kursy z cache.
     */
    public function loadCachedRates(): array
    {
        if ($this->memoryCache !== null) {
            return $this->memoryCache;
        }

        if ($this->sharedCache !== null) {
            $lookup = $this->sharedCache->get('currency_rates:nbp');
            $value = $lookup['hit'] && is_array($lookup['value']) ? $lookup['value'] : [];
            if (is_array($value['rates'] ?? null)) {
                $this->memoryCache = $value;
                return $value;
            }
            return [];
        }

        if (!file_exists($this->cachePath) && PHP_SAPI === 'cli') {
            $this->updateFromNbp();
        }

        if (!file_exists($this->cachePath)) {
            return [];
        }

        $content = file_get_contents($this->cachePath);
        if (!$content) {
            return [];
        }

        $this->memoryCache = json_decode($content, true) ?: [];
        return $this->memoryCache;
    }

    /**
     * Mapowanie języka na walutę.
     */
    public function currencyForLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        return match ($language) {
            'pl' => 'PLN',
            'en' => 'GBP',
            'de', 'fr', 'it', 'es' => 'EUR',
            default => 'PLN',
        };
    }

    /**
     * Ustala walutę do wyświetlania na podstawie języka i preferencji użytkownika.
     */
    public function effectiveCurrency(string $language, string $userDisplayCurrency = 'AUTO'): string
    {
        if ($userDisplayCurrency === 'AUTO') {
            return $this->currencyForLanguage($language);
        }
        
        $userDisplayCurrency = strtoupper($userDisplayCurrency);
        return in_array($userDisplayCurrency, ['PLN', 'EUR', 'USD', 'GBP']) ? $userDisplayCurrency : 'PLN';
    }

    /**
     * Przelicza PLN na walutę obcą.
     */
    public function convertPlnToCurrency(float $amountPln, string $currency): ?float
    {
        if ($currency === 'PLN') {
            return $amountPln;
        }

        $cache = $this->loadCachedRates();
        if (empty($cache['rates'][$currency]['mid'])) {
            return null;
        }

        $mid = (float)$cache['rates'][$currency]['mid'];
        if ($mid <= 0) {
            return null;
        }

        return $amountPln / $mid;
    }

    /**
     * Formatuje kwotę w sposób uproszczony (bez prefiksów, min 0.1).
     */
    public function formatSimple(float $amount, string $currency, string $language): string
    {
        // Zaokrąglanie w dół do 1 miejsca po przecinku
        $rounded = floor(round($amount, 6) * 10) / 10;
        
        // Jeśli wynik > 0 i spada do 0,0, pokazać 0,1 jako minimalną widoczną wartość
        if ($amount > 0 && $rounded < 0.1) {
            $rounded = 0.1;
        }

        // Formatowanie liczby
        $decimalSeparator = in_array(strtolower($language), ['pl', 'de', 'fr', 'it', 'es']) ? ',' : '.';
        $formattedAmount = number_format($rounded, 1, $decimalSeparator, ' ');
        
        return "{$formattedAmount} {$currency}";
    }

    /**
     * Formatuje kwotę jako wartość zaokrągloną w dół (uproszczone, bez prefiksów).
     */
    public function formatApproxDown(float $amount, string $currency, string $language): string
    {
        return $this->formatSimple($amount, $currency, $language);
    }

    /**
     * Konwertuje TT na sformatowany string lokalnej waluty (uproszczony).
     */
    public function ttToLocalApprox(float $tt, string $language, string $userDisplayCurrency = 'AUTO'): ?string
    {
        $currency = $this->effectiveCurrency($language, $userDisplayCurrency);
        $amountPln = $tt / 10.0;

        if ($currency === 'PLN') {
            return $this->formatSimple($amountPln, 'PLN', $language);
        }

        $amountForeign = $this->convertPlnToCurrency($amountPln, $currency);

        if ($amountForeign === null) {
            return null;
        }

        return $this->formatSimple($amountForeign, $currency, $language);
    }
}
