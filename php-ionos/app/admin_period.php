<?php
/**
 * Einheitliche Zeitraumsteuerung fuer Kennzahlen im Adminbereich (Version 4.43).
 *
 * Voreinstellungen (heute, gestern, 7, 30, 90 Tage, dieser und letzter Monat, Quartal, Jahr, 12 Monate) und ein freier
 * Bereich (von/bis, Kalendertage, lokale Zeit Europe/Berlin laut Konfiguration). Der Zeitraum ist [from, to) mit
 * ausschliesslichem Ende; der Vorzeitraum hat dieselbe Laenge und endet am Beginn des Zeitraums (Vergleichswerte).
 * Die Aufloesung der Diagramme folgt der Laenge: bis 31 Tage je Tag, bis 26 Wochen je Kalenderwoche, sonst je Monat.
 * Die letzte Wahl wird in der Sitzung gemerkt (nur Kennung und Grenzen, keine Daten). Alle Werte sind Anzeigefilter,
 * keine fachlichen Entscheidungen; Bestandszahlen (Firmen, Benutzer) bleiben zeitraumunabhaengig.
 */
if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

const ADMIN_PERIOD_PRESETS = [
    'heute'    => 'Heute',
    'gestern'  => 'Gestern',
    '7t'       => '7 Tage',
    '30t'      => '30 Tage',
    '90t'      => '90 Tage',
    'monat'    => 'Dieser Monat',
    'vormonat' => 'Letzter Monat',
    'quartal'  => 'Dieses Quartal',
    'jahr'     => 'Dieses Jahr',
    '12m'      => '12 Monate',
    'frei'     => 'Individuell',
];
const ADMIN_PERIOD_MAX_DAYS = 1096; // drei Jahre

/**
 * Zeitraum aus GET-Parametern (zeitraum, von, bis) bestimmen; ohne Angabe die gemerkte Wahl der Sitzung, sonst $default.
 * $now nur fuer Tests. Liefert from/to als DateTimeImmutable (lokal), prev_from/prev_to, days, resolution, label, query.
 */
