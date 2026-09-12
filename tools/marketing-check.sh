#!/usr/bin/env bash
# Regressionstest des Marketingmoduls (app/marketing.php, admin-marketing.php, abmelden.php, marketing-webhook.php, Jobtyp
# marketing_send) gegen eine temporaere MariaDB: Rechte, Ratenbegrenzung (je Sekunde, je 24 Stunden), CSV-Import mit
# Rechtsgrundlage, Systemliste aus Firmenaccounts (nie Endkunden), Sperrliste (dauerhaft, Abmeldung nicht aufhebbar),
# Kampagne (Pruefungen, Vorschau, Testversand ueber Profil marketing mit Transport log, Freigabe nur nach Test), Versand
# mit Dublettenschutz und Sperrliste, Abmeldung per Token und One-Click, SES-Ereignisse und SNS-Signaturpruefung, Audit
# ohne Klartextadressen; statische Pruefungen (Rechte, 2FA fuer die Freigabe, Kopfzeilen). Der Test-Schutz laeuft mit.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s' "$1" | sed -n "s/^$2=//p" | tail -n1; }
erw() { [[ "$(feld "$OUT" "$2")" == "$3" ]] && ok "$1" || bad "$1 ($2=$(feld "$OUT" "$2"), erwartet $3)"; }
enth() { [[ "$(feld "$OUT" "$2")" == *"$3"* ]] && ok "$1" || bad "$1 ($2=$(feld "$OUT" "$2"), erwartet Teil $3)"; }

echo "1) Statische Pruefungen"
for f in app/marketing.php admin-marketing.php abmelden.php marketing-webhook.php app/mailer.php app/jobs.php; do
    php -l "$ROOT/php-ionos/$f" >/dev/null 2>&1 && ok "php -l $f" || bad "php -l $f"
done
grep -q "require_platform('marketing.view')" "$ROOT/php-ionos/admin-marketing.php" && grep -q "platform_can(\$ctx, 'marketing.manage')" "$ROOT/php-ionos/admin-marketing.php" && ok "Seite: Eintritt marketing.view, Aktionen marketing.manage" || bad "Rechte der Seite"
grep -q "'marketing.view'" "$ROOT/php-ionos/app/platform.php" && grep -q "'marketing.manage'" "$ROOT/php-ionos/app/platform.php" && ok "Rechte im Katalog" || bad "Rechte fehlen im Katalog"
! grep -q "'marketing\." <<<"$(sed -n '/PLATFORM_SYSTEM_ROLES = \[/,/^\];/p' "$ROOT/php-ionos/app/platform.php")" && ok "keine Systemrolle ausser admin erhaelt Marketingrechte" || bad "Marketingrechte in Systemrolle"
grep -q "require_recent_totp(\$ctx, (string)(\$_POST\['code'\] ?? ''));" "$ROOT/php-ionos/admin-marketing.php" && ok "Massenversand verlangt 2FA-Code" || bad "campaign_start ohne 2FA"
grep -q "marketing_require(\$ctx)" "$ROOT/php-ionos/app/marketing.php" && ok "Rechtepruefung in den Funktionen" || bad "Funktionen ohne Rechtepruefung"
grep -q "'Precedence: bulk'" "$ROOT/php-ionos/app/mailer.php" && grep -q "mail_profile_config" "$ROOT/php-ionos/app/mailer.php" && ok "Mailer: Profil marketing mit Precedence bulk" || bad "Mailer ohne Profil"
grep -q "FROM organization_members m JOIN users u" "$ROOT/php-ionos/app/marketing.php" && ! grep -q "FROM customers" "$ROOT/php-ionos/app/marketing.php" && ok "Systemliste liest nur users/organization_members, nie customers" || bad "Systemliste greift auf customers zu"
grep -q "marketing_send" "$ROOT/php-ionos/app/jobs.php" && grep -q "'marketing_send'" "$ROOT/php-ionos/app/queue.php" && ok "Jobtyp marketing_send registriert (Pool mail, Vorgaben, Bezeichnung)" || bad "Jobtyp fehlt"
grep -q "marketing-webhook.php" "$ROOT/php-ionos/app/bootstrap.php" && ok "Webhook in der Hostliste" || bad "Webhook fehlt in enforce_host_rules"
grep -q "hash_equals(\$expected, \$given)" "$ROOT/php-ionos/marketing-webhook.php" && grep -q "marketing_sns_verify(\$msg)" "$ROOT/php-ionos/marketing-webhook.php" && ok "Webhook: Token UND Signatur" || bad "Webhook ohne doppelte Pruefung"
grep -q "sns\\\\.\[a-z0-9-\]+\\\\.amazonaws\\\\.com" "$ROOT/php-ionos/app/marketing.php" && ok "SNS-Zertifikat nur von amazonaws.com" || bad "Zertifikatsquelle nicht begrenzt"
grep -q "034_marketing" <<<"$(ls "$ROOT/php-ionos/sql/migrations")" && grep -q "CREATE TABLE IF NOT EXISTS marketing_suppressions" "$ROOT/php-ionos/sql/schema.sql" && ok "Migration 034 und schema.sql" || bad "Migration 034"
grep -q "mail_marketing" "$ROOT/php-ionos/app/config.example.php" && ! grep -Eq "AKIA[A-Z0-9]{12,}" "$ROOT/php-ionos/app/config.example.php" && ok "Konfigurationsvorlage ohne echte Zugangsdaten" || bad "config.example.php"
! grep -rEq "AKIA[A-Z0-9]{12,}" "$ROOT/php-ionos" "$ROOT/docs" "$ROOT/tools" && ok "keine AWS-Zugangskennung im Repository" || bad "AWS-Zugangskennung im Repository"
! grep -q "—" "$ROOT/php-ionos/app/marketing.php" "$ROOT/php-ionos/admin-marketing.php" "$ROOT/php-ionos/abmelden.php" && ok "keine Gedankenstriche" || bad "Gedankenstrich"

