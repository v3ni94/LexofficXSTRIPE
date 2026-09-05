<?php
/**
 * Kleine Diagramme für den Adminbereich als HTML/CSS (Säulen und Balken),
 * serverseitig erzeugt: keine externe Bibliothek, kein JavaScript, keine
 * Datenübertragung an Dritte. Texte bleiben in normaler Schriftgröße lesbar,
 * die Balken passen sich der Breite an (Handy, Tablet, Desktop).
 * Gestaltung in assets/css/style.css (Abschnitt "Diagramme").
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
    $max = 0.0;
    foreach ($rows as $r) { $max = max($max, (float)$r['value']); }
    $html = '<figure class="chart chart-columns" aria-label="' . e($title) . '">'
          . '<figcaption class="chart-title">' . e($title) . '</figcaption>';
    if (!$rows || $max <= 0) {
        return $html . '<p class="chart-empty">Noch keine Daten</p></figure>';
    }
    $html .= '<div class="chart-scroll"><div class="chart-cols" style="--n:' . count($rows) . '">';
    foreach ($rows as $r) {
        $v = (float)$r['value'];
        $pct = $v > 0 ? max(1.5, round($v / $max * 100, 1)) : 0;
        $html .= '<div class="chart-col">'
               . '<div class="chart-plot">'
               . ($v > 0 ? '<span class="chart-value">' . e($format($v)) . '</span>' : '<span class="chart-value chart-value-zero">0</span>')
               . '<span class="chart-bar" style="height:' . $pct . '%;background:' . e($color) . '"></span>'
               . '</div>'
               . '<span class="chart-label">' . e((string)$r['label']) . '</span>'
               . '</div>';
    }
    return $html . '</div></div></figure>';
}

/** Balkendiagramm (horizontal), z.B. Funnel oder Verteilung je Herkunft. */
function chart_hbars(array $rows, string $title, ?callable $format = null, string $color = '#2E2D2E'): string
{
    $format = $format ?? static fn(float $v): string => (string)(int)round($v);
    $max = 0.0;
    foreach ($rows as $r) { $max = max($max, (float)$r['value']); }
    $html = '<figure class="chart chart-rows" aria-label="' . e($title) . '">'
          . '<figcaption class="chart-title">' . e($title) . '</figcaption>';
    if (!$rows || $max <= 0) {
        return $html . '<p class="chart-empty">Noch keine Daten</p></figure>';
    }
    $html .= '<div class="chart-rowlist">';
    foreach ($rows as $r) {
        $v = (float)$r['value'];
        $pct = $v > 0 ? max(0.8, round($v / $max * 100, 1)) : 0;
        $html .= '<div class="chart-row">'
               . '<span class="chart-label">' . e((string)$r['label']) . '</span>'
               . '<span class="chart-track"><span class="chart-hbar" style="width:' . $pct . '%;background:' . e($color) . '"></span></span>'
               . '<span class="chart-value">' . e($format($v)) . '</span>'
               . '</div>';
    }
    return $html . '</div></figure>';
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
