<?php
namespace App\Services;

use App\Core\Database;

final class SqlScriptRunner
{
    public function runFile(Database $database, string $file): int
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException("Plik SQL jest niedostępny: $file");
        }
        $sql = file_get_contents($file);
        if (!is_string($sql)) {
            throw new \RuntimeException("Nie udało się odczytać pliku SQL: $file");
        }

        $executed = 0;
        foreach ($this->statements($sql) as $statement) {
            $database->query($statement);
            $executed++;
        }
        return $executed;
    }

    public function statements(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $sql));
        $delimiter = ';';
        $buffer = '';
        $statements = [];
        $quote = null;
        $dollarTag = null;
        $lineComment = false;
        $blockComment = false;
        $lineStart = true;
        $length = strlen((string)$sql);

        for ($index = 0; $index < $length;) {
            if (
                $lineStart
                && trim($buffer) === ''
                && preg_match(
                    '/\A[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(?:\n|\z)/i',
                    substr((string)$sql, $index),
                    $directive
                ) === 1
            ) {
                $delimiter = $directive[1];
                $index += strlen($directive[0]);
                $lineStart = true;
                continue;
            }

            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                $buffer .= $character;
                $index++;
                if ($character === "\n") {
                    $lineComment = false;
                    $lineStart = true;
                }
                continue;
            }

            if ($blockComment) {
                $buffer .= $character;
                $index++;
                if ($character === '*' && $next === '/') {
                    $buffer .= '/';
                    $index++;
                    $blockComment = false;
                }
                $lineStart = $character === "\n";
                continue;
            }

            if ($dollarTag !== null) {
                if (substr((string)$sql, $index, strlen($dollarTag)) === $dollarTag) {
                    $buffer .= $dollarTag;
                    $index += strlen($dollarTag);
                    $dollarTag = null;
                    $lineStart = false;
                    continue;
                }
                $buffer .= $character;
                $index++;
                $lineStart = $character === "\n";
                continue;
            }

            if ($quote !== null) {
                $buffer .= $character;
                $index++;
                if ($character === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                } elseif ($character === '\\' && $next !== '') {
                    $buffer .= $next;
                    $index++;
                }
                $lineStart = $character === "\n";
                continue;
            }

            if ($character === '-' && $next === '-') {
                $buffer .= '--';
                $index += 2;
                $lineComment = true;
                $lineStart = false;
                continue;
            }
            if ($character === '#') {
                $buffer .= $character;
                $index++;
                $lineComment = true;
                $lineStart = false;
                continue;
            }
            if ($character === '/' && $next === '*') {
                $buffer .= '/*';
                $index += 2;
                $blockComment = true;
                $lineStart = false;
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $buffer .= $character;
                $index++;
                $quote = $character;
                $lineStart = false;
                continue;
            }
            if (
                $character === '$'
                && preg_match('/\A(?:\$\$|\$[A-Za-z_][A-Za-z0-9_]*\$)/', substr((string)$sql, $index), $tag)
                === 1
            ) {
                $dollarTag = $tag[0];
                $buffer .= $dollarTag;
                $index += strlen($dollarTag);
                $lineStart = false;
                continue;
            }
            if (substr((string)$sql, $index, strlen($delimiter)) === $delimiter) {
                $statement = trim($buffer);
                if ($this->isExecutable($statement)) {
                    $statements[] = $statement;
                }
                $buffer = '';
                $index += strlen($delimiter);
                $lineStart = false;
                continue;
            }

            $buffer .= $character;
            $index++;
            $lineStart = $character === "\n";
        }

        if (
            $quote !== null
            || $dollarTag !== null
            || $blockComment
            || $this->isExecutable(trim($buffer))
        ) {
            throw new \RuntimeException('Plik SQL kończy się niezamkniętą instrukcją lub nieprawidłowym delimiterem.');
        }
        return $statements;
    }

    private function isExecutable(string $statement): bool
    {
        if ($statement === '') {
            return false;
        }
        $withoutLineComments = preg_replace('/^\s*(?:--|#).*$/m', '', $statement);
        $withoutComments = preg_replace('#/\*(?!\!)[\s\S]*?\*/#', '', (string)$withoutLineComments);
        return trim((string)$withoutComments) !== '';
    }
}
