<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Rome');

function is_local(): bool {
    return !isset($_SERVER['SERVER_ADDR']) || in_array($_SERVER['SERVER_ADDR'], ['127.0.0.1', '::1', 'localhost'], true);
}

/** Un file della cartella private/ del sito (www.gianlucadario.com), cercato nelle cartelle superiori. */
function site_private(string $file): ?string {
    for ($dir = __DIR__, $i = 0; $i < 7; $i++, $dir = dirname($dir)) {
        if (is_file($dir . '/private/' . $file)) {
            return $dir . '/private/' . $file;
        }
    }
    return null;
}

function cfg(): array {
    static $c;
    if (!$c) {
        $c = require dirname(__DIR__) . '/config.php';
        // sul sito: segreti (cron_token, groq_key, telegram) in private/bollette-chiare.php; in sviluppo: config.local.php
        foreach ([site_private('bollette-chiare.php'), dirname(__DIR__) . '/config.local.php'] as $file) {
            if ($file && is_file($file)) {
                $extra = require $file;
                if (is_array($extra)) {
                    $c = array_replace_recursive($c, $extra);
                }
            }
        }
        $c['debug'] ??= is_local();
    }
    return $c;
}

/** Indirizzo dell'app (per i link nelle email): da config, altrimenti dalla richiesta. */
function app_url(): string {
    if (!empty(cfg()['app_url'])) {
        return rtrim((string) cfg()['app_url'], '/');
    }
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php', 2)), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
}

