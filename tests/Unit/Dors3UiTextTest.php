<?php
declare(strict_types=1);

use App\Security\Dors3\MobileOperationPolicy;
use App\Services\Dors3UiText;
use PHPUnit\Framework\TestCase;

final class Dors3UiTextTest extends TestCase
{
    public function testAllSupportedLanguageCatalogsHaveTheSameStructure(): void
    {
        $polish = Dors3UiText::languageCatalog('pl');
        foreach (['en', 'de', 'fr', 'it', 'es'] as $language) {
            self::assertSame($this->leafKeys($polish), $this->leafKeys(Dors3UiText::languageCatalog($language)), $language);
        }
    }

    public function testEveryRoutedOperationHasAVisibleLabelInBothLanguages(): void
    {
        $reflection = new ReflectionClass(MobileOperationPolicy::class);
        $operations = $reflection->getConstant('OPERATIONS');
        self::assertIsArray($operations);

        foreach ($operations as $variantOperations) {
            foreach ($variantOperations as $actionType) {
                foreach (['pl', 'en', 'de', 'fr', 'it', 'es'] as $language) {
                    self::assertNotSame($actionType, Dors3UiText::option('operations', $actionType, $language));
                }
            }
        }
    }

    public function testPlaceholdersAreResolvedWithoutChangingMachineIdentifiers(): void
    {
        self::assertSame(
            'Kod porównawczy: 123456',
            Dors3UiText::get('panel.comparison_code', ['code' => '123456'], 'pl'),
        );
        self::assertSame('Zmiana roli użytkownika', Dors3UiText::option('operations', 'role.change', 'pl'));
        self::assertSame('Change user role', Dors3UiText::option('operations', 'role.change', 'en'));
    }

    /** @param array<string,mixed> $value @return list<string> */
    private function leafKeys(array $value, string $prefix = ''): array
    {
        $keys = [];
        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (is_array($item)) {
                array_push($keys, ...$this->leafKeys($item, $path));
                continue;
            }
            $keys[] = $path;
        }
        sort($keys);
        return $keys;
    }
}
