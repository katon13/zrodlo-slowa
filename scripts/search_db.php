<?php
require_once 'app/Core/bootstrap.php';
$app = App\Core\App::boot('.');
$res = $app->db->all("SELECT * FROM settings WHERE value LIKE '%OGÓLNE%' OR value LIKE '%ALDO%' OR name LIKE '%OGÓLNE%' OR name LIKE '%ALDO%'");
print_r($res);

$res2 = $app->db->all("SELECT * FROM ledger_types WHERE label LIKE '%OGÓLNE%' OR label LIKE '%ALDO%'");
print_r($res2);
