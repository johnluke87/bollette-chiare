<?php
declare(strict_types=1);

// Confronto: quanto costerebbe in un anno la parte della bolletta che dipende dal venditore
// (prezzo dell'energia/gas, quote fisse di vendita, sconti senza condizioni). Trasporto, oneri
// di sistema e accise sono gli stessi con qualsiasi venditore, quindi non spostano il risparmio;
// l'IVA invece sì e si aggiunge (luce 10%; gas 10% sui consumi fino a 480 Smc l'anno, 22% oltre
// e sulla quota fissa, come nelle bollette).

// Peso di ogni mese sul consumo dell'anno (gennaio...dicembre). Gas: riscaldamento d'inverno.
const MONTH_WEIGHTS = [
    'luce' => [0.095, 0.085, 0.085, 0.075, 0.075, 0.08, 0.095, 0.09, 0.075, 0.075, 0.08, 0.09],
    'gas' => [0.18, 0.155, 0.12, 0.07, 0.035, 0.025, 0.02, 0.02, 0.025, 0.05, 0.11, 0.19],
];
// fasce di una famiglia tipo quando le bollette non le dicono (F1, F2, F3)
const DEFAULT_SPLIT = [0.33, 0.31, 0.36];

/** Quanta parte dell'anno "pesa" un periodo, secondo il profilo mensile. */
function period_weight(string $commodity, string $from, string $to): float {
    $w = 0.0;
    $d = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($d <= $end) {
        $w += MONTH_WEIGHTS[$commodity][(int) $d->format('n') - 1] / (int) $d->format('t');
        $d = $d->modify('+1 day');
    }
    return $w;
}

/** Consumo annuo stimato dalle bollette (le ultime che coprono al massimo un anno). */
function estimate_annual(string $commodity, array $bills): ?int {
    $bills = array_reverse($bills);
    $sum = 0.0;
    $weight = 0.0;
    $days = 0;
    foreach ($bills as $b) {
        $len = (new DateTimeImmutable($b['period_from']))->diff(new DateTimeImmutable($b['period_to']))->days + 1;
        if ($days + $len > 400) {
            break;
        }
        $sum += (float) $b['consumption'];
        $weight += period_weight($commodity, $b['period_from'], $b['period_to']);
        $days += $len;
    }
    return $weight > 0.02 ? (int) round($sum / $weight) : null;
}

function band_split(array $bills): array {
    $f = [0.0, 0.0, 0.0];
    foreach ($bills as $b) {
        if ($b['f1'] !== null && $b['f2'] !== null) {
            $f[0] += (float) $b['f1'];
            $f[1] += (float) $b['f2'];
            $f[2] += (float) ($b['f3'] ?? 0);
        }
    }
    $tot = array_sum($f);
    return $tot > 0 ? array_map(fn($x) => $x / $tot, $f) : DEFAULT_SPLIT;
}

function public_supply(array $s, array $bills): array {
    $estimated = estimate_annual($s['commodity'], $bills);
    $last = $bills ? end($bills) : null;
    return [
        'id' => isset($s['id']) ? (int) $s['id'] : null,
        'commodity' => $s['commodity'],
        'name' => $s['name'],
        'code' => $s['code'],
        'seller' => $s['seller'],
        'offer_name' => $s['offer_name'],
        'price_type' => $s['price_type'],
        'unit_price' => $s['unit_price'] !== null ? (float) $s['unit_price'] : null,
        'spread' => $s['spread'] !== null ? (float) $s['spread'] : null,
        'fixed_year' => $s['fixed_year'] !== null ? (float) $s['fixed_year'] : null,
        'resident' => (bool) $s['resident'],
        'power_kw' => $s['power_kw'] !== null ? (float) $s['power_kw'] : null,
        'comune' => $s['comune'] ?? null,
        'istat' => $s['istat'] ?? null,
        'annual_use_set' => $s['annual_use'] !== null ? (int) $s['annual_use'] : null,
        'annual_use_estimated' => $estimated,
        'annual_use' => $s['annual_use'] !== null ? (int) $s['annual_use'] : $estimated,
        'last_bill' => $last ? ['period_to' => $last['period_to'], 'total' => $last['total'] !== null ? (float) $last['total'] : null] : null,
        'best_saving' => isset($s['last_best_saving']) && $s['last_best_saving'] !== null ? (int) $s['last_best_saving'] : null,
    ];
}

