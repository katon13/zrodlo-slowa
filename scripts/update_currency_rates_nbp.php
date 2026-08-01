<?php

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Services\CurrencyRateService;

echo "Updating currency rates from NBP API...\n";

$service = new CurrencyRateService();
if ($service->updateFromNbp()) {
    echo "SUCCESS: Currency rates updated successfully.\n";
    exit(0);
} else {
    echo "ERROR: Failed to update currency rates from NBP API.\n";
    exit(1);
}
