<?php
/**
 * Kleine SVG-Diagramme für den Adminbereich, serverseitig erzeugt (keine
 * externe Bibliothek, kein JavaScript, keine Datenübertragung an Dritte).
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }

/**
 * Säulendiagramm. $rows: Liste aus ['label' => string, 'value' => float].
 * $format: Callback für die Wertbeschriftung.
 */
function chart_bars(array $rows, string $title, ?callable $format = null, string $color = '#E3AC48'): string
{
    $format = $format ?? static fn(float $v): string => (string)(int)round($v);
    $n = count($rows);
    $w = 640; $h = 220; $padL = 16; $padR = 16; $padT = 34; $padB = 40;
    $max = 0.0;
    foreach ($rows as $r) { $max = max($max, (float)$r['value']); }
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title) . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<text x="' . $padL . '" y="20" class="chart-title">' . e($title) . '</text>';
    $svg .= '<line x1="' . $padL . '" y1="' . ($h - $padB) . '" x2="' . ($w - $padR) . '" y2="' . ($h - $padB) . '" class="chart-axis"/>';
    if ($n === 0 || $max <= 0) {
        $svg .= '<text x="' . ($w / 2) . '" y="' . ($h / 2) . '" text-anchor="middle" class="chart-empty">Noch keine Daten</text></svg>';
        return $svg;
    }
    $slot = ($w - $padL - $padR) / $n;
    $barW = max(6, min(48, $slot * 0.6));
    $plotH = $h - $padT - $padB;
    foreach (array_values($rows) as $i => $r) {
        $v = (float)$r['value'];
        $bh = $v > 0 ? max(2, $plotH * $v / $max) : 0;
        $x = $padL + $slot * $i + ($slot - $barW) / 2;
        $y = $h - $padB - $bh;
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round($bh, 1) . '" rx="3" fill="' . $color . '"/>';
        if ($v > 0) {
            $svg .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . round($y - 5, 1) . '" text-anchor="middle" class="chart-value">' . e($format($v)) . '</text>';
        }
        $svg .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . ($h - $padB + 16) . '" text-anchor="middle" class="chart-label">' . e((string)$r['label']) . '</text>';
    }
    return $svg . '</svg>';
}

/** Balkendiagramm (horizontal), z.B. Funnel oder Verteilung je Herkunft. */
function chart_hbars(array $rows, string $title, ?callable $format = null, string $color = '#2E2D2E'): string
{
    $format = $format ?? static fn(float $v): string => (string)(int)round($v);
    $n = count($rows);
    $rowH = 26; $w = 640; $padT = 34; $labelW = 230; $padR = 70;
    $h = $padT + max(1, $n) * $rowH + 12;
    $max = 0.0;
    foreach ($rows as $r) { $max = max($max, (float)$r['value']); }
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title) . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<text x="16" y="20" class="chart-title">' . e($title) . '</text>';
    if ($n === 0 || $max <= 0) {
        $svg .= '<text x="' . ($w / 2) . '" y="' . ($padT + 16) . '" text-anchor="middle" class="chart-empty">Noch keine Daten</text></svg>';
        return $svg;
    }
    $plotW = $w - $labelW - $padR;
    foreach (array_values($rows) as $i => $r) {
        $v = (float)$r['value'];
        $y = $padT + $i * $rowH;
        $bw = $v > 0 ? max(2, $plotW * $v / $max) : 0;
        $svg .= '<text x="' . ($labelW - 10) . '" y="' . ($y + 17) . '" text-anchor="end" class="chart-label">' . e((string)$r['label']) . '</text>';
        $svg .= '<rect x="' . $labelW . '" y="' . ($y + 5) . '" width="' . round($bw, 1) . '" height="16" rx="3" fill="' . $color . '"/>';
        $svg .= '<text x="' . round($labelW + $bw + 6, 1) . '" y="' . ($y + 17) . '" class="chart-value">' . e($format($v)) . '</text>';
    }
    return $svg . '</svg>';
}

/** Kalenderwochen der letzten $weeks Wochen als [yearweek => 'KW nn'] (ISO, aufsteigend). */
function chart_week_slots(int $weeks): array
{
    $slots = [];
    $monday = new DateTimeImmutable('monday this week');
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $d = $monday->modify('-' . $i . ' week');
        $slots[$d->format('oW')] = 'KW ' . $d->format('W');
    }
    return $slots;
}
