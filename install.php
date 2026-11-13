<?php
declare(strict_types=1);
// Installateur unique, sans secret : reçoit config.php en POST HTTPS
// (protégé par un jeton dont seul le hash SHA-256 figure ici),
// importe le schéma SQL, puis se supprime.
const GM_TOKEN_SHA256 = '543ba33c70caeef88fd148d1dc36e4350fa689f4d1f78a4d79594f9c2f7c1deb';
$cfg = __DIR__ . '/config.php';
if (is_file($cfg)
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !hash_equals(GM_TOKEN_SHA256, hash('sha256', (string)($_POST['t'] ?? '')))) {
    http_response_code(404); exit;
}
header('Content-Type: text/plain; charset=utf-8');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if (!$https) { http_response_code(400); echo "HTTPS requis\n"; exit; }
$php = (string)($_POST['config_php'] ?? '');
if (strncmp($php, '<?php', 5) !== 0) { http_response_code(400); echo "config invalide\n"; exit; }
$tmp = __DIR__ . '/config.php.tmp';
file_put_contents($tmp, $php, LOCK_EX); @chmod($tmp, 0640);
try {
    $c = require $tmp;
    if (!is_array($c) || empty($c['db']['name'])) { throw new RuntimeException('config sans base'); }
    $d = $c['db'];
    $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { @unlink($tmp); http_response_code(500); echo "ERREUR config/connexion\n"; exit; }
rename($tmp, $cfg);
foreach (['schema.sql', 'alter_add_tables.sql'] as $f) {
    $p = __DIR__ . '/sql/' . $f; if (!is_file($p)) { continue; }
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($p));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
        try { $pdo->exec($q); } catch (Throwable $e) { echo "WARN $f: " . $e->getMessage() . "\n"; }
    }
}
echo 'TABLES: ' . implode(',', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . "\n";
@unlink(__FILE__);
echo "OK install supprime\n";
