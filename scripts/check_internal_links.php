<?php
declare(strict_types=1);

$baseUrl = rtrim((string)($argv[1] ?? 'http://localhost:8080'), '/');
if ($baseUrl !== 'http://localhost:8080') {
    fwrite(STDERR, "Kontrola jest ograniczona do http://localhost:8080.\n");
    exit(2);
}

$required = ['/', '/pl', '/pl/articles', '/pl/surveys', '/pl/campaigns', '/pl/jak-zarabiac', '/pl/login', '/pl/author', '/admin'];
foreach (['de', 'en', 'es', 'fr', 'it'] as $language) {
    $required[] = '/' . $language;
    $required[] = '/' . $language . '/articles';
}
$queue = $required;
$queued = array_fill_keys($required, true);
$visited = [];
$instances = [];
$errors = [];
$linksChecked = 0;
$redirects = 0;

while ($queue !== [] && count($visited) < 300) {
    $path = array_shift($queue);
    if (isset($visited[$path])) {
        continue;
    }
    $response = requestPage($baseUrl . $path);
    $visited[$path] = $response['status'];
    $server = strtolower((string)($response['headers']['server'] ?? ''));
    if (str_contains($server, 'microsoft-iis') || str_contains(strtolower($response['body']), 'inetpub')) {
        $errors[] = "{$path}: odpowiedź pochodzi z IIS.";
    }
    $isStaticAsset = str_starts_with((string)(parse_url($path, PHP_URL_PATH) ?: ''), '/assets/');
    if (!$isStaticAsset) {
        $instance = (string)($response['headers']['x-app-instance'] ?? '');
        if (in_array($instance, ['app-1', 'app-2'], true)) {
            $instances[$instance] = true;
        } else {
            $errors[] = "{$path}: brak poprawnego X-App-Instance.";
        }
    }
    if (!in_array($response['status'], [200, 301, 302, 303, 307, 308, 401, 403], true)) {
        $errors[] = "{$path}: niedozwolony status HTTP {$response['status']}.";
    }

    $location = (string)($response['headers']['location'] ?? '');
    if ($location !== '') {
        $redirects++;
        $normalized = internalPath($location, $path, $errors);
        if ($normalized !== null && !isset($queued[$normalized])) {
            $queue[] = $normalized;
            $queued[$normalized] = true;
        }
    }
    if ($response['status'] !== 200 || !str_contains(strtolower((string)($response['headers']['content-type'] ?? '')), 'text/html')) {
        continue;
    }
    preg_match_all('/\b(?:href|action)\s*=\s*(["\'])(.*?)\1/is', $response['body'], $matches);
    foreach ($matches[2] as $raw) {
        $linksChecked++;
        $normalized = internalPath(html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $path, $errors);
        if ($normalized !== null && !isset($queued[$normalized])) {
            $queue[] = $normalized;
            $queued[$normalized] = true;
        }
    }
}

for ($attempt = 0; $attempt < 12 && count($instances) < 2; $attempt++) {
    $response = requestPage($baseUrl . '/pl');
    $instance = (string)($response['headers']['x-app-instance'] ?? '');
    if (in_array($instance, ['app-1', 'app-2'], true)) {
        $instances[$instance] = true;
    }
}
foreach ($required as $path) {
    if (!isset($visited[$path])) {
        $errors[] = "Nie sprawdzono wymaganej strony {$path}.";
    }
}
foreach (['app-1', 'app-2'] as $instance) {
    if (!isset($instances[$instance])) {
        $errors[] = "Brak odpowiedzi z {$instance}.";
    }
}

$report = [
    'ok' => $errors === [],
    'base_url' => $baseUrl,
    'pages_checked' => count($visited),
    'links_and_actions_checked' => $linksChecked,
    'redirects_checked' => $redirects,
    'instances' => array_keys($instances),
    'required_pages' => array_intersect_key($visited, array_fill_keys($required, true)),
    'errors' => $errors,
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($report['ok'] ? 0 : 1);

/** @return array{status:int,headers:array<string,string>,body:string} */
function requestPage(string $url): array
{
    $headers = [];
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Nie udało się uruchomić klienta HTTP.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ZrodloSlowa-Internal-Link-Check/1.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $length = strlen($line);
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return $length;
        },
    ]);
    $body = curl_exec($handle);
    if (!is_string($body)) {
        $message = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Błąd HTTP: ' . $message);
    }
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

/** @param list<string> $errors */
function internalPath(string $raw, string $currentPath, array &$errors): ?string
{
    $raw = trim($raw);
    if ($raw === '' || str_starts_with($raw, '#') || preg_match('~^(?:mailto:|tel:|javascript:|data:)~i', $raw) === 1) {
        return null;
    }
    $parts = parse_url($raw);
    if ($parts === false) {
        $errors[] = "Nieprawidłowy URL: {$raw}.";
        return null;
    }
    if (isset($parts['host'])) {
        $host = strtolower((string)$parts['host']);
        if ($host !== 'localhost') {
            return null;
        }
        $port = (int)($parts['port'] ?? 0);
        if ($port !== 8080) {
            $errors[] = "Link utracił port 8080: {$raw}.";
            return null;
        }
    }
    $path = (string)($parts['path'] ?? '');
    if ($path === '') {
        $path = parse_url($currentPath, PHP_URL_PATH) ?: '/';
    } elseif (!str_starts_with($path, '/')) {
        $directory = rtrim(str_replace('\\', '/', dirname(parse_url($currentPath, PHP_URL_PATH) ?: '/')), '/');
        $path = ($directory === '' ? '' : $directory) . '/' . $path;
    }
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
        } else {
            $segments[] = $segment;
        }
    }
    $normalized = '/' . implode('/', $segments);
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $normalized . $query;
}
