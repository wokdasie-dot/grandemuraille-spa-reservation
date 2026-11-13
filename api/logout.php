<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gm_json(['error' => 'Méthode non autorisée.'], 405);
}

gm_start_admin_session();
$body = gm_json_body();
if (!gm_csrf_check($body['csrf'] ?? null)) {
    gm_json(['error' => 'Jeton de sécurité invalide.'], 403);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();

gm_json(['ok' => true]);
