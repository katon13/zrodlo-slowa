<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class UiLocalizationArchitectureTest extends TestCase
{
    /** @var list<string> */
    private const LANGUAGES = ['pl', 'en', 'de', 'fr', 'it', 'es'];

    public function testEveryFlatUiCatalogEntryHasAllSupportedLanguagesAndMatchingPlaceholders(): void
    {
        foreach (['public.json', 'admin.json', 'safety_fund.json'] as $file) {
            $catalog = $this->catalog($file);
            self::assertNotEmpty($catalog, $file);
            foreach ($catalog as $key => $entry) {
                self::assertIsArray($entry, $file . ' / ' . $key);
                $expectedPlaceholders = null;
                foreach (self::LANGUAGES as $language) {
                    self::assertArrayHasKey($language, $entry, $file . ' / ' . $key . ' / ' . $language);
                    $value = trim((string)$entry[$language]);
                    self::assertNotSame('', $value, $file . ' / ' . $key . ' / ' . $language);
                    preg_match_all('/\{[A-Za-z0-9_]+\}/', $value, $matches);
                    $placeholders = array_values(array_unique($matches[0]));
                    sort($placeholders);
                    $expectedPlaceholders ??= $placeholders;
                    self::assertSame($expectedPlaceholders, $placeholders, $file . ' / ' . $key . ' / ' . $language);
                }
            }
        }
    }

    public function testEveryLiteralTranslationKeyUsedByWwwExists(): void
    {
        $catalog = array_merge(
            $this->catalog('public.json'),
            $this->catalog('admin.json'),
            $this->catalog('safety_fund.json'),
        );
        $missing = [];
        foreach (array_merge($this->phpFiles('views'), $this->phpFiles('app/Controllers')) as $file) {
            $source = (string)file_get_contents($file);
            preg_match_all('/\bt\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
            foreach ($matches[1] as $key) {
                if (str_ends_with($key, '.') || isset($catalog[$key])) {
                    continue;
                }
                $missing[] = $this->relative($file) . ': ' . $key;
            }
        }
        self::assertSame([], $missing, "Missing UI translation keys:\n" . implode("\n", $missing));
    }

    public function testViewsContainNoRawHumanFacingHtmlOrPolishPhpLiterals(): void
    {
        $violations = [];
        foreach ($this->phpFiles('views') as $file) {
            $source = (string)file_get_contents($file);
            preg_match_all('/<script\b[^>]*>([\s\S]*?)<\/script\s*>/i', $source, $scriptMatches, PREG_OFFSET_CAPTURE);
            foreach ($scriptMatches[1] as [$script, $offset]) {
                $script = preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/', '', (string)$script) ?? (string)$script;
                $script = preg_replace(['/\/\*[\s\S]*?\*\//', '/(?m)\/\/[^\r\n]*$/'], '', $script) ?? $script;
                if (preg_match('/[ĄĆĘŁŃÓŚŹŻąćęłńóśźż]/u', $script) === 1) {
                    $violations[] = $this->location($file, $source, (int)$offset) . ': untranslated JavaScript copy';
                }
            }
            $htmlOnly = preg_replace('/<(?:script|style)\b[\s\S]*?<\/\s*(?:script|style)\s*>/i', '', $source) ?? $source;
            $withoutPhp = preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/', '', $htmlOnly) ?? $htmlOnly;
            $withoutComments = preg_replace([
                '/<!--[\s\S]*?-->/',
                '/\/\*[\s\S]*?\*\//',
                '/(?m)^\s*\/\/.*$/',
            ], '', $withoutPhp) ?? $withoutPhp;
            preg_match_all('/>([^<>]+)</', $withoutComments, $textMatches, PREG_OFFSET_CAPTURE);
            foreach ($textMatches[1] as [$text, $offset]) {
                $plain = trim(html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($this->isRawHumanText($plain)) {
                    $violations[] = $this->location($file, $source, (int)$offset) . ': ' . $plain;
                }
            }
            preg_match_all('/\b(?:placeholder|title|aria-label|alt)\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i', $withoutComments, $attributeMatches, PREG_OFFSET_CAPTURE);
            foreach ($attributeMatches[1] as [$text, $offset]) {
                if ($this->isRawHumanText(trim((string)$text))) {
                    $violations[] = $this->location($file, $source, (int)$offset) . ': ' . trim((string)$text);
                }
            }

            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $line = explode("\n", $source)[$token[2] - 1] ?? '';
                if (preg_match('/[ĄĆĘŁŃÓŚŹŻąćęłńóśźż]/u', $token[1]) === 1
                    && !str_contains($token[1], 'ŹRÓDŁO SŁOWA')
                    && !str_contains($line, 'settingsByGroup')) {
                    $violations[] = $this->relative($file) . ':' . $token[2] . ': ' . $token[1];
                }
            }
        }
        self::assertSame([], $violations, "Hardcoded view copy:\n" . implode("\n", $violations));
    }

    public function testWwwControllersDoNotReturnLiteralUiMessages(): void
    {
        $violations = [];
        foreach ($this->phpFiles('app/Controllers') as $file) {
            $name = basename($file);
            if (in_array($name, ['Dors3MobileApiController.php', 'MobileSessionController.php'], true)) {
                continue;
            }
            $source = (string)file_get_contents($file);
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                if (preg_match('/[ĄĆĘŁŃÓŚŹŻąćęłńóśźż]/u', $token[1]) !== 1) {
                    continue;
                }
                $line = explode("\n", $source)[$token[2] - 1] ?? '';
                if (str_contains($line, 'error_log(') || str_contains($token[1], 'ŹRÓDŁO SŁOWA')) {
                    continue;
                }
                $violations[] = $this->relative($file) . ':' . $token[2] . ': ' . $token[1];
            }
            foreach ([
                '/session->flash\(\s*[\'\"](?:success|error|info)[\'\"]\s*,\s*[\'\"]([A-Z])/',
                '/[\'\"](?:title|message|label)[\'\"]\s*=>\s*[\'\"]([A-Z])/',
                '/throw new [^(]+\(\s*[\'\"]([A-Z])/',
            ] as $pattern) {
                if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as [$match, $offset]) {
                        $violations[] = $this->location($file, $source, (int)$offset) . ': ' . $match;
                    }
                }
            }
        }
        self::assertSame([], $violations, "Hardcoded controller UI copy:\n" . implode("\n", $violations));
    }

    public function testJavascriptAndViewMetadataDoNotIntroduceLiteralUiCopy(): void
    {
        $violations = [];
        foreach ($this->phpFiles('views') as $file) {
            $source = (string)file_get_contents($file);
            $withoutComments = preg_replace([
                '/\/\*[\s\S]*?\*\//',
                '/(?m)\/\/[^\r\n]*$/',
            ], '', $source) ?? $source;

            if (preg_match_all('/\b(?:textContent|innerText)\s*=\s*([\'\"])([^\'\"\r\n]+)\1/', $withoutComments, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[2] as [$copy, $offset]) {
                    if (str_starts_with(trim((string)$copy), '<?=') || preg_match('/[A-Za-zÀ-ž]/u', (string)$copy) !== 1) {
                        continue;
                    }
                    $violations[] = $this->location($file, $source, (int)$offset) . ': JavaScript: ' . $copy;
                }
            }

            if (preg_match_all('/[\'\"](?:label|hint)[\'\"]\s*=>\s*([\'\"])([^\'\"\r\n]+)\1/', $withoutComments, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[2] as [$copy, $offset]) {
                    if (preg_match('/[A-Za-zÀ-ž]/u', (string)$copy) !== 1) {
                        continue;
                    }
                    $violations[] = $this->location($file, $source, (int)$offset) . ': metadata: ' . $copy;
                }
            }
        }

        self::assertSame([], $violations, "Hardcoded JavaScript or view metadata copy:\n" . implode("\n", $violations));
    }

    /** @return array<string,mixed> */
    private function catalog(string $file): array
    {
        $decoded = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/resources/lang/' . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $root = dirname(__DIR__, 2) . '/' . $directory;
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile() && $item->getExtension() === 'php') {
                $files[] = $item->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private function isRawHumanText(string $text): bool
    {
        if ($text === '' || in_array($text, ['AI', 'API', 'CSV', 'ID', 'PLN', 'TT', 'UTC', '3DORS', 'Ź'], true)) {
            return false;
        }
        if (preg_match('/^ID[\s#|]*$/', $text)) {
            return false;
        }
        $withoutUnits = preg_replace('/\b(?:TT|PLN|s|ms)\b/', '', $text) ?? $text;
        if (preg_match('/^[\d\s#|·+\/=.,:%()—-]*$/u', $withoutUnits)) {
            return false;
        }
        if (preg_match('/^[\d\s#|·+\/=.,:%()—-]*(?:TT|PLN|s|ms)?[\d\s#|·+\/=.,:%()—-]*$/u', $text)) {
            return false;
        }
        if (!preg_match('/[A-Za-zÀ-ž]/u', $text)) {
            return false;
        }
        return !(bool)preg_match('/^[A-Za-z0-9_.:\/@#?&=%+\-]+$/', $text);
    }

    private function location(string $file, string $source, int $offset): string
    {
        return $this->relative($file) . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
    }

    private function relative(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(dirname(__DIR__, 2)) + 1));
    }
}