echo "2) Prueffaelle gegen temporaere MariaDB"
source "$ROOT/tools/lib/mariadb-sandbox.sh"
T="$(mktemp -d)"; cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }; trap cleanup EXIT INT TERM
if mariadb_sandbox_available; then
    mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true], 'base_url' => 'https://app.example.test', 'stripe_api_base_url' => 'http://127.0.0.1:1/v1', 'lexware_api_base_url' => 'http://127.0.0.1:1/v1',
    'mail' => ['enabled' => false], 'mail_marketing' => ['enabled' => true, 'transport' => 'log', 'log_file' => '$T/marketing.log', 'from_address' => 'kontakt@mail.example.test', 'from_name' => 'Test Marketing', 'reply_to' => 'kontakt@example.test', 'webhook_token' => str_repeat('t', 40)]," || exit 1
    OUT="$(php "$ROOT/tools/lib/marketing-sim.php" "$ROOT" "$T/marketing.log" 2>&1)"
    if ! grep -q "^admin_manage=" <<<"$OUT"; then bad "Simulation lief nicht: $(tail -n 12 <<<"$OUT")"; else
    grep -q "Fatal\|Warning\|Notice\|Deprecated" <<<"$OUT" && bad "PHP-Meldung in der Simulation: $(grep -m1 "Fatal\|Warning\|Notice\|Deprecated" <<<"$OUT")"
    erw "Administrator hat marketing.manage" admin_manage 1
    erw "Mitarbeiter ohne marketing.view" staff_kein_view 1
    erw "Support ohne marketing.manage" support_kein_manage 1
    erw "Vorgabe 1 je Sekunde, 200 je 24 h" rate_default "1/200"
    erw "Rate 0 verweigert" rate_ungueltig verweigert
    erw "Rate durch Mitarbeiter verweigert" rate_staff_verweigert verweigert
    erw "Rate gesetzt" rate_gesetzt "5/3"
    erw "Liste durch Mitarbeiter verweigert" liste_staff_verweigert verweigert
    erw "doppelter Listenname verweigert" liste_doppelt_verweigert verweigert
    erw "CSV: Kopfzeile, Semikolon, BOM, Vor- und Nachname, Anfuehrungszeichen" csv_kopf_semikolon 1
    erw "CSV: Komma" csv_kopf_komma 1
    erw "CSV: ohne Kopfzeile Spalte 1 E-Mail" csv_ohne_kopf 1
    erw "Import ohne Rechtsgrundlage verweigert" import_ohne_rechtsgrundlage_verweigert verweigert
    erw "Import Sonstiges ohne Vermerk verweigert" import_sonstiges_ohne_vermerk_verweigert verweigert
    erw "Import: 5 Zeilen, 2 neu, 1 doppelt, 1 ungueltig, 1 gesperrt" import_zaehler "5,2,1,1,1"
    erw "Import: Wiederholung zaehlt als doppelt" import_wiederholung_doppelt 1
    erw "Import: gesperrte Adresse mit Status suppressed" import_status_gesperrt 1
    erw "Import: E-Mail normalisiert, Rechtsgrundlage und Vermerk gespeichert" import_email_norm 1
    erw "Systemliste ohne Umfang verweigert" systemliste_ohne_umfang_verweigert verweigert
    erw "Systemliste Inhaber: 2" systemliste_inhaber 2
    erw "Systemliste Inhaber und Admins: 3 (inaktiver Benutzer ausgeschlossen)" systemliste_inhaber_admins 3
    erw "Systemliste: kein Endkunde, kein Mitarbeiter, kein inaktives Konto" systemliste_kein_endkunde 1
    erw "Systemliste: kein CSV-Import" systemliste_import_verweigert verweigert
    erw "Systemliste: Rechtsgrundlage Bestandskunde" systemliste_rechtsgrundlage 1
    erw "Systemliste: entferntes Mitglied auf removed" systemliste_entfernt 1
    erw "Sperrliste: doppelte manuelle Sperre wirkungslos" sperre_manuell_doppelt 1
    erw "Sperrliste: manuelle Sperre aufhebbar" sperre_aufheben_manuell ok
    erw "Sperrliste: Aufheben mit kurzem Grund verweigert" sperre_aufheben_kurzer_grund_verweigert verweigert
    erw "Sperrliste: Empfaenger nach Aufheben wieder aktiv" sperre_recipient_wieder_aktiv 1
    erw "Sperrliste: Abmeldung wird nicht durch Bounce ueberschrieben" sperre_unsubscribe_ueberschreibt_nicht_durch_bounce 1
    erw "Sperrliste: Abmeldung nicht aufhebbar" sperre_unsubscribe_aufheben_verweigert verweigert
    erw "Sperrliste: Mitarbeiter darf nicht sperren" sperre_staff_verweigert verweigert
    erw "Kampagne durch Mitarbeiter verweigert" kampagne_staff_verweigert verweigert
    erw "Kampagne: HTML im Text verweigert" kampagne_html_verweigert verweigert
    erw "Kampagne: Gedankenstrich verweigert" kampagne_gedankenstrich_verweigert verweigert
    erw "Kampagne: Schaltflaeche unvollstaendig verweigert" kampagne_button_unvollstaendig_verweigert verweigert
    erw "Kampagne: Schaltflaeche ohne https verweigert" kampagne_button_http_verweigert verweigert
    erw "Kampagne: ohne Liste verweigert" kampagne_ohne_liste_verweigert verweigert
    erw "Kampagne: Entwurf mit zwei Listen" kampagne_entwurf 1
    erw "Rendern: Platzhalter name und firma" render_platzhalter 1
    erw "Rendern: Absaetze" render_absaetze 1
    erw "Rendern: Abmeldelink in HTML und Text" render_abmeldelink 1
    erw "Rendern: Fusstext mit Grund und Abmeldehinweis" render_fusstext_grund 1
    erw "Rendern: Pflichtangaben und CI" render_pflichtangaben 1
    erw "Rendern: leere Platzhalter ohne Reste" render_leere_platzhalter 1
    erw "Vorschau mit Musterdaten" preview_muster 1
    erw "Freigabe ohne Testversand verweigert" start_ohne_test_verweigert verweigert
    erw "Test an ungueltige Adresse verweigert" test_ungueltige_adresse_verweigert verweigert
    erw "Test: Absender des Marketingprofils" test_log_absender 1
    erw "Test: Betreff mit Vorsatz TEST" test_log_betreff_test 1
    erw "Test: Precedence bulk, kein Auto-Submitted" test_log_precedence_bulk 1
    erw "Test: List-Unsubscribe mit One-Click" test_log_list_unsubscribe 1
    erw "Test: Reply-To auf derselben registrierbaren Domain" test_log_reply_to 1
    erw "Test: Ereignis test_send" test_event 1
    erw "Test: Zeitpunkt gespeichert" test_sent_at 1
    erw "Inhaltsaenderung setzt Testversand zurueck" test_nach_aenderung_zurueck 1
    erw "Namensaenderung laesst Testversand bestehen" test_nach_namensaenderung_bleibt 1
    erw "Freigabe: 5 Adressen (Dublette ueber zwei Listen), 1 gesperrt" start_total_skipped "5/1"
    erw "Freigabe: Status queued" start_status queued
    erw "Freigabe: Job marketing_send eingereiht" start_job 1
    erw "Freigabe: zweite Freigabe verweigert" start_doppelt_verweigert verweigert
    erw "Freigegebene Kampagne nicht aenderbar" start_entwurf_nicht_aenderbar verweigert
    erw "Versand: Tagesgrenze 3 (2 Tests verbraucht): 1 gesendet, Grenze, 3 offen" versand_tagesgrenze "1/1/3"
    erw "Versand: Status sending" versand_status_sending sending
    erw "Versand: Rest nach Anheben der Grenze" versand_rest "3/0/0"
    erw "Versand: Sekundenrate eingehalten" versand_sekundenrate_eingehalten 1
    erw "Versand: abgeschlossen 4 gesendet, 1 uebersprungen, 0 Fehler" versand_abgeschlossen "sent/4/1/0"
    erw "Versand: vier eindeutige Abmeldetoken" versand_tokens_eindeutig 4
    erw "Versand: Token nur als Hash gespeichert" versand_kein_klartext_token 1
    erw "Versand: je Empfaenger personalisiert" versand_personalisiert 1
    erw "Versand: gesperrte Adresse nie angeschrieben" versand_gesperrt_nicht_gesendet 1
    erw "Versand: Audit marketing_campaign_sent" versand_audit_sent 1
    erw "Token aufloesbar" token_aufloesbar 1
    erw "Token ungueltig" token_ungueltig "invalid/invalid"
    erw "Abmeldung per Link" abmeldung unsubscribed
    erw "Abmeldung wiederholt" abmeldung_wiederholt already
    erw "Abmeldung: Sperrgrund unsubscribe" abmeldung_sperre unsubscribe
    erw "Abmeldung: Ereignis" abmeldung_event 1
    erw "One-Click erkannt (nur POST mit exaktem Feld)" one_click_erkannt 1
    erw "Abmeldung per One-Click" abmeldung_one_click unsubscribed
    erw "Zweite Kampagne: 3 uebersprungen (Sperre und zwei Abmeldungen)" zweite_skipped 3
    erw "Zweite Kampagne: angehalten" zweite_pausiert paused
    erw "Angehalten: kein Versand" zweite_pause_kein_versand 0
    erw "Unzulaessiger Statuswechsel verweigert" zweite_falscher_wechsel_verweigert verweigert
    erw "Abgebrochen: offene Adressen uebersprungen" zweite_abgebrochen "cancelled/2"
    erw "Versendete Kampagne nicht loeschbar" zweite_loeschen_versendete_verweigert verweigert
    erw "Abgebrochene Kampagne loeschbar" zweite_loeschen_abgebrochene ok
    erw "Liste nach Ende der Kampagnen loeschbar" liste_in_verwendung_loeschbar_nach_ende ok
    erw "Job ohne offene Adressen: completed" job_ohne_offene "completed/0"
    erw "Scheduler: keine offene Kampagne" scheduler_ohne_kampagne 1
    erw "Pool mail und all enthalten marketing_send" pool_mail_enthaelt_marketing 1
    erw "SES: harter Bounce sperrt" ses_bounce_hart_gesperrt "max@example.test/bounce"
    erw "SES: weicher Bounce nur protokolliert" ses_bounce_weich_nicht_gesperrt 1
    erw "SES: Beschwerde sperrt" ses_complaint_gesperrt complaint
    erw "SES: Zustellung nur protokolliert" ses_delivery_nur_protokoll 1
    erw "SNS: Signatur Version 1 gueltig" sns_signatur_v1 1
    erw "SNS: Signatur Version 2 gueltig" sns_signatur_v2 1
    erw "SNS: manipulierte Nachricht abgewiesen" sns_signatur_manipuliert 1
    erw "SNS: fremdes Zertifikat abgewiesen" sns_fremdes_zertifikat 1
    erw "SNS: SubscriptionConfirmation signiert" sns_subscription_signatur 1
    erw "SNS: unbekannter Typ" sns_unbekannter_typ 1
    erw "SNS: Zertifikat nur https von amazonaws.com" sns_zertifikat_nur_amazon 1
    erw "Export: BOM, Kopfzeile, Inhalt" export_bom_kopf 1
    erw "Export durch Mitarbeiter verweigert" export_staff_verweigert verweigert
    for a in marketing_campaign_created marketing_campaign_started marketing_campaign_test_sent marketing_campaign_sent marketing_campaign_cancelled marketing_list_imported marketing_list_synced marketing_rates_changed marketing_suppressed marketing_unsuppressed marketing_list_exported; do
        enth "Audit: $a" audit_aktionen "$a"
    done
    erw "Audit: keine Klartextadressen bei Sperren, Tests, Entfernen" audit_keine_klartext_adresse 1
    fi
else
    echo "  uebersprungen: mariadbd/mariadb-install-db nicht vorhanden"
fi
echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ $FAIL -eq 0 ]]
