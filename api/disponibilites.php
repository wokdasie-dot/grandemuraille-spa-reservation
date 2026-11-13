<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/app.php';

$date = trim((string) ($_GET['date'] ?? ''));
$open = gm_validate_open_date($date);
if (empty($open['ok'])) {
    gm_json(['error' => $open['error']], 400);
}

$pdo = gm_pdo();
$capacity = gm_capacity();
$sum = $pdo->prepare(
    "SELECT time_slot, COALESCE(SUM(guests), 0) AS used
     FROM reservations
     WHERE date = ? AND status <> 'annulee'
     GROUP BY time_slot"
);
$sum->execute([$date]);
$used = [];
foreach ($sum as $row) {
    $used[$row['time_slot']] = (int) $row['used'];
}

$occ = $pdo->prepare(
    "SELECT time_slot, table_id
     FROM reservations
     WHERE date = ? AND status <> 'annulee' AND table_id <> ''"
);
$occ->execute([$date]);
$occupiedBySlot = [];
foreach ($occ as $row) {
    $occupiedBySlot[$row['time_slot']][] = (string) $row['table_id'];
}

$slots = [];
$remaining = [];
$maxParty = [];
foreach (gm_slots() as $slot) {
    $coversLeft = $capacity - ($used[$slot] ?? 0);
    $table = gm_pick_table($occupiedBySlot[$slot] ?? [], 1);
    $largest = 0;
    $occupied = array_flip($occupiedBySlot[$slot] ?? []);
    foreach (gm_inventory() as $item) {
        if (isset($occupied[$item['id']])) {
            continue;
        }
        if ($item['seats'] > $largest) {
            $largest = $item['seats'];
        }
    }
    $canAccept = $coversLeft > 0 && $table !== null;
    $remaining[$slot] = max(0, $coversLeft);
    $maxParty[$slot] = $canAccept ? min($coversLeft, $largest, (int) (gm_config()['max_party'] ?? 20)) : 0;
    if ($canAccept) {
        $slots[] = $slot;
    }
}

gm_json([
    'slots' => $slots,
    'remaining' => $remaining,
    'max_party' => $maxParty,
    'notice' => 'Horaires à confirmer avec le restaurant. Attribution d’une table selon les places disponibles.',
]);
