<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/app.php';

gm_start_admin_session();
gm_require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    gm_json([
        'ok' => true,
        'csrf' => gm_csrf_token(),
        'reservations' => gm_list_reservations(),
    ]);
}

if ($method !== 'POST') {
    gm_json(['error' => 'Méthode non autorisée.'], 405);
}

$body = gm_json_body();
if (!gm_csrf_check($body['csrf'] ?? null)) {
    gm_json(['error' => 'Jeton de sécurité invalide.'], 403);
}

$id = isset($body['id']) ? (int) $body['id'] : 0;
$status = (string) ($body['status'] ?? '');
if ($id < 1) {
    gm_json(['error' => 'Identifiant invalide.'], 400);
}

$result = gm_update_reservation_status($id, $status);
if (empty($result['ok'])) {
    gm_json(['error' => $result['error']], (int) ($result['status'] ?? 400));
}

gm_json(['ok' => true, 'csrf' => gm_csrf_token(), 'reservation' => $result['row']]);
