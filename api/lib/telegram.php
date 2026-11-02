<?php
declare(strict_types=1);

// Promemoria su Telegram con un bot proprio (creato con @BotFather).
// Sul sito il bot sta in private/bollette-chiare.php (chiave 'telegram'):
//   return ['telegram' => ['token' => '123456:ABC...', 'username' => 'NomeDelBot']];
// Il bot riceve i messaggi con un webhook su api/telegram/webhook: si attiva una volta con
// api/telegram/setup?token=CRON_TOKEN (dopo aver messo il token del bot).

function tg_config(): array {
    $t = cfg()['telegram'] ?? [];
    return [
        'token' => trim((string) ($t['token'] ?? '')),
        'username' => ltrim(trim((string) ($t['username'] ?? '')), '@'),
    ];
}

function tg_enabled(): bool {
    $t = tg_config();
    return $t['token'] !== '' && $t['username'] !== '';
}

/** Segreto del webhook: Telegram lo rimanda in ogni chiamata, così nessun altro può fingersi il bot. */
function tg_secret(): string {
    return substr(hash_hmac('sha256', 'bollette-chiare-webhook', tg_config()['token']), 0, 48);
}

function tg_api(string $method, array $params = []): array {
    $ch = curl_init('https://api.telegram.org/bot' . tg_config()['token'] . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string) $raw, true);
    if (!is_array($json) || empty($json['ok'])) {
        error_log('bollette-chiare telegram ' . $method . ': ' . substr((string) $raw, 0, 300));
        return ['ok' => false, 'description' => $json['description'] ?? 'nessuna risposta'];
    }
    return $json;
}

function tg_send(string $chatId, string $html): bool {
    if (!tg_enabled()) {
        return false;
    }
    if (is_local()) {
        // in locale non parte niente: il messaggio finisce in data/outbox come le email
        app_mail('telegram-' . $chatId, 'Telegram', nl2br($html));
        return true;
    }
    return !empty(tg_api('sendMessage', ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true])['ok']);
}

/** Messaggi in arrivo dal bot: /start CODICE collega l'account, /stop lo scollega. */
function tg_handle_update(array $update): void {
    $msg = $update['message'] ?? null;
    if (!$msg || !isset($msg['chat']['id'])) {
        return;
    }
    $chat = (string) $msg['chat']['id'];
    $text = trim((string) ($msg['text'] ?? ''));

    if (preg_match('/^\/start(?:@\w+)?\s+([a-f0-9]{48})$/', $text, $m)) {
        $st = db()->prepare('SELECT * FROM bc_email_tokens WHERE token_hash=? AND purpose="telegram" AND used_at IS NULL AND expires_at > NOW()');
        $st->execute([hash('sha256', $m[1])]);
        $row = $st->fetch();
        if (!$row) {
            tg_send($chat, 'Il link è scaduto. Apri Bollette Chiare → Impostazioni e premi di nuovo <b>Collega Telegram</b>.');
            return;
        }
        // una chat appartiene a un solo account
        db()->prepare('UPDATE bc_users SET telegram_chat_id=NULL WHERE telegram_chat_id=?')->execute([$chat]);
        db()->prepare('UPDATE bc_users SET telegram_chat_id=?, notify_telegram=1 WHERE id=?')->execute([$chat, (int) $row['user_id']]);
        db()->prepare('UPDATE bc_email_tokens SET used_at=NOW() WHERE id=?')->execute([(int) $row['id']]);
        tg_send($chat, "✅ Fatto! Da ora gli avvisi di <b>Bollette Chiare</b> arrivano anche qui.\nPer smettere scrivi /stop.");
        return;
    }
    if (preg_match('/^\/stop\b/', $text)) {
        db()->prepare('UPDATE bc_users SET telegram_chat_id=NULL WHERE telegram_chat_id=?')->execute([$chat]);
        tg_send($chat, 'Scollegato: qui non arrivano più avvisi. Per ricollegarti usa Impostazioni nell’app.');
        return;
    }
    tg_send($chat, "Ciao! Sono il bot degli avvisi di <b>Bollette Chiare</b>.\nPer collegarmi apri l’app → Impostazioni → <b>Collega Telegram</b>.\n" . app_url() . '/');
}
