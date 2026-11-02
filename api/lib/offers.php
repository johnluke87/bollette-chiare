<?php
declare(strict_types=1);

// Offerte di luce e gas dagli open data del Portale Offerte di ARERA / Acquirente Unico (uso libero):
//   https://www.ilportaleofferte.it/portaleOfferte/it/open-data.page
// Ogni notte escono i file del giorno: mercato libero in XML (luce ~21 MB, gas ~15 MB), PLACET in CSV,
// parametri regolati in CSV. Il cron ne importa uno per volta (luce, poi gas) per stare nei tempi del server.
// Nel DB restano solo le offerte per clienti domestici; i codici sono quelli delle specifiche SII v5.0.

const PO_BASE = 'https://www.ilportaleofferte.it/portaleOfferte/resources/opendata/csv/';
// storico mensile degli indici (PUN, PSV...): indirizzo fisso pubblicato nella pagina degli open data
const PO_INDEX_CSV = 'https://www.ilportaleofferte.it/portaleOfferte/resources/cms/documents/0c916785cbc1c33483b3dee7fc53c7a5.csv';

// IDX_PREZZO_ENERGIA → colonna dello storico (TTF non c'è: si usa il PSV, che ci si avvicina)
const PO_IDX = [
    '01' => 'PUN', '08' => 'PUN', '12' => 'PUN',
    '02' => 'PSV', '09' => 'PSV', '13' => 'PSV',
    '03' => 'PSV', '10' => 'PSV', '14' => 'PSV',
    '04' => 'Psbil', '11' => 'Psbil', '15' => 'Psbil',
    '05' => 'PE', '06' => 'CMEM', '07' => 'Pfor',
];

