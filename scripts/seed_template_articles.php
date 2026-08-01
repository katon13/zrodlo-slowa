<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\ArticleService;

$options = getopt('', ['fresh']);
$config = require __DIR__ . '/../config/database.php';
$db = new Database($config['default']);
$articleService = new ArticleService($db);

if (isset($options['fresh'])) {
    echo "Czyszczenie tabel tresci..." . PHP_EOL;
    $db->query('DELETE FROM article_access_grants');
    $db->query('DELETE FROM article_reads');
    $db->query('DELETE FROM article_events');
    $db->query('DELETE FROM article_versions');
    $db->query('DELETE FROM article_categories');
    $db->query('DELETE FROM articles');
    $db->query('DELETE FROM categories');
}

// Pobierz admina
$admin = $db->one('SELECT id FROM users WHERE email=:email', ['email' => 'admin@zrodlo-slowa.local']);
if (!$admin) {
    die("Blad: Nie znaleziono uzytkownika admin@zrodlo-slowa.local. Uruchom najpierw install.php --fresh." . PHP_EOL);
}
$adminId = (int)$admin['id'];

// Kategorie
$categories = [
    'Najnowsze', 'Eseje', 'Kultura', 'Społeczeństwo', 'Wiara', 'Komentarze', 'Autorzy'
];
$catIds = [];
foreach ($categories as $catName) {
    $existing = $db->one('SELECT id FROM categories WHERE name=:name', ['name' => $catName]);
    if ($existing) {
        $catIds[$catName] = (int)$existing['id'];
    } else {
        $catIds[$catName] = $db->insert('INSERT INTO categories(name, slug, created_at) VALUES(:name, :slug, NOW())', [
            'name' => $catName,
            'slug' => strtolower(str_replace(' ', '-', $catName))
        ]);
    }
}

