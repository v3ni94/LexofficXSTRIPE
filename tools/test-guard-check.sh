#!/usr/bin/env bash
# Prueft den zentralen Test-Schutz (tools/lib/test-guard.php) mit SYNTHETISCHEN Konfigurationen: Jede Variante,
# die auf Produktion oder echte Konten hindeutet, muss mit Exit 64 und "TEST-GUARD:" abbrechen, BEVOR ein
# Datenbank- oder Netzwerkzugriff stattfindet (die Datenbankangaben zeigen absichtlich auf nicht existierende
# Ports; ein Verbindungsversuch waere als Fehler sichtbar). Es werden keine echten Zugangsdaten verwendet.
# Aufruf: bash tools/test-guard-check.sh   (Exit 0 = gruen)
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

# Basis: eine gueltige Pruefstandkonfiguration; jede Variante aendert genau ein Merkmal.
mkcfg() { # $1 Datei, $2 zusaetzliche Zeilen (ueberschreiben Vorgaben, da spaeter im Array)
cat > "$1" <<PHP
<?php
declare(strict_types=1);
if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }
return array_replace_recursive([
    'timezone' => 'Europe/Berlin', 'environment' => 'prod',
    'app_secret' => str_repeat('a', 64), 'cron_token' => str_repeat('b', 32),
    'db' => ['host' => '127.0.0.1', 'port' => 23999, 'name' => 'se_test', 'user' => 'se_test', 'pass' => 'x', 'charset' => 'utf8mb4'],
    'redis' => null, 'storage_dir' => '$T/storage',
    'stripe_api_base_url' => 'http://127.0.0.1:28999',
], [ $2 ]);
PHP
}
laden() { # $1 Konfigurationsdatei -> Ausgabe stderr+Exitcode
    SMARTEINZUG_CONFIG="$1" php -r 'define("LOG_SERVICE","cli"); require $argv[1] . "/php-ionos/bin/_cli.php"; require $argv[1] . "/tools/lib/test-guard.php"; echo "DURCH\n";' "$ROOT" 2>&1
    return $?
}
erwarte_abbruch() { # $1 Name, $2 Zusatzzeilen, $3 erwarteter Textteil
    mkcfg "$T/c.php" "$2"
    OUT="$(laden "$T/c.php")"; RC=$?
    if [[ $RC -eq 64 && "$OUT" == *"TEST-GUARD:"* && "$OUT" == *"$3"* && "$OUT" != *DURCH* ]]; then ok "$1 wird abgewiesen"; else bad "$1: rc=$RC, Ausgabe: $(head -c 300 <<<"$OUT")"; fi
}

echo "1) Gueltiger Pruefstand passiert"
mkcfg "$T/ok.php" ""
OUT="$(laden "$T/ok.php")"; RC=$?
[[ $RC -eq 0 && "$OUT" == *DURCH* ]] && ok "Sandbox-Konfiguration passiert den Schutz" || bad "Sandbox-Konfiguration abgewiesen: $OUT"

echo "2) Synthetische Produktionsmerkmale werden abgewiesen"
erwarte_abbruch "Datenbank auf fremdem Host" "'db' => ['host' => 'db.example.invalid']" "nicht lokal"
erwarte_abbruch "Datenbank auf Standardport 3306" "'db' => ['port' => 3306]" "3306"
erwarte_abbruch "Datenbankname ohne test" "'db' => ['name' => 'smarteinzug']" 'kein "test"'
erwarte_abbruch "Mailversand aktiv" "'mail' => ['enabled' => true, 'transport' => 'smtp']" "mail.enabled"
erwarte_abbruch "Totmannschalter gesetzt" "'monitoring' => ['heartbeat_url' => 'https://hc.example.invalid/ping']" "heartbeat_url"
erwarte_abbruch "Plattform-Abrechnung aktiv" "'billing' => ['enabled' => true]" "billing.enabled"
erwarte_abbruch "Live-Schluessel in der Konfiguration" "'billing' => ['stripe_secret_key' => 'sk_' . 'live_TESTGUARD']" "Live-Schluessel"
erwarte_abbruch "eingeschraenkter Live-Schluessel" "'billing' => ['stripe_secret_key' => 'rk_' . 'live_TESTGUARD']" "Live-Schluessel"
erwarte_abbruch "Stripe ohne Stub-Adresse" "'stripe_api_base_url' => ''" "stripe_api_base_url fehlt"
erwarte_abbruch "Stripe-Adresse extern" "'stripe_api_base_url' => 'https://api.stripe.com/v1'" "nicht auf 127.0.0.1"
erwarte_abbruch "Lexware-Adresse extern" "'lexware_api_base_url' => 'https://api.lexware.io/v1'" "lexware_api_base_url"
erwarte_abbruch "sevdesk-Adresse extern" "'sevdesk' => ['base_url' => 'https://my.sevdesk.de/api/v1']" "sevdesk.base_url"
erwarte_abbruch "Redis auf fremdem Host" "'redis' => ['host' => 'smarteinzug-redis', 'port' => 6379]" "redis.host"

