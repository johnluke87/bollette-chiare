<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/telegram.php';
require __DIR__ . '/lib/llm.php';
require __DIR__ . '/lib/offers.php';
require __DIR__ . '/lib/compare.php';
require __DIR__ . '/lib/comuni.php';

cors();
$path = route_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// HEAD (es. il controllo di cron-job.org) come GET: Apache non manda il corpo della risposta
if ($method === 'HEAD') {
    $method = 'GET';
}
$body = json_body();

try {
    if ($path === '/config' && $method === 'GET') {
        out([
            'donate_url' => cfg()['donate_url'],
            'telegram_bot' => tg_enabled() ? tg_config()['username'] : null,
            'reader' => llm_enabled(),
            'offers' => offers_status(),
        ]);
    }

    if ($path === '/telegram/webhook' && $method === 'POST') {
        if (!tg_enabled() || !hash_equals(tg_secret(), (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
            fail('Non autorizzato.', 403);
        }
        tg_handle_update($body);
        out(['ok' => true]);
    }

    // attiva il webhook del bot (una volta, dopo aver messo il token in private/bollette-chiare.php)
    if ($path === '/telegram/setup' && $method === 'GET') {
        require_cron_token();
        if (!tg_enabled()) {
            fail('Manca il bot: token e nome in private/bollette-chiare.php (chiave telegram).');
        }
        $me = tg_api('getMe');
        $hook = tg_api('setWebhook', [
            'url' => app_url() . '/api/telegram/webhook',
            'secret_token' => tg_secret(),
            'allowed_updates' => ['message'],
        ]);
        out(['bot' => $me['result']['username'] ?? null, 'webhook' => !empty($hook['ok']), 'detail' => $hook['description'] ?? null]);
    }

    // Prova senza account: dati di una bolletta (o inseriti a mano) → le offerte migliori, niente salvato
    if ($path === '/compare' && $method === 'POST') {
        rate_limit('compare', 60);
        $supply = supply_from_input($body);
        // facoltativa: una bolletta (letta dal PDF o scritta a mano) per stimare consumo, fasce e costo di oggi
        $bills = [];
        $b = $body['bill'] ?? null;
        if (is_array($b) && valid_date($b['period_from'] ?? null) && valid_date($b['period_to'] ?? null)
            && $b['period_from'] <= $b['period_to'] && (num($b['consumption'] ?? null) ?? 0) > 0) {
            $f = fn($k) => num($b[$k] ?? null);
            $bills[] = [
                'period_from' => $b['period_from'], 'period_to' => $b['period_to'], 'consumption' => $f('consumption'),
                'f1' => $f('f1'), 'f2' => $f('f2'), 'f3' => $f('f3'), 'energy_cost' => $f('energy_cost'),
            ];
        }
        $supply['annual_use'] ??= estimate_annual($supply['commodity'], $bills);
        if (!$supply['annual_use']) {
            fail($supply['commodity'] === 'gas' ? 'Indica quanti Smc consumi in un anno.' : 'Indica quanti kWh consumi in un anno.');
        }
        out(compare_supply($supply, $bills));
    }

    if ($path === '/bills/read' && $method === 'POST') {
        rate_limit('read', 20);
        if (!llm_enabled()) {
            fail('La lettura automatica non è attiva: inserisci i dati a mano.', 503);
        }
        $text = trim((string) ($body['text'] ?? ''));
        if (mb_strlen($text) < 200) {
            fail('Dal PDF non esce testo: forse è una scansione. Inserisci i dati a mano.');
        }
        daily_reads_guard();
        $res = read_bill($text);
        if (!$res || !$res['parts']) {
            fail('Non sono riuscito a leggere la bolletta: inserisci i dati a mano.', 502);
        }
        foreach ($res['parts'] as &$part) {
            $c = comune_guess($part['cap'], $part['comune']);
            $part['istat'] = $c['istat'] ?? null;
            $part['comune_label'] = $c['label'] ?? null;
        }
        unset($part);
        out($res);
    }

    if ($path === '/comuni' && $method === 'GET') {
        out(['results' => comuni_search((string) ($_GET['q'] ?? ''))]);
    }

    if ($path === '/auth/register' && $method === 'POST') {
        rate_limit('register', 5);
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || empty($body['privacy'])) {
            fail('Nome, email valida, password di almeno 8 caratteri e consenso privacy.');
        }
        try {
            db()->prepare('INSERT INTO bc_users (name, email, password_hash) VALUES (?,?,?)')
                ->execute([mb_substr($name, 0, 80), $email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException) {
            fail('Questa email è già registrata.', 409);
        }
        $id = (int) db()->lastInsertId();
        $tok = issue_email_token($id, 'verify', '+24 hours');
        $url = app_url() . '/#/verifica/' . $tok;
        app_mail($email, 'Conferma l’email — Bollette Chiare',
            "<p>Ciao " . htmlspecialchars($name) . ",</p><p>Conferma l’indirizzo per ricevere gli avvisi sulle offerte:</p><p><a href=\"$url\">$url</a></p>");
        $res = ['token' => issue_token($id), 'user' => public_user(user_row($id))];
        if (!empty(cfg()['debug'])) {
            $res['dev_verify_url'] = $url;
        }
        out($res, 201);
    }

    if ($path === '/auth/login' && $method === 'POST') {
        rate_limit('login', 8);
        $st = db()->prepare('SELECT * FROM bc_users WHERE email=?');
        $st->execute([strtolower(trim((string) ($body['email'] ?? '')))]);
        $u = $st->fetch();
        if (!$u || !password_verify((string) ($body['password'] ?? ''), $u['password_hash'])) {
            fail('Email o password non corretti.', 401);
        }
        out(['token' => issue_token((int) $u['id']), 'user' => public_user($u)]);
    }

    if ($path === '/auth/verify' && $method === 'POST') {
        $st = db()->prepare('SELECT * FROM bc_email_tokens WHERE token_hash=? AND purpose="verify" AND used_at IS NULL AND expires_at > NOW()');
        $st->execute([hash('sha256', trim((string) ($body['token'] ?? '')))]);
        $row = $st->fetch();
        if (!$row) {
            fail('Link non valido o scaduto.');
        }
        db()->prepare('UPDATE bc_users SET email_verified=1 WHERE id=?')->execute([(int) $row['user_id']]);
        db()->prepare('UPDATE bc_email_tokens SET used_at=NOW() WHERE id=?')->execute([(int) $row['id']]);
        out(['ok' => true]);
    }

    if ($path === '/auth/forgot' && $method === 'POST') {
        rate_limit('forgot', 5);
        $st = db()->prepare('SELECT * FROM bc_users WHERE email=?');
        $st->execute([strtolower(trim((string) ($body['email'] ?? '')))]);
        $u = $st->fetch();
        if ($u) {
            $tok = issue_email_token((int) $u['id'], 'reset', '+60 minutes');
            $url = app_url() . '/#/reset/' . $tok;
            app_mail($u['email'], 'Nuova password — Bollette Chiare',
                "<p>Reimposta la password (60 minuti):</p><p><a href=\"$url\">$url</a></p>");
            if (!empty(cfg()['debug'])) {
                out(['ok' => true, 'dev_reset_url' => $url]);
            }
        }
        out(['ok' => true]);
    }

    if ($path === '/auth/reset' && $method === 'POST') {
        $password = (string) ($body['password'] ?? '');
        if (strlen($password) < 8) {
            fail('Password di almeno 8 caratteri.');
        }
        $st = db()->prepare('SELECT * FROM bc_email_tokens WHERE token_hash=? AND purpose="reset" AND used_at IS NULL AND expires_at > NOW()');
        $st->execute([hash('sha256', trim((string) ($body['token'] ?? '')))]);
        $row = $st->fetch();
        if (!$row) {
            fail('Link non valido o scaduto.');
        }
        $uid = (int) $row['user_id'];
        db()->prepare('UPDATE bc_users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        db()->prepare('UPDATE bc_email_tokens SET used_at=NOW() WHERE id=?')->execute([(int) $row['id']]);
        db()->prepare('DELETE FROM bc_sessions WHERE user_id=?')->execute([$uid]);
        out(['ok' => true]);
    }

    // cron-job.org ogni ora: offerte nuove (una volta al giorno), poi gli avvisi a chi può risparmiare
    if ($path === '/cron' && $method === 'GET') {
        require_cron_token();
        $refresh = offers_refresh(!empty($_GET['force']));
        $alerts = send_alerts();
        db()->exec('DELETE FROM bc_rate_limits WHERE window_start < NOW() - INTERVAL 2 DAY');
        db()->exec('DELETE FROM bc_sessions WHERE expires_at < NOW()');
        out(['offers' => $refresh, 'alerts' => $alerts]);
    }

    $u = require_user();
    $uid = (int) $u['id'];

    if ($path === '/me' && $method === 'GET') {
        out(['user' => public_user($u)]);
    }

    if ($path === '/me' && $method === 'PUT') {
        $name = trim((string) ($body['name'] ?? $u['name']));
        if ($name === '') {
            fail('Indica un nome.');
        }
        db()->prepare('UPDATE bc_users SET name=?, notify_email=?, notify_telegram=?, min_saving=? WHERE id=?')->execute([
            mb_substr($name, 0, 80),
            (int) (bool) ($body['notify_email'] ?? $u['notify_email']),
            (int) (bool) ($body['notify_telegram'] ?? $u['notify_telegram']),
            max(10, min(1000, (int) ($body['min_saving'] ?? $u['min_saving']))),
            $uid,
        ]);
        out(['user' => public_user(user_row($uid))]);
    }

    // Collega Telegram: un codice valido 30 minuti, da aprire nel bot (t.me/BOT?start=CODICE)
    if ($path === '/me/telegram' && $method === 'POST') {
        if (!tg_enabled()) {
            fail('Telegram non è ancora attivo.');
        }
        $code = issue_email_token($uid, 'telegram', '+30 minutes');
        out(['url' => 'https://t.me/' . tg_config()['username'] . '?start=' . $code]);
    }

    if ($path === '/me/telegram' && $method === 'DELETE') {
        if (!empty($u['telegram_chat_id'])) {
            tg_send((string) $u['telegram_chat_id'], 'Scollegato da Bollette Chiare: qui non arrivano più avvisi.');
        }
        db()->prepare('UPDATE bc_users SET telegram_chat_id=NULL WHERE id=?')->execute([$uid]);
        out(['user' => public_user(user_row($uid))]);
    }

    if ($path === '/me/password' && $method === 'POST') {
        if (!password_verify((string) ($body['current'] ?? ''), $u['password_hash'])) {
            fail('Password attuale non corretta.', 403);
        }
        $np = (string) ($body['password'] ?? '');
        if (strlen($np) < 8) {
            fail('Nuova password di almeno 8 caratteri.');
        }
        db()->prepare('UPDATE bc_users SET password_hash=? WHERE id=?')->execute([password_hash($np, PASSWORD_DEFAULT), $uid]);
        out(['ok' => true]);
    }

    if ($path === '/me' && $method === 'DELETE') {
        foreach (['bc_bills', 'bc_supplies', 'bc_sessions', 'bc_email_tokens'] as $t) {
            db()->prepare("DELETE FROM $t WHERE user_id=?")->execute([$uid]);
        }
        db()->prepare('DELETE FROM bc_users WHERE id=?')->execute([$uid]);
        out(['ok' => true]);
    }

    if ($path === '/export' && $method === 'GET') {
        $s = db()->prepare('SELECT * FROM bc_supplies WHERE user_id=? ORDER BY id');
        $s->execute([$uid]);
        $b = db()->prepare('SELECT id, supply_id, period_from, period_to, consumption, f1, f2, f3, estimated, total, energy_cost, unit_price, source FROM bc_bills WHERE user_id=? ORDER BY period_from');
        $b->execute([$uid]);
        out(['supplies' => $s->fetchAll(), 'bills' => $b->fetchAll()]);
    }

    if ($path === '/supplies' && $method === 'GET') {
        $list = [];
        foreach (user_supplies($uid) as $s) {
            $bills = supply_bills((int) $s['id']);
            $list[] = public_supply($s, $bills) + ['bills_count' => count($bills)];
        }
        out(['supplies' => $list]);
    }

    if ($path === '/supplies' && $method === 'POST') {
        out(['id' => save_supply($uid, null, $body)], 201);
    }

    if (preg_match('#^/supplies/(\d+)(/compare)?$#', $path, $m)) {
        $s = own_supply((int) $m[1], $uid);
        $bills = supply_bills((int) $s['id']);
        if (!empty($m[2]) && $method === 'GET') {
            $sup = public_supply($s, $bills);
            if (!$sup['annual_use']) {
                fail('Serve almeno una bolletta o il consumo annuo per confrontare.');
            }
            $res = compare_supply($sup, $bills);
            db()->prepare('UPDATE bc_supplies SET last_best_saving=? WHERE id=?')->execute([$res['best_saving'], (int) $s['id']]);
            out($res);
        }
        if ($method === 'GET') {
            out(['supply' => public_supply($s, $bills), 'bills' => array_map('public_bill', $bills)]);
        }
        if ($method === 'PUT') {
            save_supply($uid, (int) $s['id'], $body);
            out(['ok' => true]);
        }
        if ($method === 'DELETE') {
            db()->prepare('DELETE FROM bc_bills WHERE supply_id=? AND user_id=?')->execute([(int) $s['id'], $uid]);
            db()->prepare('DELETE FROM bc_supplies WHERE id=? AND user_id=?')->execute([(int) $s['id'], $uid]);
            out(['ok' => true]);
        }
    }

    // Una bolletta (letta o scritta a mano): va su una fornitura esistente o ne crea una nuova.
    // Con update_offer=true i prezzi dell'offerta della fornitura si aggiornano con quelli della bolletta.
    if ($path === '/bills' && $method === 'POST') {
        $bill = $body['bill'] ?? [];
        if (!is_array($bill)) {
            fail('Dati della bolletta mancanti.');
        }
        db()->beginTransaction();
        $sid = (int) ($body['supply_id'] ?? 0);
        if ($sid > 0) {
            own_supply($sid, $uid);
            if (!empty($body['update_offer']) && is_array($body['supply'] ?? null)) {
                save_supply($uid, $sid, $body['supply'], true);
            }
        } else {
            $sid = save_supply($uid, null, is_array($body['supply'] ?? null) ? $body['supply'] : []);
        }
        $id = save_bill($uid, $sid, null, $bill);
        db()->commit();
        out(['id' => $id, 'supply_id' => $sid], 201);
    }

    if (preg_match('#^/bills/(\d+)$#', $path, $m)) {
        $st = db()->prepare('SELECT * FROM bc_bills WHERE id=? AND user_id=?');
        $st->execute([(int) $m[1], $uid]);
        $row = $st->fetch();
        if (!$row) {
            fail('Bolletta non trovata.', 404);
        }
        if ($method === 'PUT') {
            save_bill($uid, (int) $row['supply_id'], (int) $row['id'], $body);
            out(['ok' => true]);
        }
        if ($method === 'DELETE') {
            db()->prepare('DELETE FROM bc_bills WHERE id=? AND user_id=?')->execute([(int) $row['id'], $uid]);
            out(['ok' => true]);
        }
    }

    fail('Non trovato.', 404);
} catch (Throwable $e) {
    try {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    } catch (Throwable) {
        // il DB stesso non risponde: niente da annullare
    }
    error_log('bollette-chiare: ' . $e->getMessage());
    fail(cfg()['debug'] ? $e->getMessage() : 'Servizio non disponibile.', 500);
}

function require_cron_token(): void {
    $token = (string) cfg()['cron_token'];
    if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
        fail('Non autorizzato.', 403);
    }
}

/** Tetto giornaliero di letture con Groq per tutto il sito (file in cache/, niente IP salvati). */
function daily_reads_guard(): void {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $f = fopen($dir . '/letture-' . date('Y-m-d') . '.txt', 'c+');
    flock($f, LOCK_EX);
    $n = (int) stream_get_contents($f);
    if ($n >= (int) cfg()['max_reads_day']) {
        flock($f, LOCK_UN);
        fail('Oggi ho già letto troppe bollette: inserisci i dati a mano o riprova domani.', 429);
    }
    ftruncate($f, 0);
    rewind($f);
    fwrite($f, (string) ($n + 1));
    flock($f, LOCK_UN);
    fclose($f);
}

function user_supplies(int $uid): array {
    $st = db()->prepare('SELECT * FROM bc_supplies WHERE user_id=? ORDER BY commodity DESC, id');
    $st->execute([$uid]);
    return $st->fetchAll();
}

function own_supply(int $id, int $uid): array {
    $st = db()->prepare('SELECT * FROM bc_supplies WHERE id=? AND user_id=?');
    $st->execute([$id, $uid]);
    return $st->fetch() ?: fail('Fornitura non trovata.', 404);
}

function supply_bills(int $sid): array {
    $st = db()->prepare('SELECT * FROM bc_bills WHERE supply_id=? ORDER BY period_from');
    $st->execute([$sid]);
    return $st->fetchAll();
}

/** Campi di una fornitura dal corpo della richiesta (validati), senza salvare niente. */
function supply_from_input(array $b): array {
    $commodity = ($b['commodity'] ?? '') === 'gas' ? 'gas' : 'luce';
    $com = comune_by_code((string) ($b['istat'] ?? ''));
    $price = num($b['unit_price'] ?? null);
    return [
        'commodity' => $commodity,
        'name' => mb_substr(trim((string) ($b['name'] ?? '')) ?: ($commodity === 'gas' ? 'Gas di casa' : 'Luce di casa'), 0, 80),
        'code' => ($code = strtoupper(preg_replace('/\s+/', '', (string) ($b['code'] ?? '')))) !== '' ? mb_substr($code, 0, 20) : null,
        'seller' => ($v = trim((string) ($b['seller'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
        'offer_name' => ($v = trim((string) ($b['offer_name'] ?? ''))) !== '' ? mb_substr($v, 0, 160) : null,
        'price_type' => ($b['price_type'] ?? '') === 'variabile' ? 'variabile' : 'fisso',
        'unit_price' => $price !== null && $price > 0 && $price < 5 ? $price : null,
        'spread' => num($b['spread'] ?? null),
        'fixed_year' => ($fy = num($b['fixed_year'] ?? null)) !== null && $fy >= 0 && $fy < 2000 ? $fy : null,
        'resident' => array_key_exists('resident', $b) ? (int) (bool) $b['resident'] : 1,
        'power_kw' => ($p = num($b['power_kw'] ?? null)) !== null && $p > 0 && $p <= 100 ? $p : null,
        'comune' => $com ? $com[0] : null,
        'istat' => $com ? $com[1] : null,
        'annual_use' => ($a = num($b['annual_use'] ?? null)) !== null && $a > 0 ? (int) round($a) : null,
    ];
}

/** Crea o aggiorna una fornitura. $partial: tocca solo i campi presenti (aggiornamento da bolletta). */
function save_supply(int $uid, ?int $id, array $b, bool $partial = false): int {
    $v = supply_from_input($b);
    $cols = array_keys($v);
    if ($partial) {
        $keep = array_filter(array_keys($b), fn($k) => in_array($k, ['seller', 'offer_name', 'price_type', 'unit_price', 'spread', 'fixed_year', 'power_kw', 'istat', 'code', 'annual_use', 'resident'], true)
            && $b[$k] !== null && $b[$k] !== '');
        $cols = array_values($keep);
        if (in_array('istat', $cols, true)) {
            $cols[] = 'comune';
        }
        if (!$cols) {
            return (int) $id;
        }
    }
    if ($id) {
        $set = implode(',', array_map(fn($c) => "$c=?", $cols));
        db()->prepare("UPDATE bc_supplies SET $set, last_best_offer=NULL WHERE id=? AND user_id=?")
            ->execute([...array_map(fn($c) => $v[$c], $cols), $id, $uid]);
        return $id;
    }
    $ph = implode(',', array_fill(0, count($cols) + 1, '?'));
    db()->prepare('INSERT INTO bc_supplies (user_id,' . implode(',', $cols) . ") VALUES ($ph)")
        ->execute([$uid, ...array_values($v)]);
    return (int) db()->lastInsertId();
}

function save_bill(int $uid, int $sid, ?int $id, array $b): int {
    $from = (string) ($b['period_from'] ?? '');
    $to = (string) ($b['period_to'] ?? '');
    if (!valid_date($from) || !valid_date($to) || $from > $to) {
        fail('Indica le date del periodo fatturato (dal… al…).');
    }
    $days = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    if ($days > 400) {
        fail('Il periodo è più lungo di un anno: dividi la bolletta.');
    }
    $cons = num($b['consumption'] ?? null);
    $bands = array_map(fn($k) => ($x = num($b[$k] ?? null)) !== null && $x >= 0 ? $x : null, ['f1' => 'f1', 'f2' => 'f2', 'f3' => 'f3']);
    if (($cons === null || $cons <= 0) && $bands['f1'] !== null) {
        $cons = array_sum($bands);
    }
    if ($cons === null || $cons < 0) {
        fail('Indica il consumo del periodo.');
    }
    $money = fn($k) => ($x = num($b[$k] ?? null)) !== null && $x >= 0 && $x < 100000 ? $x : null;
    $price = num($b['unit_price'] ?? null);
    $vals = [
        $from, $to, $cons, $bands['f1'], $bands['f2'], $bands['f3'], (int) !empty($b['estimated']),
        $money('total'), $money('energy_cost'), $price !== null && $price > 0 && $price < 5 ? $price : null,
        in_array($b['source'] ?? '', ['pdf', 'manuale'], true) ? $b['source'] : 'manuale',
    ];
    if ($id) {
        db()->prepare('UPDATE bc_bills SET period_from=?, period_to=?, consumption=?, f1=?, f2=?, f3=?, estimated=?, total=?, energy_cost=?, unit_price=?, source=? WHERE id=? AND user_id=?')
            ->execute([...$vals, $id, $uid]);
    } else {
        db()->prepare('INSERT INTO bc_bills (period_from, period_to, consumption, f1, f2, f3, estimated, total, energy_cost, unit_price, source, user_id, supply_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...$vals, $uid, $sid]);
        $id = (int) db()->lastInsertId();
    }
    db()->prepare('UPDATE bc_supplies SET last_best_offer=NULL WHERE id=?')->execute([$sid]);
    return $id;
}

function public_bill(array $b): array {
    return [
        'id' => (int) $b['id'],
        'supply_id' => (int) $b['supply_id'],
        'period_from' => $b['period_from'],
        'period_to' => $b['period_to'],
        'consumption' => (float) $b['consumption'],
        'f1' => $b['f1'] !== null ? (float) $b['f1'] : null,
        'f2' => $b['f2'] !== null ? (float) $b['f2'] : null,
        'f3' => $b['f3'] !== null ? (float) $b['f3'] : null,
        'estimated' => (bool) $b['estimated'],
        'total' => $b['total'] !== null ? (float) $b['total'] : null,
        'energy_cost' => $b['energy_cost'] !== null ? (float) $b['energy_cost'] : null,
        'unit_price' => $b['unit_price'] !== null ? (float) $b['unit_price'] : null,
        'source' => $b['source'],
    ];
}
