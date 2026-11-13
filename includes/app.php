<?php
declare(strict_types=1);

const GM_ROOT = __DIR__ . '/..';

function gm_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }
    $override = getenv('GM_CONFIG') ?: '';
    $path = $override !== '' ? $override : GM_ROOT . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Fichier de configuration introuvable. Copiez config.example.php vers config.php.');
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php doit retourner un tableau.');
    }
    $config = $loaded;
    return $config;
}

function gm_brussels_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
}

function gm_today(): string
{
    return gm_brussels_now()->format('Y-m-d');
}

function gm_now_sql(): string
{
    return gm_brussels_now()->format('Y-m-d H:i:s');
}

function gm_pdo(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $db = gm_config()['db'];
    $charset = 'utf8mb4';
    if (!empty($db['socket'])) {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $db['socket'], $db['name'], $charset);
    } else {
        $host = $db['host'] ?? 'localhost';
        $port = (int) ($db['port'] ?? 3306);
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $db['name'], $charset);
    }
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    return $pdo;
}

function gm_json($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gm_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        gm_json(['error' => 'Données invalides.'], 400);
    }
    return $data;
}

function gm_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return $ip;
}

function gm_ip_hash(?string $ip = null): string
{
    $ip = $ip ?? gm_client_ip();
    $secret = (string) (gm_config()['ip_hash_secret'] ?? 'restoline-ip');
    return hash_hmac('sha256', $ip, $secret);
}

function gm_expand_dates(array $items): array
{
    $dates = [];
    foreach ($items as $item) {
        if (is_array($item) && count($item) === 2) {
            $start = (string) $item[0];
            $end = (string) $item[1];
            if ($start === '' || $end === '') {
                continue;
            }
            $cursor = new DateTimeImmutable($start, new DateTimeZone('Europe/Brussels'));
            $last = new DateTimeImmutable($end, new DateTimeZone('Europe/Brussels'));
            while ($cursor <= $last) {
                $dates[] = $cursor->format('Y-m-d');
                $cursor = $cursor->modify('+1 day');
            }
        } else {
            $dates[] = (string) $item;
        }
    }
    return array_values(array_unique($dates));
}

function gm_holidays(): array
{
    return gm_expand_dates(gm_config()['holidays'] ?? []);
}

function gm_closures(): array
{
    return gm_expand_dates(gm_config()['closures'] ?? []);
}

function gm_is_valid_ymd(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

/**
 * @return array{ok:bool, error?:string}
 */
function gm_validate_open_date(string $date): array
{
    if (!gm_is_valid_ymd($date)) {
        return ['ok' => false, 'error' => 'Date invalide ou déjà passée.'];
    }
    if ($date < gm_today()) {
        return ['ok' => false, 'error' => 'Date invalide ou déjà passée.'];
    }
    if (in_array($date, gm_closures(), true)) {
        return ['ok' => false, 'error' => 'Le restaurant est fermé à cette date.'];
    }
    $dt = new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('UTC'));
    $weekday = (int) $dt->format('w');
    $closedWeekday = (int) (gm_config()['closed_weekday'] ?? 2);
    $isHoliday = in_array($date, gm_holidays(), true);
    if ($weekday === $closedWeekday && !$isHoliday) {
        return ['ok' => false, 'error' => 'Date invalide ou restaurant fermé le mardi.'];
    }
    return ['ok' => true];
}

function gm_slots(): array
{
    return array_values(gm_config()['slots']);
}

function gm_load_menus(): array
{
    $path = GM_ROOT . '/data/menus.json';
    if (!is_file($path)) {
        throw new RuntimeException('data/menus.json introuvable.');
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('data/menus.json illisible.');
    }
    return $data;
}

