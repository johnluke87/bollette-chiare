<?php
declare(strict_types=1);

// Comuni italiani con codici ISTAT e CAP (da github.com/matteocontrini/comuni-json, licenza MIT):
// servono a capire in quale zona vale un'offerta (ZoneOfferta: regione, provincia, comune).
// comuni.json: [nome, codice ISTAT, sigla provincia, codice regione, codice provincia, "CAP CAP ..."]

function comuni_all(): array {
    static $all;
    return $all ??= json_decode((string) file_get_contents(__DIR__ . '/comuni.json'), true) ?: [];
}

function comune_by_code(string $istat): ?array {
    static $byCode;
    if ($byCode === null) {
        $byCode = [];
        foreach (comuni_all() as $c) {
            $byCode[$c[1]] = $c;
        }
    }
    return $byCode[$istat] ?? null;
}

function comune_region(string $istat): string {
    return comune_by_code($istat)[3] ?? '';
}

function public_comune(array $c): array {
    return ['comune' => $c[0], 'istat' => $c[1], 'sigla' => $c[2], 'label' => "$c[0] ($c[2])"];
}

function comuni_search(string $q): array {
    $q = mb_strtolower(trim($q));
    if (preg_match('/^\d{5}$/', $q)) {
        return array_map('public_comune', comuni_by_cap($q));
    }
    if (mb_strlen($q) < 2) {
        return [];
    }
    $starts = [];
    $contains = [];
    foreach (comuni_all() as $c) {
        $n = mb_strtolower($c[0]);
        if (str_starts_with($n, $q)) {
            $starts[] = $c;
        } elseif (str_contains($n, $q)) {
            $contains[] = $c;
        }
    }
    return array_map('public_comune', array_slice([...$starts, ...$contains], 0, 8));
}

function comuni_by_cap(string $cap): array {
    return array_values(array_filter(comuni_all(), fn($c) => in_array($cap, explode(' ', $c[5]), true)));
}

/** Il comune di una bolletta: dal nome (se c'è) tra quelli del CAP, altrimenti il primo del CAP. */
function comune_guess(?string $cap, ?string $name): ?array {
    $list = $cap ? comuni_by_cap($cap) : [];
    $name = mb_strtolower(trim((string) $name));
    foreach ($list ?: ($name !== '' ? comuni_all() : []) as $c) {
        if ($name !== '' && mb_strtolower($c[0]) === $name) {
            return public_comune($c);
        }
    }
    return $list ? public_comune($list[0]) : null;
}
