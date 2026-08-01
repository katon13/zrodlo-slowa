<?php
require_once 'app/Core/bootstrap.php';
$app = App\Core\App::boot('.');

echo "TEST: Panel Edycji Tekstów\n";

// 1. Sprawdzenie tras
$routes = [
    'GET /admin/editorial',
    'GET /admin/editorial/edit',
    'POST /admin/editorial/update',
    'POST /admin/editorial/save-order',
    'POST /admin/editorial/toggle-featured'
];
echo "[ ] Sprawdzanie tras...\n";
// W tym środowisku trudno sprawdzić zarejestrowane trasy w Routerze bez mockowania, 
// ale sprawdzimy czy plik index.php zawiera te wpisy.
$indexContent = file_get_contents('public/index.php');
foreach ($routes as $route) {
    list($method, $path) = explode(' ', $route);
    if (strpos($indexContent, "'$path'") !== false) {
        echo "  [OK] Trasa $route obecna.\n";
    } else {
        echo "  [FAIL] Brak trasy $route!\n";
    }
}

// 2. Sprawdzenie kontrolera
echo "[ ] Sprawdzanie AdminController...\n";
$controllerFile = 'app/Controllers/AdminController.php';
$methods = ['editorial', 'editEditorialArticle', 'updateEditorialArticle', 'saveEditorialOrder', 'toggleFeatured'];
$controllerContent = file_get_contents($controllerFile);
foreach ($methods as $method) {
    if (strpos($controllerContent, "function $method") !== false) {
        echo "  [OK] Metoda $method obecna.\n";
    } else {
        echo "  [FAIL] Brak metody $method!\n";
    }
}

// 3. Sprawdzenie ArticleService
echo "[ ] Sprawdzanie ArticleService...\n";
$serviceFile = 'app/Services/ArticleService.php';
$serviceMethods = ['allForAdmin', 'findForAdmin', 'updateEditorial'];
$serviceContent = file_get_contents($serviceFile);
foreach ($serviceMethods as $method) {
    if (strpos($serviceContent, "function $method") !== false) {
        echo "  [OK] Metoda $method obecna.\n";
    } else {
        echo "  [FAIL] Brak metody $method!\n";
    }
}

// 4. Sprawdzenie widoków
echo "[ ] Sprawdzanie widoków...\n";
$views = ['views/admin/editorial_list.php', 'views/admin/editorial_edit.php'];
foreach ($views as $view) {
    if (file_exists($view)) {
        echo "  [OK] Widok $view istnieje.\n";
        $content = file_get_contents($view);
        if (strpos($content, "editorial.editing.title") !== false) {
            echo "    [OK] Tłumaczenia użyte w $view.\n";
        }
    } else {
        echo "  [FAIL] Brak widoku $view!\n";
    }
}

// 5. Sprawdzenie bazy danych (pola)
echo "[ ] Sprawdzanie pól w bazie danych...\n";
$cols = $app->db->all('DESCRIBE articles');
$foundCols = array_column($cols, 'Field');
$requiredCols = ['display_order', 'editorial_weight', 'is_featured', 'source_language'];
foreach ($requiredCols as $col) {
    if (in_array($col, $foundCols)) {
        echo "  [OK] Pole $col istnieje w articles.\n";
    } else {
        echo "  [FAIL] Brak pola $col w articles!\n";
    }
}

// 6. Sprawdzenie tłumaczeń w JSON
echo "[ ] Sprawdzanie kluczy w public.json...\n";
$json = json_decode(file_get_contents('resources/lang/public.json'), true);
if (isset($json['editorial.editing.title']['pl'])) {
    echo "  [OK] Klucze editorial.editing obecne w JSON.\n";
} else {
    echo "  [FAIL] Brak kluczy w JSON!\n";
}

echo "\nTEST ZAKOŃCZONY.\n";
