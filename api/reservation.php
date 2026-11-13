<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gm_json(['error' => 'Méthode non autorisée.'], 405);
}

if (!gm_rate_limit('reservation')) {
    gm_json(['error' => 'Trop de demandes. Réessayez dans quelques minutes.'], 429);
}

$result = gm_create_reservation(gm_json_body());
if (empty($result['ok'])) {
    gm_json(['error' => $result['error']], (int) ($result['status'] ?? 400));
}

gm_json([
    'id' => $result['id'],
    'message' => $result['message'],
    'table_id' => $result['row']['table_id'] ?? '',
    'table_label' => $result['row']['table_label'] ?? '',
    'needs_call' => !empty($result['row']['needs_call']),
], 201);
