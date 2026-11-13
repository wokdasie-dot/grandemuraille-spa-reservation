<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/app.php';

try {
    gm_json(gm_load_menus());
} catch (Throwable $e) {
    error_log($e->getMessage());
    gm_json(['error' => 'Impossible de charger les menus.'], 500);
}