// Artykuly demonstracyjne
$articlesData = [
    [
        'title' => 'Czy technologia odbierze nam ciszę?',
        'lead' => 'W świecie nieustannych powiadomień i cyfrowego szumu, cisza staje się luksusem dostępnym dla nielicznych. Czy potrafimy jeszcze usłyszeć własne myśli?',
        'body' => 'W dzisiejszym swiecie cisza przestaje byc tylko brakiem dzwieku, a staje sie towarem deficytowym. Kazde powiadomienie z telefonu, kazda reklama w przestrzeni publicznej walczy o nasza uwage. Esej ten bada, jak technologia zmienia nasza zdolnosc do kontemplacji i czy w ogole mozliwy jest powrot do prawdziwej, wewnetrznej ciszy.',
        'categories' => ['Najnowsze', 'Eseje'],
        'access_mode' => 'free'
    ],
    [
        'title' => 'Między wspólnotą a samotnością',
        'lead' => 'Paradoks współczesności: jesteśmy połączeni jak nigdy wcześniej, a jednak poczucie izolacji rośnie. Gdzie szukać prawdziwych więzi?',
        'body' => 'Choc media spolecznosciowe obiecywaly nam globalna wioske, wielu z nas czuje sie bardziej samotnymi niz kiedykolwiek. Artykul analizuje spoleczne skutki cyfryzacji relacji i zastanawia sie, jak odbudowac realne wspolnoty oparte na bezposredniej obecnosci i wzajemnym zrozumieniu.',
        'categories' => ['Najnowsze', 'Społeczeństwo'],
        'access_mode' => 'free'
    ],
    [
        'title' => 'Po co nam klasyka?',
        'lead' => 'Czy dzieła sprzed wieków mają nam jeszcze coś do powiedzenia? O tym, dlaczego warto wracać do fundamentów naszej kultury.',
        'body' => 'W pogoni za nowoscia czesto zapominamy o fundamencie, na ktorym stoimy. Klasyka to nie tylko zakurzone ksiegi na polkach, ale zywa rozmowa z najwiekszymi umyslami historii. Tekst ten dowodzi, ze zrozumienie przeszlosci jest kluczem do swiadomego przezywania terazniejszosci.',
        'categories' => ['Najnowsze', 'Kultura'],
        'access_mode' => 'free'
    ],
    [
        'title' => 'Nadzieja, która nie zawodzi',
        'lead' => 'W trudnych czasach szukamy oparcia. Czym różni się chrześcijańska nadzieja od optymizmu i jak ją pielęgnować w codzienności?',
        'body' => 'Optymizm to czesto tylko wiara w to, ze "wszystko bedzie dobrze". Nadzieja siega znacznie glebiej – to pewnosc sensu nawet wtedy, gdy okolicznosci sa skrajnie trudne. Ks. Tomasz Biela prowadzi nas przez teologiczne i praktyczne wymiary nadziei, ktora nie pozwala sie poddac.',
        'categories' => ['Najnowsze', 'Wiara'],
        'access_mode' => 'paid',
        'price_minor' => 500
    ],
    [
        'title' => 'Polityka bez prawdy jest tylko zarządzaniem',
        'lead' => 'Kiedy pragmatyzm zastępuje wartości, życie publiczne traci swój fundament. O potrzebie powrotu do etyki w polityce.',
        'body' => 'Polityka w swojej najszlachetniejszej formie ma byc troska o dobro wspolne. Dzis czesto sprowadza sie do technokratycznego zarzadzania emocjami wyborcow. Autor wzywa do przywrocenia prawdy jako naczelnej zasady zycia publicznego, bez ktorej demokracja staje sie pusta skorupa.',
        'categories' => ['Najnowsze', 'Komentarze'],
        'access_mode' => 'free'
    ],
    [
        'title' => 'O języku, który tworzy rzeczywistość',
        'lead' => 'Słowa nie tylko opisują świat, ale go kształtują. Jak dbamy o higienę języka w dobie polaryzacji i fake newsów?',
        'body' => 'Jezyk nie jest neutralnym narzedziem. Kazde slowo, ktorego uzywamy, niesie ze soba ladunek emocjonalny i ideologiczny. Esej ten analizuje, jak wspolczesna polaryzacja psuje nasza zdolnosc do dialogu i jak mozemy odzyskac jezyk jako most laczacy ludzi, a nie mur ich dzielacy.',
        'categories' => ['Najnowsze', 'Eseje'],
        'access_mode' => 'paid',
        'price_minor' => 300
    ],
    [
        'title' => 'Książki, które zmieniają sposób patrzenia',
        'lead' => 'Literatura to nie tylko rozrywka, to trening empatii. Przedstawiamy zestawienie lektur, które pozwalają spojrzeć na świat inaczej.',
        'body' => 'Dobra literatura ma moc przemiany czlowieka. Poprzez historie innych ludzi uczymy sie rozumiec wlasne doswiadczenia. Aleksandra Majewska przygotowala wybor ksiazek, ktore stawiaja trudne pytania i nie daja latwych odpowiedzi, zmuszajac nas do rewizji wlasnych pogladow.',
        'categories' => ['Najnowsze', 'Kultura'],
        'access_mode' => 'free'
    ],
    [
        'title' => 'Praca, sens i godność człowieka',
        'lead' => 'Czy w dobie sztucznej inteligencji praca nadal będzie definiować naszą tożsamość? O przyszłości zatrudnienia i wartości ludzkiego wysiłku.',
        'body' => 'Nadchodzi era, w ktorej wiele tradycyjnych zawodow zniknie. Czy praca jest tylko sposobem na zarabianie pieniedzy, czy ma głebszy wymiar zwiazany z ludzka godnoscia? Tekst ten bada etyczne i spoleczne aspekty pracy w swiecie rzadzonym przez algorytmy.',
        'categories' => ['Najnowsze', 'Społeczeństwo'],
        'access_mode' => 'paid',
        'price_minor' => 400
    ],
    [
        'title' => 'Milczenie jako modlitwa',
        'lead' => 'Gdy brakuje słów, pozostaje obecność. O duchowej sile kontemplacji i odnajdywaniu Boga w głębi serca.',
        'body' => 'W tradycji monastycznej milczenie bylo zawsze uznawane za "jezyk, w ktorym mowi Bog". S. Marta od Milosierdzia pokazuje, ze nie trzeba wyjezdzac do pustelni, by odnalezc przestrzen spotkania z Absolutem. Wystarczy uciszyc wlasne "ja", by uslyszec glos, ktory jest cichym powiewem.',
        'categories' => ['Najnowsze', 'Wiara'],
        'access_mode' => 'free'
    ],
];

foreach ($articlesData as $art) {
    echo "Dodawanie artykulu: {$art['title']}" . PHP_EOL;
    $id = $articleService->createDraft($adminId, [
        'title' => $art['title'],
        'lead' => $art['lead'],
        'body' => $art['body'],
        'access_mode' => $art['access_mode'],
        'price_minor' => $art['price_minor'] ?? 0
    ]);
    
    foreach ($art['categories'] as $catName) {
        $db->query('INSERT INTO article_categories(article_id, category_id) VALUES(:a, :c)', [
            'a' => $id,
            'c' => $catIds[$catName]
        ]);
    }
    
    // Opublikuj od razu
    $articleService->setStatus($id, 'published', $adminId);
}

echo "Seeding zakonczony sukcesem." . PHP_EOL;
