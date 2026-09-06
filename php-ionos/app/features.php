<?php
/**
 * Feature-Flags: global in config.php ('features' => ['queue' => true|false|['<firma-id>', ...]])
 * und je Firma in organizations.feature_flags (JSON-Liste, vom Plattformadministrator gesetzt).
 * Eine Funktion ist für eine Firma aktiv, wenn das globale Flag true ist, die Firma in der globalen
 * Liste steht oder die Firma das Flag selbst trägt. Ohne Firma zählt nur das globale Flag.
 */
declare(strict_types=1);

function feature_enabled(string $flag, ?string $tenantId = null): bool
{
    $features = (array)config('features', []);
    $global = $features[$flag] ?? false;
    if ($global === true) {
        return true;
    }
    if (is_array($global) && $tenantId !== null && in_array($tenantId, $global, true)) {
        return true;
    }
    if ($tenantId === null) {
        return false;
    }
    return in_array($flag, tenant_feature_flags($tenantId), true);
}

/** Je Firma gesetzte Flags (leer, solange Migration 018 fehlt). */
function tenant_feature_flags(string $tenantId): array
{
    static $cache = [];
    if (isset($cache[$tenantId])) {
        return $cache[$tenantId];
    }
    try {
        $st = db()->prepare('SELECT feature_flags FROM organizations WHERE id = ?');
        $st->execute([$tenantId]);
        $raw = $st->fetchColumn();
        $list = $raw ? json_decode((string)$raw, true) : [];
        $cache[$tenantId] = is_array($list) ? array_values(array_filter(array_map('strval', $list))) : [];
    } catch (Throwable $e) {
        $cache[$tenantId] = [];
    }
    return $cache[$tenantId];
}

function tenant_feature_set(string $tenantId, string $flag, bool $on, ?array $actor = null): void
{
    $list = tenant_feature_flags($tenantId);
    $list = array_values(array_diff($list, [$flag]));
    if ($on) {
        $list[] = $flag;
    }
    db()->prepare('UPDATE organizations SET feature_flags = ? WHERE id = ?')->execute([json_encode($list), $tenantId]);
    audit_log($tenantId, $actor, $on ? 'feature_enabled' : 'feature_disabled', 'organization', $tenantId, ['flag' => $flag]);
}