function offers_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS bc_offers (
        code VARCHAR(40) NOT NULL PRIMARY KEY,
        commodity CHAR(4) NOT NULL,
        placet TINYINT(1) NOT NULL DEFAULT 0,
        piva VARCHAR(16) NOT NULL,
        name VARCHAR(200) NOT NULL,
        price_type VARCHAR(10) NOT NULL,
        idx VARCHAR(8) NULL,
        idx_coeff DECIMAL(8,4) NULL,
        resident CHAR(2) NOT NULL DEFAULT '03',
        fixed_year DECIMAL(10,2) NOT NULL DEFAULT 0,
        unit_fee DECIMAL(10,6) NOT NULL DEFAULT 0,
        p1 DECIMAL(10,6) NULL,
        p2 DECIMAL(10,6) NULL,
        p3 DECIMAL(10,6) NULL,
        discount_year DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount_unit DECIMAL(10,6) NOT NULL DEFAULT 0,
        discount_cap INT NULL,
        green TINYINT(1) NOT NULL DEFAULT 0,
        cons_min INT NULL,
        cons_max INT NULL,
        pow_min DECIMAL(6,2) NULL,
        pow_max DECIMAL(6,2) NULL,
        zones TEXT NULL,
        duration INT NULL,
        valid_to DATE NULL,
        url VARCHAR(255) NULL,
        site VARCHAR(120) NULL,
        imported DATE NOT NULL,
        INDEX (commodity, price_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS bc_sellers (
        piva VARCHAR(16) NOT NULL PRIMARY KEY,
        name VARCHAR(160) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS bc_meta (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function meta_get(string $k): ?string {
    offers_schema();
    $st = db()->prepare('SELECT v FROM bc_meta WHERE k=?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function meta_set(string $k, string $v): void {
    offers_schema();
    db()->prepare('INSERT INTO bc_meta (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k, $v]);
}

function offers_status(): array {
    offers_schema();
    $counts = db()->query('SELECT commodity, COUNT(*) n FROM bc_offers GROUP BY commodity')->fetchAll(PDO::FETCH_KEY_PAIR);
    $idx = json_decode(meta_get('indices') ?? '{}', true) ?: [];
    return [
        'luce' => (int) ($counts['luce'] ?? 0),
        'gas' => (int) ($counts['gas'] ?? 0),
        'updated_luce' => meta_get('imported_luce'),
        'updated_gas' => meta_get('imported_gas'),
        'pun' => $idx['PUN'] ?? null,
        'psv' => $idx['PSV'] ?? null,
        'index_month' => $idx['month'] ?? null,
    ];
}

/** Indici dell'ultimo mese pubblicato e parametri regolati che servono al confronto. */
function offer_params(): array {
    $idx = json_decode(meta_get('indices') ?? '{}', true) ?: [];
    $par = json_decode(meta_get('params') ?? '{}', true) ?: [];
    return [
        'indices' => $idx,
        'lambda' => (float) ($par['lambda'] ?? 0.10), // perdite di rete sul PUN (10%)
    ];
}

/**
 * Un passo dell'aggiornamento (chiamato dal cron ogni ora): indici e venditori, poi luce, poi gas.
 * Un solo file grande per chiamata; il lavoro continua anche se cron-job.org chiude prima la connessione.
 */
function offers_refresh(bool $force = false): array {
    offers_schema();
    ignore_user_abort(true);
    @set_time_limit(600);
    @ini_set('memory_limit', '256M');
    $today = date('Ymd');
    $lock = fopen(cache_dir() . '/import.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['step' => 'già in corso'];
    }
    try {
        if ($force || meta_get('imported_extra') !== $today) {
            $res = import_extra($today);
            if ($res !== null) {
                meta_set('imported_extra', $today);
            }
            return ['step' => 'indici e PLACET', 'result' => $res];
        }
        foreach (['luce' => 'E', 'gas' => 'G'] as $commodity => $letter) {
            if ($force || meta_get("imported_$commodity") !== date('Y-m-d')) {
                $n = import_market($commodity, $letter, $today);
                if ($n !== null) {
                    meta_set("imported_$commodity", date('Y-m-d'));
                }
                return ['step' => $commodity, 'offers' => $n];
            }
        }
        return ['step' => 'niente da fare'];
    } finally {
        flock($lock, LOCK_UN);
    }
}

function cache_dir(): string {
    $dir = dirname(__DIR__) . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/** File del giorno nella cache (si cancellano quelli vecchi). Prima delle 6 i file di oggi possono mancare: si prova ieri. */
function po_file(string $folder, string $name, string $day): ?string {
    foreach ([$day, date('Ymd', strtotime($day . ' -1 day'))] as $d) {
        $file = sprintf($name, $d);
        $local = cache_dir() . '/' . $file;
        if (is_file($local) && filesize($local) > 0) {
            return $local;
        }
        $url = PO_BASE . $folder . '/' . substr($d, 0, 4) . '_' . substr($d, 4, 2) . '/' . $file;
        $body = http_get($url, 120);
        if ($body !== null && strlen($body) > 100) {
            foreach (glob(cache_dir() . '/' . str_replace('%s', '*', $name)) ?: [] as $old) {
                @unlink($old);
            }
            file_put_contents($local, $body);
            return $local;
        }
    }
    return null;
}

/** Indici mensili, parametri (perdite), venditori e offerte PLACET (CSV piccoli). */
function import_extra(string $day): ?array {
    $out = [];
    // storico indici: separatore ;, decimali con la virgola, Windows-1252
    $csv = http_get(PO_INDEX_CSV, 30);
    if ($csv !== null) {
        $rows = array_values(array_filter(array_map('trim', explode("\n", mb_convert_encoding($csv, 'UTF-8', 'Windows-1252')))));
        $head = array_map(fn($h) => trim(preg_replace('/\s*\(.*$/', '', $h)), str_getcsv(array_shift($rows), ';'));
        $idx = [];
        foreach ($rows as $r) {
            $row = array_combine($head, array_pad(str_getcsv($r, ';'), count($head), '')) ?: [];
            if (!preg_match('/^\d{6}$/', (string) ($row['AnnoMese'] ?? ''))) {
                continue;
            }
            foreach ($row as $k => $v) {
                if ($k !== 'AnnoMese' && $v !== '') {
                    $idx[$k] = (float) str_replace(',', '.', $v); // l'ultimo mese con un valore vince
                }
            }
            $idx['month'] = substr($row['AnnoMese'], 0, 4) . '-' . substr($row['AnnoMese'], 4, 2);
        }
        if (isset($idx['PUN'], $idx['PSV'])) {
            meta_set('indices', json_encode($idx));
            $out['indices'] = $idx['month'];
        }
    }
    if ($file = po_file('parametri', 'PO_Parametri_E_%s.csv', $day)) {
        $par = [];
        foreach (csv_rows($file) as $row) {
            if (strtolower((string) ($row['nome_parametro'] ?? '')) === 'lambda') {
                $par['lambda'] = (float) $row['valore'];
            }
        }
        if ($par) {
            meta_set('params', json_encode($par));
        }
    }
    $placet = 0;
    $sellers = 0;
    foreach (['luce' => 'E', 'gas' => 'G'] as $commodity => $letter) {
        $file = po_file('offerte', "PO_Offerte_{$letter}_PLACET_%s.csv", $day);
        if (!$file) {
            continue;
        }
        $ins = db()->prepare('INSERT INTO bc_sellers (piva, name) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)');
        $rows = [];
        foreach (csv_rows($file) as $r) {
            if (!empty($r['p_iva']) && !empty($r['denominazione'])) {
                $ins->execute([trim($r['p_iva']), seller_name($r['denominazione'])]);
                $sellers++;
            }
            if (strtolower(trim((string) ($r['tipo_cliente'] ?? ''))) !== 'domestico') {
                continue;
            }
            $variable = stripos((string) ($r['tipo_offerta'] ?? ''), 'variabile') !== false;
            // le colonne vuote o a zero non contano (alcuni venditori scrivono 0.000000 dove non c'è prezzo)
            $price = fn($k) => ($x = num($r[$k] ?? null)) !== null && $x > 0 ? $x : null;
            if ($commodity === 'luce') {
                // domestici: di solito biorari (F1 e F23); altrimenti tre fasce o monorario
                [$p1, $p2, $p3] = $price('p_vol_bf1') !== null ? [$price('p_vol_bf1'), $price('p_vol_bf23'), $price('p_vol_bf23')]
                    : ($price('p_vol_f1') !== null ? [$price('p_vol_f1'), $price('p_vol_f2'), $price('p_vol_f3')]
                        : array_fill(0, 3, $price('p_vol_mono')));
            } else {
                $p1 = $p2 = $p3 = $price('p_vol');
            }
            if ($variable) {
                $p1 = $p2 = $p3 = num($r['alpha'] ?? null) ?? 0.0;
            }
            if ($p1 === null) {
                continue;
            }
            $rows[] = [
                'code' => (string) $r['cod_offerta'], 'commodity' => $commodity, 'placet' => 1, 'piva' => trim((string) $r['p_iva']),
                'name' => (string) $r['nome_offerta'], 'price_type' => $variable ? 'variabile' : 'fisso',
                'idx' => $variable ? ($commodity === 'luce' ? 'PUN' : 'PSV') : null, 'idx_coeff' => null, 'resident' => '03',
                'fixed_year' => num($variable ? ($r['p_fix_v'] ?? null) : ($r['p_fix_f'] ?? null)) ?? 0,
                'unit_fee' => 0, 'p1' => $p1, 'p2' => $p2 ?? $p1, 'p3' => $p3 ?? $p1, 'discount_year' => 0, 'discount_unit' => 0, 'discount_cap' => null, 'green' => 0,
                'cons_min' => null, 'cons_max' => null, 'pow_min' => null, 'pow_max' => null,
                'zones' => zones_csv($r['regione'] ?? '', $r['provincia'] ?? '', $r['comune'] ?? ''),
                'duration' => 12, 'valid_to' => po_date((string) ($r['data_fine'] ?? '')),
                'url' => clean_url((string) ($r['url_offerta'] ?? '')),
                'site' => mb_substr((string) ($r['url_sito_venditore'] ?? ''), 0, 120) ?: null,
            ];
        }
        $placet += save_offers($rows, $commodity, true);
    }
    $out['placet'] = $placet;
    $out['sellers'] = $sellers;
    return $out;
}

function csv_rows(string $file): array {
    $fh = fopen($file, 'r');
    $head = fgetcsv($fh, 0, ',', '"', '\\');
    if (!$head) {
        return [];
    }
    $head = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $head);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (count($r) === count($head)) {
            $rows[] = array_combine($head, $r);
        }
    }
    fclose($fh);
    return $rows;
}

function seller_name(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    // "ENEL ENERGIA S.P.A." → "Enel Energia": più leggibile nelle liste
    $s = preg_replace('/[\s,]+(S\.?\s?P\.?\s?A\.?|S\.?\s?R\.?\s?L\.?|S\.?\s?C\.?\s?A?\.?\s?R?\.?\s?L?\.?|SOCIETA\'? .*|SB)\.?$/i', '', $s);
    if ($s === strtoupper($s)) {
        $s = ucwords(strtolower($s));
    }
    return mb_substr($s, 0, 160);
}

/** Link dell'offerta: con lo schema e in minuscolo se è scritto tutto in maiuscolo. */
function clean_url(string $u): ?string {
    $u = trim($u);
    if ($u === '' || str_contains($u, ' ')) {
        return null;
    }
    if ($u === strtoupper($u)) {
        $u = strtolower($u);
    }
    if (!preg_match('#^https?://#i', $u)) {
        $u = 'https://' . $u;
    }
    return mb_substr($u, 0, 255);
}

function po_date(string $s): ?string {
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $s, $m)) {
        return "$m[3]-$m[2]-$m[1]";
    }
    return null;
}

/** Zone come lista "R05,P026,C026086" (codici ISTAT); vuota = tutta Italia. */
function zones_csv(string ...$lists): ?string {
    $prefix = ['R', 'P', 'C'];
    $z = [];
    foreach ($lists as $i => $list) {
        foreach (preg_split('/[;|,\s]+/', trim($list)) ?: [] as $code) {
            if (preg_match('/^\d{1,6}$/', $code)) {
                $z[] = $prefix[$i] . str_pad($code, [2, 3, 6][$i], '0', STR_PAD_LEFT);
            }
        }
    }
    return $z ? implode(',', array_unique($z)) : null;
}

/** Offerte del mercato libero di un tipo (XML): solo clienti domestici. Ritorna quante, o null se il file non c'è. */
function import_market(string $commodity, string $letter, string $day): ?int {
    $file = po_file('offerteML', "PO_Offerte_{$letter}_MLIBERO_%s.xml", $day);
    if (!$file) {
        return null;
    }
    $reader = new XMLReader();
    if (!$reader->open($file)) {
        return null;
    }
    $rows = [];
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'offerta') {
            continue;
        }
        // senza namespace i nomi dei tag si leggono direttamente
        $xml = @simplexml_load_string(preg_replace('/\sxmlns="[^"]+"/', '',$reader->readOuterXml(), 1));
        if ($xml && ($row = parse_offer($xml, $commodity))) {
            $rows[] = $row;
        }
    }
    $reader->close();
    return save_offers($rows, $commodity, false);
}

function parse_offer(SimpleXMLElement $o, string $commodity): ?array {
    $d = $o->DettaglioOfferta;
    if ((string) $d->TIPO_CLIENTE !== '01' || (string) $d->OFFERTA_SINGOLA === 'NO') {
        return null;
    }
    $type = (string) $d->TIPO_OFFERTA; // 01 fisso, 02 variabile, 03 FLAT, 04 mista
    if ($type === '03') {
        return null; // FLAT: canone fisso che non dipende dai consumi, non confrontabile così
    }
    $idxCode = (string) ($o->RiferimentiPrezzoEnergia->IDX_PREZZO_ENERGIA ?? '');
    $variable = in_array($type, ['02', '04'], true) && isset(PO_IDX[$idxCode]);
    if (in_array($type, ['02', '04'], true) && !$variable) {
        return null; // indice "altro": il prezzo non si può stimare
    }
    $fixed = 0.0;
    $unitFee = 0.0;
    $bands = [];
    $green = 0;
    foreach ($o->ComponenteImpresa as $c) {
        if ((string) $c->TIPOLOGIA === '02') {
            continue; // componenti facoltative
        }
        $macro = (string) $c->MACROAREA;
        $first = [];
        foreach ($c->IntervalloPrezzi as $ip) {
            $band = (string) ($ip->FASCIA_COMPONENTE ?? '') ?: '01';
            if (isset($first[$band])) {
                continue; // scaglioni di consumo: si usa il primo
            }
            $first[$band] = ['p' => (float) $ip->PREZZO, 'u' => (string) $ip->UNITA_MISURA];
        }
        foreach ($first as $band => $x) {
            if ($macro === '01' && $x['u'] === '01') {
                $fixed += $x['p'];
            } elseif ($macro === '01' && $x['u'] === '02') {
                $fixed += $x['p'] * 3; // €/kW: potenza tipica di casa
            } elseif ($macro === '02' && in_array($x['u'], ['03', '04'], true)) {
                $unitFee += $x['p'];
            } elseif ($macro === '04' && in_array($x['u'], ['03', '04'], true)) {
                $bands[$band] = ($bands[$band] ?? 0) + $x['p'];
            } elseif ($macro === '06') {
                $green = 1;
                if (in_array($x['u'], ['03', '04'], true)) {
                    $unitFee += $x['p'];
                } elseif ($x['u'] === '01') {
                    $fixed += $x['p'];
                }
            }
        }
    }
    if (!$bands) {
        return null;
    }
    [$p1, $p2, $p3] = resolve_bands($bands, (string) ($o->TipoPrezzo->TIPOLOGIA_FASCE ?? '01'), $commodity);
    if (!$variable && min($p1, $p2, $p3) < 0.01) {
        return null; // prezzo fisso a zero: dato incompleto
    }
    $discYear = 0.0;
    $discUnit = 0.0;
    $discCap = null;
    foreach ($o->Sconto as $s) {
        if ((string) ($s->Condizione->CONDIZIONE_APPLICAZIONE ?? '') !== '00') {
            continue; // sconti con condizioni (domiciliazione, bolletta web...): fuori, come fa il Portale
        }
        foreach ($s->PrezziSconto as $ps) {
            $u = (string) $ps->UNITA_MISURA;
            if ($u === '01') {
                $discYear += (float) $ps->PREZZO;
            } elseif (in_array($u, ['03', '04'], true)) {
                $discUnit += (float) $ps->PREZZO;
                // sconto solo sui primi N kWh/Smc dell'anno
                if (($to = trim((string) ($ps->VALIDO_FINO ?? ''))) !== '' && (int) $to > 0) {
                    $discCap = $discCap === null ? (int) $to : min($discCap, (int) $to);
                }
            }
        }
    }
    $zones = [];
    foreach ($o->ZoneOfferta as $zo) {
        foreach (['REGIONE' => ['R', 2], 'PROVINCIA' => ['P', 3], 'COMUNE' => ['C', 6]] as $tag => [$pre, $len]) {
            foreach ($zo->{$tag} as $code) {
                $zones[] = $pre . str_pad(trim((string) $code), $len, '0', STR_PAD_LEFT);
            }
        }
    }
    $car = $o->CaratteristicheOfferta;
    $intOrNull = fn($v) => ($v = trim((string) $v)) !== '' ? (int) $v : null;
    $site = trim((string) $d->Contatti->URL_SITO_VENDITORE);
    $resident = (string) ($d->DOMESTICO_RESIDENTE ?? '') ?: '03';
    return [
        'code' => (string) $o->IdentificativiOfferta->COD_OFFERTA,
        'commodity' => $commodity,
        'placet' => 0,
        'piva' => (string) $o->IdentificativiOfferta->PIVA_UTENTE,
        'name' => mb_substr(trim((string) $d->NOME_OFFERTA), 0, 200),
        'price_type' => $variable ? 'variabile' : 'fisso',
        'idx' => $variable ? PO_IDX[$idxCode] : null,
        'idx_coeff' => $variable && (string) ($o->RiferimentiPrezzoEnergia->COEFFICIENTE ?? '') !== '' ? (float) $o->RiferimentiPrezzoEnergia->COEFFICIENTE : null,
        'resident' => $resident,
        'fixed_year' => round($fixed, 2),
        'unit_fee' => $unitFee,
        'p1' => $p1, 'p2' => $p2, 'p3' => $p3,
        'discount_year' => round($discYear, 2),
        'discount_unit' => $discUnit,
        'discount_cap' => $discCap,
        'green' => $green,
        'cons_min' => $intOrNull($car->CONSUMO_MIN ?? ''),
        'cons_max' => $intOrNull($car->CONSUMO_MAX ?? ''),
        'pow_min' => ($v = trim((string) ($car->POTENZA_MIN ?? ''))) !== '' ? (float) $v : null,
        'pow_max' => ($v = trim((string) ($car->POTENZA_MAX ?? ''))) !== '' ? (float) $v : null,
        'zones' => $zones ? implode(',', array_unique($zones)) : null,
        'duration' => ($dur = (int) $d->DURATA) > 0 ? $dur : null,
        'valid_to' => po_date((string) $o->ValiditaOfferta->DATA_FINE),
        'url' => clean_url((string) $d->Contatti->URL_OFFERTA),
        'site' => $site !== '' ? mb_substr(strtolower($site), 0, 120) : null,
    ];
}

/** Prezzi per fascia (F1, F2, F3) secondo la TIPOLOGIA_FASCE dell'offerta. */
function resolve_bands(array $b, string $kind, string $commodity): array {
    $avg = array_sum($b) / count($b);
    if ($commodity === 'gas' || $kind === '01' || count($b) === 1) {
        $p = $b['01'] ?? $avg;
        return [$p, $p, $p];
    }
    $f1 = $b['01'] ?? $avg;
    $f2 = $b['02'] ?? $avg;
    $f3 = $b['03'] ?? $f2;
    return match ($kind) {
        '02', '91' => [$f1, $f2, $f2],         // F1 / F2+F3
        '03' => [$f1, $f2, $f3],
        '92' => [$f2, $f1, $f2],               // F2 / F1+F3: la prima componente è F2
        '93' => [$f2, $f2, $f1],               // F3 / F1+F2: la prima componente è F3
        default => [$avg, $avg, $avg],         // fasce orarie particolari: media
    };
}

/** Salva le offerte di un tipo e toglie quelle sparite dal file di oggi. */
function save_offers(array $rows, string $commodity, bool $placet): int {
    if (!$rows) {
        return 0;
    }
    $cols = array_keys($rows[0]);
    $cols[] = 'imported';
    $today = date('Y-m-d');
    $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $upd = implode(',', array_map(fn($c) => "$c=VALUES($c)", array_diff($cols, ['code'])));
    db()->beginTransaction();
    foreach (array_chunk($rows, 200) as $chunk) {
        $vals = [];
        foreach ($chunk as $r) {
            array_push($vals, ...array_values($r));
            $vals[] = $today;
        }
        db()->prepare('INSERT INTO bc_offers (' . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $ph))
            . " ON DUPLICATE KEY UPDATE $upd")->execute($vals);
    }
    db()->prepare('DELETE FROM bc_offers WHERE commodity=? AND placet=? AND imported < ?')->execute([$commodity, (int) $placet, $today]);
    db()->commit();
    return count($rows);
}
