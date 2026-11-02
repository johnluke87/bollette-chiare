<?php
declare(strict_types=1);

// Lettura della bolletta: il browser estrae il testo dal PDF (pdf.js) e lo manda qui;
// Groq (gratis, chiave in private/bollette-chiare.php → 'groq_key') lo trasforma in dati.
// Il PDF non arriva mai al server e il testo non si salva: resta solo il risultato.

function llm_enabled(): bool {
    return trim((string) (cfg()['groq_key'] ?? '')) !== '';
}

function llm_json(string $system, string $user): ?array {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . trim((string) cfg()['groq_key'])],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => cfg()['groq_model'],
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string) $raw, true);
    $content = $json['choices'][0]['message']['content'] ?? null;
    if ($code !== 200 || !is_string($content)) {
        error_log('bollette-chiare groq ' . $code . ': ' . substr((string) $raw, 0, 300));
        return null;
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

/** Testo di una bolletta → campi utili. Ogni campo può mancare (null): l'utente controlla e corregge. */
function read_bill(string $text): ?array {
    // le bollette lunghe ripetono: le prime pagine (sintesi) bastano quasi sempre
    $text = mb_substr(preg_replace('/[ \t]+/', ' ', $text), 0, 24000);
    $system = <<<TXT
Sei un esperto di bollette italiane di luce e gas. Dal testo di una bolletta estrai i dati e rispondi SOLO con un oggetto JSON con queste chiavi (null se il dato non c'è, numeri con il punto decimale, date AAAA-MM-GG):
{
 "commodity": "luce" | "gas" | "dual",
 "seller": nome del venditore (es. "Enel Energia"),
 "offer_name": nome dell'offerta/contratto,
 "price_type": "fisso" | "variabile",
 "code": POD (luce, inizia con IT) o PDR (gas, 14 cifre),
 "cap": CAP dell'indirizzo di fornitura,
 "comune": comune dell'indirizzo di fornitura,
 "resident": true se cliente domestico residente,
 "power_kw": potenza impegnata in kW (solo luce),
 "period_from": inizio periodo fatturato,
 "period_to": fine periodo fatturato,
 "consumption": consumo del periodo fatturato (kWh per luce, Smc per gas),
 "f1": kWh in fascia F1, "f2": kWh in F2, "f3": kWh in F3,
 "estimated": true se i consumi sono stimati e non letti,
 "annual_use": consumo annuo indicato in bolletta (kWh o Smc),
 "total": totale da pagare in euro,
 "energy_cost": spesa per la materia energia/gas naturale del periodo in euro (quota energia + quota fissa di vendita, senza trasporto, oneri e imposte),
 "unit_price": prezzo unitario della componente energia/gas in €/kWh o €/Smc (se variabile: il prezzo applicato nel periodo),
 "spread": per offerte indicizzate, lo spread in €/kWh o €/Smc sopra PUN/PSV,
 "fixed_year": quota fissa di commercializzazione/vendita in euro all'anno (se in €/mese moltiplica per 12)
}
Se la bolletta è dual (luce e gas insieme) usa "dual" e metti in "luce" e "gas" due oggetti con le stesse chiavi.
TXT;
    $data = llm_json($system, "Testo della bolletta:\n\n" . $text);
    if (!$data) {
        return null;
    }
    if (($data['commodity'] ?? '') === 'dual') {
        return ['parts' => array_values(array_filter([
            isset($data['luce']) && is_array($data['luce']) ? clean_bill(['commodity' => 'luce'] + $data['luce']) : null,
            isset($data['gas']) && is_array($data['gas']) ? clean_bill(['commodity' => 'gas'] + $data['gas']) : null,
        ]))];
    }
    return ['parts' => [clean_bill($data)]];
}

function clean_bill(array $d): array {
    $date = fn($v) => is_string($v) && valid_date($v) ? $v : null;
    $commodity = in_array($d['commodity'] ?? '', ['luce', 'gas'], true) ? $d['commodity'] : null;
    $out = [
        'commodity' => $commodity,
        'seller' => is_string($d['seller'] ?? null) ? mb_substr(trim($d['seller']), 0, 120) : null,
        'offer_name' => is_string($d['offer_name'] ?? null) ? mb_substr(trim($d['offer_name']), 0, 160) : null,
        'price_type' => ($d['price_type'] ?? '') === 'variabile' ? 'variabile' : 'fisso',
        'code' => is_string($d['code'] ?? null) ? strtoupper(preg_replace('/\s+/', '', $d['code'])) : null,
        'cap' => preg_match('/^\d{5}$/', (string) ($d['cap'] ?? '')) ? (string) $d['cap'] : null,
        'comune' => is_string($d['comune'] ?? null) ? mb_substr(trim($d['comune']), 0, 120) : null,
        'resident' => isset($d['resident']) ? (bool) $d['resident'] : null,
        'period_from' => $date($d['period_from'] ?? null),
        'period_to' => $date($d['period_to'] ?? null),
        'estimated' => !empty($d['estimated']),
    ];
    foreach (['power_kw', 'consumption', 'f1', 'f2', 'f3', 'annual_use', 'total', 'energy_cost', 'unit_price', 'spread', 'fixed_year'] as $k) {
        $v = num($d[$k] ?? null);
        $out[$k] = $v !== null && $v >= 0 ? $v : null;
    }
    // prezzi assurdi (es. letti in c€ invece che in €): meglio vuoti che sbagliati
    if ($out['unit_price'] !== null && $out['unit_price'] > 5) {
        $out['unit_price'] = $out['unit_price'] < 500 ? round($out['unit_price'] / 100, 6) : null;
    }
    return $out;
}
