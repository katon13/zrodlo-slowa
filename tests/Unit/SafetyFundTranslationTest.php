<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SafetyFundTranslationTest extends TestCase
{
    /** @var list<string> */
    private const PUBLIC_LANGUAGES = ['pl', 'en', 'de', 'fr', 'it', 'es'];

    public function testEveryPublicSafetyFundTextHasAllActiveLanguageVersions(): void
    {
        $catalog = $this->catalog('resources/lang/safety_fund.json');
        $publicKeys = array_filter(
            array_keys($catalog),
            static fn(string $key): bool => str_starts_with($key, 'economy.')
                || str_starts_with($key, 'article.purchase.')
                || str_starts_with($key, 'bonus.'),
        );
        self::assertNotEmpty($publicKeys);

        foreach ($publicKeys as $key) {
            foreach (self::PUBLIC_LANGUAGES as $language) {
                self::assertArrayHasKey($language, $catalog[$key], $key . ' / ' . $language);
                self::assertNotSame('', trim((string)$catalog[$key][$language]), $key . ' / ' . $language);
            }
        }
    }

    public function testExistingPublicPurchaseAndHomeTextsDescribeThreeWaySplitInEveryLanguage(): void
    {
        $catalog = $this->catalog('resources/lang/public.json');
        foreach (['home.premium.description', 'article.paywall.split_notice', 'article.premium.safety_fund_share', 'wallet.earning.texts_percent'] as $key) {
            self::assertArrayHasKey($key, $catalog);
            foreach (self::PUBLIC_LANGUAGES as $language) {
                self::assertNotSame('', trim((string)($catalog[$key][$language] ?? '')), $key . ' / ' . $language);
            }
        }
        foreach (self::PUBLIC_LANGUAGES as $language) {
            self::assertStringContainsString('Safety Fund', (string)$catalog['home.premium.description'][$language]);
        }
    }

    public function testAdminAndDorsTextsNeverExposeMachineActionAsTheLabel(): void
    {
        $catalog = $this->catalog('resources/lang/safety_fund.json');
        foreach (['pl', 'en'] as $language) {
            self::assertNotSame('financial_settings.change', $catalog['safety_fund.operation.financial_settings.change'][$language] ?? null);
            self::assertNotSame('safety_fund.disbursement', $catalog['safety_fund.operation.safety_fund.disbursement'][$language] ?? null);
        }

        $dors = $this->catalog('resources/lang/dors3.json');
        foreach (['pl', 'en'] as $language) {
            self::assertNotSame('financial_settings.change', $dors[$language]['operations']['financial_settings.change'] ?? null);
            self::assertNotSame('safety_fund.disbursement', $dors[$language]['operations']['safety_fund.disbursement'] ?? null);
        }
    }

    /** @return array<string,mixed> */
    private function catalog(string $path): array
    {
        $fullPath = dirname(__DIR__, 2) . '/' . $path;
        $decoded = json_decode((string)file_get_contents($fullPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