function db(): PDO {
    static $pdo;
    if ($pdo) {
        return $pdo;
    }
    $c = cfg()['db'];
    // sul sito: credenziali in /private/db.php (sezione "bollette"), DB condiviso con altre app: tabelle bc_
    if ($file = site_private('db.php')) {
        $all = require $file;
        if (isset($all['bollette'])) {
            $p = $all['bollette'][is_local() ? 'local' : 'prod'];
            $c = ['host' => $p['host'], 'name' => $p['db'], 'user' => $p['user'], 'pass' => $p['password'], 'charset' => 'utf8mb4', 'create' => is_local()];
        }
    }
    $name = preg_replace('/[^a-z0-9_]/i', '', $c['name']);
    if ($c['create'] ?? true) {
        $root = new PDO("mysql:host={$c['host']};charset={$c['charset']}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $root->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $pdo = new PDO(
        "mysql:host={$c['host']};dbname={$name};charset={$c['charset']}",
        $c['user'],
        $c['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    schema_install($pdo);
    return $pdo;
}

// Niente colonne JSON (online c'è MySQL 5.6): i dati letti dalla bolletta stanno in TEXT.
function schema_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bc_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        email_verified TINYINT(1) NOT NULL DEFAULT 0,
        telegram_chat_id VARCHAR(32) NULL,
        notify_email TINYINT(1) NOT NULL DEFAULT 1,
        notify_telegram TINYINT(1) NOT NULL DEFAULT 1,
        min_saving INT UNSIGNED NOT NULL DEFAULT 50,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bc_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bc_email_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        purpose VARCHAR(20) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bc_rate_limits (
        ip VARCHAR(45) NOT NULL,
        action VARCHAR(40) NOT NULL,
        window_start DATETIME NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (ip, action, window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // una fornitura = un contatore (luce: POD, gas: PDR) con l'offerta che si paga oggi
    $pdo->exec("CREATE TABLE IF NOT EXISTS bc_supplies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        commodity CHAR(4) NOT NULL,
        name VARCHAR(80) NOT NULL,
        code VARCHAR(20) NULL,
        seller VARCHAR(120) NULL,
        offer_name VARCHAR(160) NULL,
        price_type VARCHAR(10) NOT NULL DEFAULT 'fisso',
        unit_price DECIMAL(10,6) NULL,
        spread DECIMAL(10,6) NULL,
        fixed_year DECIMAL(10,2) NULL,
        resident TINYINT(1) NOT NULL DEFAULT 1,
        power_kw DECIMAL(5,2) NULL,
        comune VARCHAR(120) NULL,
        istat CHAR(6) NULL,
        annual_use INT UNSIGNED NULL,
        last_best_saving INT NULL,
        last_best_offer VARCHAR(60) NULL,
        alerted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bc_bills (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        supply_id INT UNSIGNED NOT NULL,
        period_from DATE NOT NULL,
        period_to DATE NOT NULL,
        consumption DECIMAL(10,2) NOT NULL,
        f1 DECIMAL(10,2) NULL,
        f2 DECIMAL(10,2) NULL,
        f3 DECIMAL(10,2) NULL,
        estimated TINYINT(1) NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NULL,
        energy_cost DECIMAL(10,2) NULL,
        unit_price DECIMAL(10,6) NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'manuale',
        extracted TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (supply_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function out(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never {
    out(['error' => $msg], $code);
}

function client_ip(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 45);
}

function rate_limit(string $action, int $max): void {
    $ip = client_ip();
    $hour = (new DateTimeImmutable('now'))->format('Y-m-d H:00:00');
    $st = db()->prepare('INSERT INTO bc_rate_limits (ip, action, window_start, hits) VALUES (?,?,?,1)
        ON DUPLICATE KEY UPDATE hits = hits + 1');
    $st->execute([$ip, $action, $hour]);
    $q = db()->prepare('SELECT hits FROM bc_rate_limits WHERE ip=? AND action=? AND window_start=?');
    $q->execute([$ip, $action, $hour]);
    if ((int) $q->fetchColumn() > $max) {
        fail('Troppe richieste, riprova tra un’ora.', 429);
    }
}

function route_path(): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = $path ?: '/';
    return rtrim($path, '/') ?: '/';
}

function bearer(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        $h = (string) (array_change_key_case(getallheaders())['authorization'] ?? '');
    }
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) {
        return trim($m[1]);
    }
    return null;
}

function public_user(array $u): array {
    return [
        'id' => (int) $u['id'],
        'name' => $u['name'],
        'email' => $u['email'],
        'email_verified' => (bool) $u['email_verified'],
        'notify_email' => (bool) $u['notify_email'],
        'notify_telegram' => (bool) $u['notify_telegram'],
        'telegram_linked' => !empty($u['telegram_chat_id']),
        'min_saving' => (int) $u['min_saving'],
    ];
}

function issue_token(int $userId): string {
    $raw = bin2hex(random_bytes(32));
    $days = (int) cfg()['token_days'];
    $exp = (new DateTimeImmutable("+$days days"))->format('Y-m-d H:i:s');
    db()->prepare('INSERT INTO bc_sessions (user_id, token_hash, expires_at) VALUES (?,?,?)')->execute([$userId, hash('sha256', $raw), $exp]);
    return $raw;
}

function require_user(): array {
    $raw = bearer();
    if (!$raw) {
        fail('Devi entrare.', 401);
    }
    $st = db()->prepare('SELECT u.* FROM bc_sessions s JOIN bc_users u ON u.id = s.user_id
        WHERE s.token_hash = ? AND s.expires_at > NOW()');
    $st->execute([hash('sha256', $raw)]);
    $u = $st->fetch();
    if (!$u) {
        fail('Sessione scaduta. Entra di nuovo.', 401);
    }
    return $u;
}

function user_row(int $id): array {
    $st = db()->prepare('SELECT * FROM bc_users WHERE id=?');
    $st->execute([$id]);
    return $st->fetch() ?: [];
}

function valid_date(mixed $d): bool {
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

/** Numero da input utente o da bolletta: accetta "1.234,56", "1234.56", "0,1234". */
function num(mixed $v): ?float {
    if ($v === null || $v === '') {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return (float) $v;
    }
    $s = preg_replace('/[^0-9,.\-]/', '', (string) $v);
    if ($s === '' || $s === '-') {
        return null;
    }
    if (str_contains($s, ',')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

function issue_email_token(int $userId, string $purpose, string $ttl): string {
    $raw = bin2hex(random_bytes(24));
    $exp = (new DateTimeImmutable($ttl))->format('Y-m-d H:i:s');
    db()->prepare('INSERT INTO bc_email_tokens (user_id, purpose, token_hash, expires_at) VALUES (?,?,?,?)')
        ->execute([$userId, $purpose, hash('sha256', $raw), $exp]);
    return $raw;
}

function app_mail(string $to, string $subject, string $html): void {
    // sul sito: la casella SMTP comune (private/mailer.php); in locale le email finiscono in data/outbox
    if (!is_local() && ($mailer = site_private('mailer.php'))) {
        require_once $mailer;
        site_send_mail($to, $subject, $html);
        return;
    }
    $dir = dirname(__DIR__) . '/data/outbox';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]+/i', '-', $to) . '.html';
    $body = "<p><strong>A:</strong> " . htmlspecialchars($to) . "<br><strong>Oggetto:</strong> "
        . htmlspecialchars($subject) . "</p>" . $html;
    file_put_contents($dir . '/' . $name, $body);
}

function http_get(string $url, int $timeout = 60): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'BolletteChiare/1.0 (+https://www.gianlucadario.com/apps/bollette-chiare/)',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body === false || $code >= 400) ? null : (string) $body;
}