/** $fixed: la parte di $cost che è quota fissa (nel gas va al 22%). */
function with_vat(string $commodity, float $cost, int $use, float $fixed = 0.0): float {
    if ($commodity === 'luce') {
        return $cost * 1.10;
    }
    $low = $use <= 480 ? 1.0 : 480 / $use;
    return ($cost - $fixed) * ($low * 1.10 + (1 - $low) * 1.22) + $fixed * 1.22;
}

function fixed_part(array $o): float {
    return (float) $o['fixed_year'] - (float) ($o['discount_year'] ?? 0);
}

/** Prezzo per unità (€/kWh o €/Smc) di un'offerta, fascia per fascia, con l'indice di oggi se è variabile. */
function unit_prices(array $o, string $commodity, array $par): array {
    $p = [(float) $o['p1'], (float) ($o['p2'] ?? $o['p1']), (float) ($o['p3'] ?? $o['p1'])];
    if ($o['price_type'] === 'variabile' && $o['idx']) {
        $idx = (float) ($par['indices'][$o['idx']] ?? $par['indices'][$commodity === 'luce' ? 'PUN' : 'PSV'] ?? 0);
        $idx *= $o['idx_coeff'] !== null ? (float) $o['idx_coeff'] : 1.0;
        if ($commodity === 'luce') {
            $idx *= 1 + $par['lambda']; // le perdite di rete si pagano sull'indice, lo spread le comprende già
        }
        $p = array_map(fn($x) => $x + $idx, $p);
    }
    return $p;
}

function offer_cost(array $o, string $commodity, int $use, array $split, array $par): float {
    $p = unit_prices($o, $commodity, $par);
    $energy = $use * ($split[0] * $p[0] + $split[1] * $p[1] + $split[2] * $p[2]);
    $discounted = isset($o['discount_cap']) && $o['discount_cap'] !== null ? min($use, (int) $o['discount_cap']) : $use;
    return $energy + $use * (float) $o['unit_fee'] - $discounted * (float) $o['discount_unit']
        + (float) $o['fixed_year'] - (float) $o['discount_year'];
}

/** Costo di oggi della fornitura: dai prezzi del contratto se ci sono, altrimenti dalle bollette. */
function current_cost(array $s, int $use, array $split, array $bills, array $par): ?array {
    if ($s['unit_price'] !== null || ($s['price_type'] === 'variabile' && $s['spread'] !== null)) {
        $variable = $s['price_type'] === 'variabile' && $s['spread'] !== null;
        $o = [
            'p1' => $variable ? $s['spread'] : $s['unit_price'], 'p2' => null, 'p3' => null,
            'price_type' => $variable ? 'variabile' : 'fisso', 'idx' => $s['commodity'] === 'luce' ? 'PUN' : 'PSV', 'idx_coeff' => null,
            'unit_fee' => 0, 'discount_unit' => 0, 'fixed_year' => $s['fixed_year'] ?? 0, 'discount_year' => 0,
        ];
        return ['cost' => offer_cost($o, $s['commodity'], $use, $split, $par), 'fixed' => fixed_part($o), 'how' => 'prezzi', 'fixed_known' => $s['fixed_year'] !== null];
    }
    $cost = 0.0;
    $cons = 0.0;
    foreach ($bills as $b) {
        if ($b['energy_cost'] !== null && (float) $b['consumption'] > 0) {
            $cost += (float) $b['energy_cost'];
            $cons += (float) $b['consumption'];
        }
    }
    if ($cons > 0) {
        return ['cost' => $cost / $cons * $use, 'how' => 'bollette', 'fixed_known' => true];
    }
    return null;
}

