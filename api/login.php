<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/app.php';

gm_start_admin_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (gm_admin_logged_in()) {
        gm_json(['ok' => true, 'csrf' => gm_csrf_token()]);
    }
    gm_json(['ok' => false, 'login_required' => true], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gm_json(['error' => 'Méthode non autorisée.'], 405);
}

if (!gm_rate_limit('login', 10, 10)) {
    gm_json(['error' => 'Trop de tentatives. Réessayez dans quelques minutes.'], 429);
}

$body = gm_json_body();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
$config = gm_config();
$expectedUser = (string) $config['admin_username'];
$hash = (string) $config['admin_password_hash'];

$ok = hash_equals($expectedUser, $username) && $hash !== '' && password_verify($password, $hash);
if (!$ok) {
    usleep(200000);
    gm_json(['error' => 'Identifiant ou mot de passe incorrect.'], 401);
}

session_regenerate_id(true);
$_SESSION['admin_ok'] = true;
$_SESSION['admin_user'] = $expectedUser;
$_SESSION['csrf'] = bin2hex(random_bytes(32));

gm_json(['ok' => true, 'csrf' => gm_csrf_token()]);
