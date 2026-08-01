<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

$config = require __DIR__ . '/../config/database.php';
$db = new Database($config['default']);

function line(string $s = ''): void { echo $s . PHP_EOL; }
function countTable(Database $db, string $table): int {
    try { return (int)$db->cell('SELECT COUNT(*) FROM ' . $table); }
    catch (Throwable $e) { return -1; }
}

line('ŹRÓDŁO SŁOWA — RAPORT TREŚCI');
line(str_repeat('=', 52));
line('articles:           ' . countTable($db, 'articles'));
line('categories:         ' . countTable($db, 'categories'));
line('article_categories: ' . countTable($db, 'article_categories'));
line('article_versions:   ' . countTable($db, 'article_versions'));
line('article_events:     ' . countTable($db, 'article_events'));
line('');

$statuses = $db->all('SELECT status, COUNT(*) AS cnt FROM articles GROUP BY status ORDER BY status');
line('STATUSY ARTYKUŁÓW:');
if (!$statuses) {
    line('- brak artykułów');
} else {
    foreach ($statuses as $r) {
        line('- ' . str_pad((string)$r['status'], 12) . ': ' . (int)$r['cnt']);
    }
}

line('');
line('OSTATNIE ARTYKUŁY:');
$articles = $db->all('SELECT id, legacy_id, title, status, published_at FROM articles ORDER BY id DESC LIMIT 10');
if (!$articles) {
    line('- brak');
} else {
    foreach ($articles as $a) {
        line(sprintf('#%d legacy:%s [%s] %s', (int)$a['id'], (string)($a['legacy_id'] ?? '-'), (string)$a['status'], (string)$a['title']));
    }
}
