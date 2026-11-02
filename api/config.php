<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'bollette_chiare',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'debug' => null, // null = solo in locale
    'token_days' => 30,
    'cron_token' => 'bollette-chiare-cron-locale', // sul sito: in private/bollette-chiare.php
    'app_url' => '', // vuoto = dalla richiesta
    'donate_url' => 'https://ko-fi.com/johnluke87',
    // lettura delle bollette: chiave gratuita di console.groq.com, sul sito in private/bollette-chiare.php
    'groq_key' => '',
    'groq_model' => 'openai/gpt-oss-120b',
    'max_reads_day' => 300, // letture di bollette al giorno per tutto il sito (protegge il credito gratuito)
    // bot Telegram facoltativo: ['token' => '123:ABC', 'username' => 'BolletteChiareBot']
    'telegram' => [],
];