function admin_period_from_request(array $get, string $default = '30t', ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $key = is_string($get['zeitraum'] ?? null) ? (string)$get['zeitraum'] : '';
    $von = is_string($get['von'] ?? null) ? trim((string)$get['von']) : '';
    $bis = is_string($get['bis'] ?? null) ? trim((string)$get['bis']) : '';
    if ($key === '' && $von !== '' && $bis !== '') {
        $key = 'frei';
    }
    $remembered = PHP_SAPI !== 'cli' && isset($_SESSION['admin_period']) && is_array($_SESSION['admin_period']) ? $_SESSION['admin_period'] : null;
    if ($key === '' && $remembered !== null) {
        $key = (string)($remembered['key'] ?? '');
        $von = (string)($remembered['von'] ?? '');
        $bis = (string)($remembered['bis'] ?? '');
    }
    if (!isset(ADMIN_PERIOD_PRESETS[$key])) {
        $key = isset(ADMIN_PERIOD_PRESETS[$default]) ? $default : '30t';
    }
    $today = $now->setTime(0, 0, 0);
    $tomorrow = $today->modify('+1 day');
    switch ($key) {
        case 'heute':    $from = $today; $to = $tomorrow; break;
        case 'gestern':  $from = $today->modify('-1 day'); $to = $today; break;
        case '7t':       $from = $tomorrow->modify('-7 days'); $to = $tomorrow; break;
        case '30t':      $from = $tomorrow->modify('-30 days'); $to = $tomorrow; break;
        case '90t':      $from = $tomorrow->modify('-90 days'); $to = $tomorrow; break;
        case 'monat':    $from = $today->modify('first day of this month'); $to = $from->modify('+1 month'); break;
        case 'vormonat': $from = $today->modify('first day of last month'); $to = $from->modify('+1 month'); break;
        case 'quartal':
            $q = intdiv((int)$today->format('n') - 1, 3);
            $from = $today->setDate((int)$today->format('Y'), $q * 3 + 1, 1);
            $to = $from->modify('+3 months');
            break;
        case 'jahr':     $from = $today->setDate((int)$today->format('Y'), 1, 1); $to = $from->modify('+1 year'); break;
        case '12m':      $from = $today->modify('first day of this month')->modify('-11 months'); $to = $today->modify('first day of this month')->modify('+1 month'); break;
        case 'frei':
        default:
            $f = admin_period_parse_date($von);
            $t = admin_period_parse_date($bis);
            if ($f === null || $t === null || $t < $f) {
                // ungueltig: auf 30 Tage zurueckfallen, Kennung bleibt sichtbar falsch -> zurueck auf 30t
                $key = '30t';
                $from = $tomorrow->modify('-30 days');
                $to = $tomorrow;
                break;
            }
            $from = $f;
            $to = $t->modify('+1 day'); // "bis" ist einschliesslich
            if ((int)$from->diff($to)->days > ADMIN_PERIOD_MAX_DAYS) {
                $from = $to->modify('-' . ADMIN_PERIOD_MAX_DAYS . ' days');
            }
            break;
    }
    $days = max(1, (int)$from->diff($to)->days);
    $resolution = $days <= 31 ? 'day' : ($days <= 26 * 7 ? 'week' : 'month');
    $period = [
        'key' => $key,
        'from' => $from, 'to' => $to,
        'prev_from' => $from->modify('-' . $days . ' days'), 'prev_to' => $from,
        'days' => $days,
        'resolution' => $resolution,
        'label' => $key === 'frei' ? $from->format('d.m.Y') . ' bis ' . $to->modify('-1 day')->format('d.m.Y') : ADMIN_PERIOD_PRESETS[$key],
        'von' => $from->format('Y-m-d'), 'bis' => $to->modify('-1 day')->format('Y-m-d'),
    ];
    $period['query'] = 'zeitraum=' . rawurlencode($key) . ($key === 'frei' ? '&von=' . $period['von'] . '&bis=' . $period['bis'] : '');
    if (PHP_SAPI !== 'cli' && (isset($get['zeitraum']) || ($von !== '' && $bis !== ''))) {
        $_SESSION['admin_period'] = ['key' => $key, 'von' => $period['von'], 'bis' => $period['bis']];
    }
    return $period;
}

/** Datum JJJJ-MM-TT (Eingabefeld) oder TT.MM.JJJJ in lokale Mitternacht; null bei Unsinn. */
function admin_period_parse_date(string $s): ?DateTimeImmutable
{
    $s = trim($s);
    foreach (['Y-m-d', 'd.m.Y'] as $fmt) {
        $d = DateTimeImmutable::createFromFormat('!' . $fmt, $s);
        if ($d instanceof DateTimeImmutable && $d->format($fmt) === $s) {
            return $d;
        }
    }
    return null;
}

/** SQL-Grenzen (lokale Zeit wie NOW() der Anwendung) fuer Spalten im Format DATETIME. */
function admin_period_sql_bounds(array $period, bool $previous = false): array
{
    return $previous
        ? [$period['prev_from']->format('Y-m-d H:i:s'), $period['prev_to']->format('Y-m-d H:i:s')]
        : [$period['from']->format('Y-m-d H:i:s'), $period['to']->format('Y-m-d H:i:s')];
}

/** SQL-Ausdruck, der eine DATETIME-Spalte auf den Schluessel des Zeitfachs abbildet (passend zu admin_period_slots). */
function admin_period_sql_bucket(string $column, string $resolution): string
{
    return match ($resolution) {
        'day'   => "DATE_FORMAT($column, '%Y-%m-%d')",
        'week'  => "DATE_FORMAT($column, '%x-W%v')",
        default => "DATE_FORMAT($column, '%Y-%m')",
    };
}