function offer_matches(array $o, array $s, int $use): bool {
    if ($o['resident'] === '01' && !$s['resident'] || $o['resident'] === '02' && $s['resident']) {
        return false;
    }
    if ($o['cons_max'] !== null && (int) $o['cons_max'] > 0 && ($use > (int) $o['cons_max'] || $use < (int) ($o['cons_min'] ?? 0))) {
        return false;
    }
    if ($s['commodity'] === 'luce' && $s['power_kw'] && $o['pow_max'] !== null && (float) $o['pow_max'] > 0
        && ((float) $s['power_kw'] > (float) $o['pow_max'] || (float) $s['power_kw'] < (float) ($o['pow_min'] ?? 0))) {
        return false;
    }
    if ($o['zones'] === null) {
        return true;
    }
    if (empty($s['istat'])) {
        return false; // senza comune: solo le offerte valide in tutta Italia
    }
    $zones = explode(',', $o['zones']);
    $c = comune_by_code((string) $s['istat']);
    if (!$c) {
        return false;
    }
    // provincia: sia il codice attuale sia quello "storico" all'inizio del codice del comune (Sardegna)
    return in_array('C' . $c[1], $zones, true) || in_array('P' . $c[4], $zones, true)
        || in_array('P' . substr($c[1], 0, 3), $zones, true) || in_array('R' . $c[3], $zones, true);
}

/** Tutte le offerte adatte alla fornitura, dalla più conveniente. */
function compare_supply(array $s, array $bills, int $limit = 40): array {
    offers_schema();
    $use = (int) $s['annual_use'];
    $commodity = $s['commodity'];
    $par = offer_params();
    $split = $commodity === 'luce' ? band_split($bills) : [1.0, 0.0, 0.0];
    $st = db()->prepare('SELECT o.*, sl.name AS seller FROM bc_offers o LEFT JOIN bc_sellers sl ON sl.piva = o.piva WHERE o.commodity=?');
    $st->execute([$commodity]);
    $list = [];
    foreach ($st as $o) {
        if (!offer_matches($o, $s, $use)) {
            continue;
        }
        $cost = offer_cost($o, $commodity, $use, $split, $par);
        $p = unit_prices($o, $commodity, $par);
        $list[] = [
            'code' => $o['code'],
            'seller' => $o['seller'] ?: seller_from_site((string) $o['site']) ?: 'P.IVA ' . $o['piva'],
            'name' => $o['name'],
            'price_type' => $o['price_type'],
            'idx' => $o['idx'],
            'placet' => (bool) $o['placet'],
            'green' => (bool) $o['green'],
            'local' => $o['zones'] !== null,
            'unit_price' => round($split[0] * $p[0] + $split[1] * $p[1] + $split[2] * $p[2], 5),
            'spread' => $o['price_type'] === 'variabile' ? (float) $o['p1'] : null,
            'fixed_year' => (float) $o['fixed_year'],
            'discount_year' => (float) $o['discount_year'],
            'discount_unit' => (float) $o['discount_unit'],
            'discount_cap' => $o['discount_cap'] !== null ? (int) $o['discount_cap'] : null,
            'duration' => $o['duration'] !== null ? (int) $o['duration'] : null,
            'valid_to' => $o['valid_to'],
            'url' => $o['url'] ?: ($o['site'] ? 'https://' . preg_replace('#^https?://#', '', $o['site']) : null),
            'cost' => round($cost, 2),
            'cost_vat' => round(with_vat($commodity, $cost, $use, fixed_part($o)), 2),
        ];
    }
    usort($list, fn($a, $b) => $a['cost'] <=> $b['cost']);
    $cur = current_cost($s, $use, $split, $bills, $par);
    $curVat = $cur ? round(with_vat($commodity, $cur['cost'], $use, $cur['fixed'] ?? 0.0), 2) : null;
    foreach ($list as &$x) {
        $x['saving'] = $curVat !== null ? round($curVat - $x['cost_vat']) : null;
    }
    unset($x);
    $fixed = array_values(array_filter($list, fn($x) => $x['price_type'] === 'fisso'));
    $variable = array_values(array_filter($list, fn($x) => $x['price_type'] === 'variabile'));
    return [
        'commodity' => $commodity,
        'annual_use' => $use,
        'split' => array_map(fn($x) => round($x, 3), $split),
        'current' => $cur ? ['cost' => round($cur['cost'], 2), 'cost_vat' => $curVat, 'how' => $cur['how'], 'fixed_known' => $cur['fixed_known']] : null,
        'count' => count($list),
        'best_saving' => $list && $curVat !== null ? (int) round($curVat - $list[0]['cost_vat']) : null,
        'offers' => array_slice($list, 0, $limit),
        'best_fixed' => $fixed[0] ?? null,
        'best_variable' => $variable[0] ?? null,
        'median_vat' => $list ? $list[intdiv(count($list), 2)]['cost_vat'] : null,
        'only_national' => empty($s['istat']),
        'index' => [
            'month' => $par['indices']['month'] ?? null,
            'pun' => $par['indices']['PUN'] ?? null,
            'psv' => $par['indices']['PSV'] ?? null,
        ],
        'updated' => meta_get('imported_' . $commodity),
    ];
}