function gm_rate_limit(string $action, ?int $max = null, ?int $windowMinutes = null): bool
{
    $config = gm_config();
    $max = $max ?? (int) ($config['rate_limit_max'] ?? 5);
    $windowMinutes = $windowMinutes ?? (int) ($config['rate_limit_window_minutes'] ?? 10);
    $pdo = gm_pdo();
    $hash = gm_ip_hash();
    $since = gm_brussels_now()->modify('-' . $windowMinutes . ' minutes')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM rate_limits WHERE ip_hash = ? AND action = ? AND created_at >= ?'
    );
    $stmt->execute([$hash, $action, $since]);
    $count = (int) $stmt->fetchColumn();
    $ins = $pdo->prepare(
        'INSERT INTO rate_limits (ip_hash, action, created_at) VALUES (?, ?, ?)'
    );
    $ins->execute([$hash, $action, gm_now_sql()]);
    return $count < $max;
}

function gm_start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('GMADMIN');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function gm_csrf_token(): string
{
    gm_start_admin_session();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function gm_csrf_check(?string $token): bool
{
    gm_start_admin_session();
    return is_string($token) && isset($_SESSION['csrf'])
        && hash_equals((string) $_SESSION['csrf'], $token);
}

function gm_admin_logged_in(): bool
{
    gm_start_admin_session();
    return !empty($_SESSION['admin_ok']);
}

function gm_require_admin(): void
{
    if (!gm_admin_logged_in()) {
        gm_json(['error' => 'Authentification requise.', 'login_required' => true], 401);
    }
}

function gm_encode_mail_header(string $value): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function gm_smtp_send(array $smtp, string $to, string $subject, string $body, string $from, string $fromName): bool
{
    $host = $smtp['host'];
    $port = (int) ($smtp['port'] ?? 587);
    $encryption = strtolower((string) ($smtp['encryption'] ?? ''));
    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if ($fp === false) {
        error_log('SMTP connexion: ' . $errstr);
        return false;
    }
    $read = static function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = static function (string $line) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        return $read();
    };
    $read();
    $cmd('EHLO grandemuraillespa.be');
    if ($encryption === 'tls') {
        $cmd('STARTTLS');
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $cmd('EHLO grandemuraillespa.be');
    }
    if (!empty($smtp['username'])) {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode((string) $smtp['username']));
        $auth = $cmd(base64_encode((string) $smtp['password']));
        if (!str_starts_with($auth, '235')) {
            fclose($fp);
            return false;
        }
    }
    $cmd('MAIL FROM:<' . $from . '>');
    $cmd('RCPT TO:<' . $to . '>');
    $cmd('DATA');
    $encodedSubject = gm_encode_mail_header($subject);
    $encodedFrom = gm_encode_mail_header($fromName) . ' <' . $from . '>';
    $payload = "From: $encodedFrom\r\n";
    $payload .= "To: <$to>\r\n";
    $payload .= "Subject: $encodedSubject\r\n";
    $payload .= "MIME-Version: 1.0\r\n";
    $payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $payload .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $payload .= $body . "\r\n.";
    $cmd($payload);
    $cmd('QUIT');
    fclose($fp);
    return true;
}