echo "3) Pfadregeln"
mkdir -p "$T/opt/smarteinzug/shared"; mkcfg "$T/opt/smarteinzug/shared/config.php" ""
# realpath des Tempordners beginnt nicht mit /opt/smarteinzug; die Regel prueft den aufgeloesten Pfad, deshalb hier
# nur der Nachweis, dass eine Datei unter php-ionos/app/config.php abgewiesen wird (ohne die echte Datei anzufassen).
OUT="$(SMARTEINZUG_CONFIG="" php -r 'define("LOG_SERVICE","cli"); putenv("SMARTEINZUG_CONFIG"); $GLOBALS["config"]=["db"=>["host"=>"127.0.0.1","port"=>23999,"name"=>"se_test"]]; function config($k,$d=null){return $GLOBALS["config"][$k]??$d;} require $argv[1] . "/tools/lib/test-guard.php";' "$ROOT" 2>&1)"; RC=$?
[[ $RC -eq 64 && "$OUT" == *"SMARTEINZUG_CONFIG fehlt"* ]] && ok "ohne SMARTEINZUG_CONFIG kein Lauf" || bad "ohne SMARTEINZUG_CONFIG: rc=$RC $OUT"
OUT="$(php -r '$GLOBALS["config"]=["db"=>["host"=>"127.0.0.1","port"=>23999,"name"=>"se_test"]]; function config($k,$d=null){return $GLOBALS["config"][$k]??$d;} require_once $argv[1] . "/tools/lib/test-guard.php"; ' "$ROOT" 2>&1 <<<"" )"; RC=$?
[[ $RC -eq 64 ]] && ok "Guard ohne Konfigurationsdatei bricht ab" || bad "Guard ohne Datei: rc=$RC"
mkdir -p "$T/repo/php-ionos/app"; mkcfg "$T/repo/php-ionos/app/config.php" ""
OUT="$(SMARTEINZUG_CONFIG="$T/repo/php-ionos/app/config.php" php -r 'define("LOG_SERVICE","cli"); require $argv[1] . "/php-ionos/bin/_cli.php"; require $argv[1] . "/tools/lib/test-guard.php";' "$ROOT" 2>&1)"; RC=$?
[[ $RC -eq 64 && "$OUT" == *"Entwicklerkonfiguration"* ]] && ok "php-ionos/app/config.php wird abgewiesen" || bad "app/config.php: rc=$RC $OUT"

echo "4) Datenbankstufe"
OUT="$(SMARTEINZUG_CONFIG="$T/ok.php" php -r '
define("LOG_SERVICE","cli"); require $argv[1] . "/php-ionos/bin/_cli.php"; require $argv[1] . "/tools/lib/test-guard.php";
require_once $argv[1] . "/php-ionos/app/crypto.php";
$pdo = new PDO("sqlite::memory:"); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE integrations (tenant_id TEXT, stripe_secret_key_encrypted TEXT)");
$pdo->prepare("INSERT INTO integrations VALUES (?, ?)")->execute(["t1", encrypt_value("sk_test_stub")]);
test_guard_assert_db($pdo); echo "TESTKEY-OK\n";
$pdo->prepare("INSERT INTO integrations VALUES (?, ?)")->execute(["t2", encrypt_value("sk_" . "live_TESTGUARD")]);
test_guard_assert_db($pdo); echo "DURCH\n";' "$ROOT" 2>&1)"; RC=$?
[[ "$OUT" == *TESTKEY-OK* && $RC -eq 64 && "$OUT" == *"Live-Schluessel (Firma t2)"* && "$OUT" != *DURCH* ]] && ok "gespeicherter Live-Schluessel einer Firma bricht ab, Testschluessel passiert" || bad "Datenbankstufe: rc=$RC $OUT"

echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ $FAIL -eq 0 ]]
