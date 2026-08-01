<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/bootstrap.php';

// Załaduj .env przed instalacją handlerów PHPUnit, a następnie przywróć
// handlery procesu testowego. Kolejne wywołania env() nie zmieniają już stanu.
env('APP_ENV', 'testing');
restore_error_handler();
restore_exception_handler();

date_default_timezone_set('Europe/Warsaw');