function seller_from_site(string $site): string {
    $host = preg_replace('#^(https?://)?(www\.)?#i', '', strtolower(trim($site)));
    $host = explode('/', $host)[0];
    if ($host === '') {
        return '';
    }
    $parts = explode('.', $host);
    $name = count($parts) > 1 ? $parts[count($parts) - 2] : $parts[0];
    return ucfirst(str_replace('-', ' ', $name));
}

/** Avvisi (una volta al giorno, dopo l'import del gas): a chi può risparmiare almeno la sua soglia. */
function send_alerts(): array {
    $today = date('Y-m-d');
    if (meta_get('imported_gas') !== $today || meta_get('alerts_day') === $today) {
        return ['skipped' => true];
    }
    meta_set('alerts_day', $today);
    $rows = db()->query('SELECT s.*, u.email, u.name AS uname, u.email_verified, u.notify_email, u.notify_telegram,
            u.telegram_chat_id, u.min_saving
        FROM bc_supplies s JOIN bc_users u ON u.id = s.user_id')->fetchAll();
    $sent = 0;
    foreach ($rows as $s) {
        $bills = (function (int $sid) {
            $st = db()->prepare('SELECT * FROM bc_bills WHERE supply_id=? ORDER BY period_from');
            $st->execute([$sid]);
            return $st->fetchAll();
        })((int) $s['id']);
        $pub = public_supply($s, $bills);
        if (!$pub['annual_use']) {
            continue;
        }
        $res = compare_supply($pub + ['istat' => $s['istat']], $bills, 1);
        $best = $res['offers'][0] ?? null;
        $saving = $res['best_saving'];
        db()->prepare('UPDATE bc_supplies SET last_best_saving=? WHERE id=?')->execute([$saving, (int) $s['id']]);
        if (!$best || $saving === null || $saving < (int) $s['min_saving'] || $best['code'] === $s['last_best_offer']) {
            continue;
        }
        if ($s['alerted_at'] && strtotime($s['alerted_at']) > time() - 7 * 86400) {
            continue; // al massimo un avviso a settimana per fornitura
        }
        $byMail = (int) $s['email_verified'] && (int) $s['notify_email'];
        $byTg = !empty($s['telegram_chat_id']) && (int) $s['notify_telegram'] && tg_enabled();
        if (!$byMail && !$byTg) {
            continue;
        }
        $link = app_url() . '/#/fornitura/' . (int) $s['id'];
        $what = $s['commodity'] === 'gas' ? 'il gas' : 'la luce';
        $line = 'Con <b>' . htmlspecialchars($best['name']) . '</b> di ' . htmlspecialchars($best['seller'])
            . " risparmieresti circa <b>{$saving} € l’anno</b> per $what (" . htmlspecialchars($s['name']) . ').';
        if ($byMail) {
            app_mail($s['email'], "Puoi risparmiare $saving € l’anno — Bollette Chiare",
                '<p>Ciao ' . htmlspecialchars($s['uname']) . ',</p><p>' . $line . '</p><p><a href="' . htmlspecialchars($link)
                . '">Guarda il confronto</a></p><p style="color:#666;font-size:13px">Dati: Portale Offerte ARERA. Stima su ' . (int) $pub['annual_use']
                . ($s['commodity'] === 'gas' ? ' Smc' : ' kWh') . ' l’anno, IVA compresa, solo la parte che dipende dal venditore.</p>');
        }
        if ($byTg) {
            tg_send((string) $s['telegram_chat_id'], "💡 $line\n" . htmlspecialchars($link));
        }
        db()->prepare('UPDATE bc_supplies SET last_best_offer=?, alerted_at=NOW() WHERE id=?')->execute([$best['code'], (int) $s['id']]);
        $sent++;
    }
    return ['sent' => $sent, 'supplies' => count($rows)];
}