/** Zeitfaecher des Zeitraums: Schluessel => Beschriftung, in zeitlicher Reihenfolge, auch leere Faecher. */
function admin_period_slots(array $period): array
{
    $slots = [];
    $cur = $period['from'];
    $to = $period['to'];
    $monthNames = ['', 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    switch ($period['resolution']) {
        case 'day':
            while ($cur < $to) {
                $slots[$cur->format('Y-m-d')] = $cur->format('d.m.');
                $cur = $cur->modify('+1 day');
            }
            break;
        case 'week':
            $cur = $cur->modify('monday this week');
            while ($cur < $to) {
                $slots[$cur->format('o-\WW')] = 'KW ' . $cur->format('W');
                $cur = $cur->modify('+1 week');
            }
            break;
        default:
            $cur = $cur->modify('first day of this month');
            while ($cur < $to) {
                $slots[$cur->format('Y-m')] = $monthNames[(int)$cur->format('n')] . ' ' . $cur->format('y');
                $cur = $cur->modify('+1 month');
            }
    }
    return $slots;
}

/** Bezeichnung der Aufloesung fuer Diagrammtitel. */
function admin_period_resolution_label(array $period): string
{
    return ['day' => 'je Tag', 'week' => 'je Kalenderwoche', 'month' => 'je Monat'][$period['resolution']] ?? '';
}

/** Vergleich zum Vorzeitraum als Text (+12,5 % / neu / unverändert). */
function admin_period_compare(float $current, float $previous): string
{
    if ($previous <= 0 && $current <= 0) {
        return 'unverändert';
    }
    if ($previous <= 0) {
        return 'neu (Vorzeitraum 0)';
    }
    $pct = ($current - $previous) / $previous * 100;
    $txt = ($pct > 0 ? '+' : '') . number_format($pct, 1, ',', '.') . ' %';
    return $txt . ' zum Vorzeitraum';
}

/**
 * Auswahlleiste: Voreinstellungen als Links, freier Bereich als Formular (GET). $base ist die Zieladresse ohne
 * Zeitraumparameter, $extra weitere GET-Parameter, die erhalten bleiben (z. B. tab).
 */
function admin_period_selector(string $base, array $period, array $extra = []): string
{
    $sep = str_contains($base, '?') ? '&' : '?';
    $extraQ = $extra ? http_build_query($extra) . '&' : '';
    $html = '<div class="period-bar" aria-label="Zeitraum"><span class="period-label">Zeitraum:</span>';
    foreach (ADMIN_PERIOD_PRESETS as $k => $label) {
        if ($k === 'frei') {
            continue;
        }
        $html .= '<a href="' . e($base . $sep . $extraQ . 'zeitraum=' . $k) . '"' . ($k === $period['key'] ? ' class="active"' : '') . '>' . e($label) . '</a>';
    }
    $html .= '<form method="get" action="' . e(strtok($base, '?')) . '" class="period-form">';
    parse_str((string)(parse_url($base, PHP_URL_QUERY) ?? ''), $baseParams);
    foreach (array_merge($baseParams, $extra) as $k => $v) {
        $html .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    }
    $html .= '<input type="hidden" name="zeitraum" value="frei">'
        . '<label>von <input type="date" name="von" value="' . e($period['von']) . '" required></label>'
        . '<label>bis <input type="date" name="bis" value="' . e($period['bis']) . '" required></label>'
        . '<button type="submit" class="btn btn-sm btn-secondary">Anwenden</button></form>'
        . '<span class="hint period-current">' . e($period['label']) . ' (' . e($period['from']->format('d.m.Y')) . ' bis ' . e($period['to']->modify('-1 day')->format('d.m.Y')) . ', ' . (int)$period['days'] . ' Tage; Vergleich: ' . e($period['prev_from']->format('d.m.Y')) . ' bis ' . e($period['prev_to']->modify('-1 day')->format('d.m.Y')) . ')</span>'
        . '</div>';
    return $html;
}