function gm_send_mail(string $to, string $subject, string $body): bool
{
    $config = gm_config();
    $from = (string) $config['mail_from'];
    $fromName = (string) $config['mail_from_name'];
    if (!empty($config['mail_log_only'])) {
        $dir = GM_ROOT . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $entry = gm_now_sql() . "\nTO: $to\nSUBJECT: $subject\n\n$body\n----\n";
        file_put_contents($dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
        return true;
    }
    $smtp = $config['smtp'] ?? [];
    if (!empty($smtp['host'])) {
        return gm_smtp_send($smtp, $to, $subject, $body, $from, $fromName);
    }
    $encodedSubject = gm_encode_mail_header($subject);
    $encodedFrom = gm_encode_mail_header($fromName) . ' <' . $from . '>';
    $headers = [
        'From: ' . $encodedFrom,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) {
        error_log('mail() a échoué pour ' . $to);
        $dir = GM_ROOT . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $entry = gm_now_sql() . " [MAIL() FAILED]\nTO: $to\nSUBJECT: $subject\n\n$body\n----\n";
        file_put_contents($dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
    }
    return $ok;
}


function gm_mail_signature(): string
{
    $config = gm_config();
    $legal = trim((string) ($config['legal_name'] ?? 'SRL JIN-ZHOU'));
    $vat = trim((string) ($config['vat_number'] ?? 'BE 0812011942'));
    $line = $legal;
    if ($vat !== '') {
        $line = $line === '' ? 'TVA ' . $vat : $line . ' · TVA ' . $vat;
    }
    $sig = "La Grande Muraille de Chine\nAvenue Reine Astrid 98, 4900 Spa\n087 22 08 88\n";
    if ($line !== '') {
        $sig .= $line . "\n";
    }
    return $sig;
}

function gm_mail_demande_client(array $row): void
{
    $subject = 'Demande de réservation reçue — La Grande Muraille de Chine';
    $body = "Bonjour {$row['name']},\n\n";
    $body .= "Nous avons bien reçu votre demande de réservation pour le {$row['date']} à {$row['time']}, ";
    $body .= "{$row['guests']} personne(s).\n\n";
    $body .= "Elle est en attente de confirmation. Nous vous écrivons dès qu’elle est confirmée ou si nous ne pouvons pas l’accepter.\n\n";
    $body .= gm_mail_signature();
    gm_send_mail($row['email'], $subject, $body);
}

function gm_mail_demande_restaurant(array $row): void
{
    $to = (string) (gm_config()['restaurant_email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('restaurant_email manquant ou invalide : notification non envoyée.');
        return;
    }
    $subject = 'Nouvelle demande de réservation — ' . $row['date'] . ' ' . $row['time'];
    $body = "Nouvelle demande de réservation (en attente) :\n\n";
    $body .= "Date : {$row['date']}\nHeure : {$row['time']}\nPersonnes : {$row['guests']}\n";
    $body .= "Nom : {$row['name']}\nE-mail : {$row['email']}\nTéléphone : {$row['phone']}\n";
    $tableLine = trim((string) ($row['table_label'] ?? ''));
    if ($tableLine !== '') {
        $body .= "Table prévue : {$tableLine}\n";
    }
    if (!empty($row['needs_call'])) {
        $body .= "À confirmer de préférence par téléphone (groupe de {$row['guests']} personnes).\n";
    }
    $body .= "Remarque : {$row['note']}\nRéférence : {$row['id']}\n\n";
    $body .= gm_mail_signature();
    gm_send_mail($to, $subject, $body);
}

function gm_mail_statut_client(array $row, string $status): void
{
    if ($status === 'confirmee') {
        $subject = 'Réservation confirmée — La Grande Muraille de Chine';
        $body = "Bonjour {$row['name']},\n\n";
        $body .= "Votre réservation est confirmée pour le {$row['date']} à {$row['time']}, {$row['guests']} personne(s).\n\n";
        $body .= "À bientôt.\n\n" . gm_mail_signature();
    } elseif ($status === 'annulee') {
        $subject = 'Réservation annulée — La Grande Muraille de Chine';
        $body = "Bonjour {$row['name']},\n\n";
        $body .= "Votre demande de réservation du {$row['date']} à {$row['time']} a été annulée.\n\n";
        $body .= "Pour toute question, vous pouvez nous appeler au 087 22 08 88.\n\n";
        $body .= gm_mail_signature();
    } else {
        $subject = 'Votre demande de réservation — La Grande Muraille de Chine';
        $body = "Bonjour {$row['name']},\n\n";
        $body .= "Votre demande pour le {$row['date']} à {$row['time']} est de nouveau en attente de confirmation.\n\n";
        $body .= gm_mail_signature();
    }
    gm_send_mail($row['email'], $subject, $body);
}

/**
 * @return array{ok:bool, id?:int, error?:string, status?:int, row?:array}
 */

function gm_inventory(): array
{
    static $list;
    if ($list !== null) {
        return $list;
    }
    $list = [];
    $groups = gm_config()['tables'] ?? [];
    foreach ($groups as $group) {
        $count = (int) ($group['count'] ?? 0);
        $seats = (int) ($group['seats'] ?? 0);
        if ($count < 1 || $seats < 1) {
            continue;
        }
        $shape = (string) ($group['shape'] ?? 'rectangle');
        $labelBase = (string) ($group['label'] ?? (
            $shape === 'round' ? 'Table ronde de ' . $seats : 'Table de ' . $seats
        ));
        $prefix = $shape === 'round' ? 'ronde' : 't';
        for ($i = 1; $i <= $count; $i++) {
            $id = $prefix . $seats . '-' . $i;
            $label = $count > 1 ? $labelBase . ' n°' . $i : $labelBase;
            $list[] = [
                'id' => $id,
                'seats' => $seats,
                'shape' => $shape,
                'label' => $label,
            ];
        }
    }
    return $list;
}

function gm_capacity(): int
{
    $configured = (int) (gm_config()['capacity'] ?? 0);
    $sum = 0;
    foreach (gm_inventory() as $table) {
        $sum += (int) $table['seats'];
    }
    if ($configured > 0) {
        return $configured;
    }
    return $sum;
}

function gm_phone_confirm_from(): int
{
    return (int) (gm_config()['phone_confirm_from'] ?? 12);
}

function gm_needs_call(int $guests): bool
{
    return $guests >= gm_phone_confirm_from();
}

/**
 * Plus petite table libre dont le nombre de places convient (best-fit).
 * Une réservation occupe une seule table, sans regroupement.
 *
 * @param list<string> $occupiedIds
 * @return array{id:string,seats:int,shape:string,label:string}|null
 */
function gm_pick_table(array $occupiedIds, int $guests): ?array
{
    $occupied = array_flip($occupiedIds);
    $best = null;
    foreach (gm_inventory() as $table) {
        if (isset($occupied[$table['id']])) {
            continue;
        }
        if ($table['seats'] < $guests) {
            continue;
        }
        if ($best === null || $table['seats'] < $best['seats']) {
            $best = $table;
        }
    }
    return $best;
}

/**
 * @return list<string>
 */
function gm_occupied_table_ids(\PDO $pdo, string $date, string $time, bool $forUpdate = false): array
{
    $sql = "SELECT table_id FROM reservations
            WHERE date = ? AND time_slot = ? AND status <> 'annulee' AND table_id <> ''";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date, $time]);
    $ids = [];
    foreach ($stmt as $row) {
        $ids[] = (string) $row['table_id'];
    }
    return $ids;
}

function gm_create_reservation(array $input): array
{
    $config = gm_config();
    $website = trim((string) ($input['website'] ?? ''));
    if ($website !== '') {
        return ['ok' => false, 'error' => 'Vérifiez la date, le nombre de personnes et vos coordonnées.', 'status' => 400];
    }

    $date = trim((string) ($input['date'] ?? ''));
    $time = trim((string) ($input['time'] ?? $input['time_slot'] ?? ''));
    $guests = $input['guests'] ?? null;
    if (is_string($guests) && is_numeric($guests)) {
        $guests = (int) $guests;
    }
    $name = trim((string) ($input['name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $note = trim((string) ($input['note'] ?? ''));

    $open = gm_validate_open_date($date);
    $maxParty = (int) ($config['max_party'] ?? 20);
    $capacity = gm_capacity();
    $valid =
        !empty($open['ok'])
        && in_array($time, gm_slots(), true)
        && is_int($guests) && $guests >= 1 && $guests <= $maxParty
        && mb_strlen($name) >= 2 && mb_strlen($name) <= 100
        && mb_strlen($email) <= 200 && (bool) preg_match('/^\S+@\S+\.\S+$/', $email)
        && mb_strlen($phone) >= 6 && mb_strlen($phone) <= 30
        && mb_strlen($note) <= 1000;

    if (!$valid) {
        return [
            'ok' => false,
            'error' => $open['error'] ?? 'Vérifiez la date, le nombre de personnes et vos coordonnées.',
            'status' => 400,
        ];
    }

    $pdo = gm_pdo();
    $lockName = 'resa_' . $date . '_' . str_replace(':', '', $time);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        return ['ok' => false, 'error' => 'Le serveur est occupé. Réessayez dans un instant.', 'status' => 503];
    }

    try {
        $pdo->beginTransaction();
        $sum = $pdo->prepare(
            "SELECT COALESCE(SUM(guests), 0) FROM reservations
             WHERE date = ? AND time_slot = ? AND status <> 'annulee' FOR UPDATE"
        );
        $sum->execute([$date, $time]);
        $used = (int) $sum->fetchColumn();
        if ($used + $guests > $capacity) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Ce créneau est complet.', 'status' => 409];
        }
        $occupied = gm_occupied_table_ids($pdo, $date, $time, true);
        $table = gm_pick_table($occupied, $guests);
        if ($table === null) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Aucune table disponible pour ce nombre de personnes.', 'status' => 409];
        }
        $ins = $pdo->prepare(
            'INSERT INTO reservations (date, time_slot, guests, name, email, phone, note, table_id, table_seats, table_label, status, created_at, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'en_attente\', ?, ?)'
        );
        $ins->execute([
            $date, $time, $guests, $name, $email, $phone, $note,
            $table['id'], $table['seats'], $table['label'],
            gm_now_sql(), gm_ip_hash(),
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($e->getMessage());
        return ['ok' => false, 'error' => 'Erreur lors de l’enregistrement.', 'status' => 500];
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }

    $row = [
        'id' => $id,
        'date' => $date,
        'time' => $time,
        'guests' => $guests,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'note' => $note,
        'status' => 'en_attente',
        'table_id' => $table['id'],
        'table_seats' => $table['seats'],
        'table_label' => $table['label'],
        'needs_call' => gm_needs_call($guests),
    ];
    try {
        gm_mail_demande_client($row);
        gm_mail_demande_restaurant($row);
    } catch (Throwable $e) {
        error_log('E-mail réservation: ' . $e->getMessage());
    }

    return [
        'ok' => true,
        'id' => $id,
        'row' => $row,
        'message' => 'Demande enregistrée. Elle est en attente de confirmation ; vous recevrez un e-mail dès qu’elle sera traitée.',
    ];
}

function gm_list_reservations(): array
{
    $stmt = gm_pdo()->query(
        'SELECT id, date, time_slot AS time, guests, name, email, phone, note,
                table_id, table_seats, table_label, status, created_at
         FROM reservations
         ORDER BY date DESC, time_slot DESC, id DESC'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['guests'] = (int) $row['guests'];
        $row['table_seats'] = (int) $row['table_seats'];
        $row['needs_call'] = gm_needs_call((int) $row['guests']);
    }
    unset($row);
    return $rows;
}

function gm_update_reservation_status(int $id, string $status): array
{
    if (!in_array($status, ['en_attente', 'confirmee', 'annulee'], true)) {
        return ['ok' => false, 'error' => 'Statut invalide.', 'status' => 400];
    }
    $pdo = gm_pdo();
    $sel = $pdo->prepare(
        'SELECT id, date, time_slot AS time, guests, name, email, phone, note,
                table_id, table_seats, table_label, status
         FROM reservations WHERE id = ?'
    );
    $sel->execute([$id]);
    $row = $sel->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Réservation introuvable.', 'status' => 404];
    }
    $upd = $pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?');
    $upd->execute([$status, $id]);
    $row['status'] = $status;
    if ($status !== 'en_attente') {
        try {
            gm_mail_statut_client($row, $status);
        } catch (Throwable $e) {
            error_log('E-mail statut: ' . $e->getMessage());
        }
    } else {
        try {
            gm_mail_statut_client($row, $status);
        } catch (Throwable $e) {
            error_log('E-mail statut: ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'row' => $row];
}
