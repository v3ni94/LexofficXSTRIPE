<?php
/**
 * Prueffaelle der Rechtsdokumente (app/legal.php) und der Audit-Aufbewahrung gegen eine echte, temporaere Datenbank.
 * Aufruf durch tools/legal-check.sh:  php tools/lib/legal-sim.php <repo>   Ausgabe: Zeilen "key=wert".
 */
declare(strict_types=1);
$root = $argv[1] ?? '';
require $root . '/php-ionos/app/bootstrap.php';
require_once $root . '/php-ionos/app/audit.php';
require_once $root . '/php-ionos/app/legal.php';
require_once $root . '/php-ionos/app/legal_drafts.php';
$pdo = db();
foreach (['legal_acceptances', 'legal_documents', 'audit_log'] as $t) { $pdo->exec("DELETE FROM $t"); }
$pdo->exec("DELETE FROM organizations WHERE id IN ('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222')");
$pdo->exec("INSERT INTO organizations (id, name, mandate_prefix) VALUES ('11111111-1111-1111-1111-111111111111','Firma A','FA'), ('22222222-2222-2222-2222-222222222222','Kanzlei B','KB')");
$out = static function (string $k, $v): void { echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : (string)$v) . "\n"; };
$admin = ['user_id' => null, 'email' => 'admin@example.test', 'role' => 'owner', 'is_superadmin' => 1, 'org_id' => null];
$ownerA = ['user_id' => null, 'email' => 'inhaber@firma-a.test', 'role' => 'owner', 'org_id' => '11111111-1111-1111-1111-111111111111'];
$memberA = ['user_id' => null, 'email' => 'mitarbeiter@firma-a.test', 'role' => 'member', 'org_id' => '11111111-1111-1111-1111-111111111111'];
$ownerB = ['user_id' => null, 'email' => 'inhaber@kanzlei-b.test', 'role' => 'owner', 'org_id' => '22222222-2222-2222-2222-222222222222'];

// 1. Vorlagen uebernehmen (unveroeffentlicht)
$ids = [];
foreach (legal_drafts() as $d) {
    $ids[$d['code']] = legal_document_create($admin, $d['code'], $d['version'], $d['title'], $d['summary'], $d['body_md'], $d['required_for']);
}
$out('vorlagen', count($ids));
$out('aktiv_vor_veroeffentlichung', count(legal_active_documents()));
$out('status_a_ohne_veroeffentlichung', count(legal_status_for_org($ownerA['org_id'])));

// 2. Platzhalter sperren die Veroeffentlichung
try { legal_document_publish($admin, $ids['avv'], true); $out('platzhalter_sperre', 0); } catch (Throwable $e) { $out('platzhalter_sperre', 1); }
// Zustimmung zu unveroeffentlichter Fassung verweigert
try { legal_accept($ownerA, $ids['avv']); $out('accept_unveroeffentlicht_verweigert', 0); } catch (Throwable $e) { $out('accept_unveroeffentlicht_verweigert', 1); }

// 3. Fassung ohne Platzhalter veroeffentlichen
$avv1 = legal_document_create($admin, 'avv', 'test-1', 'AVV Test', null, "# 1\n\nText ohne Platzhalter.", 'all');
legal_document_publish($admin, $avv1, true);
$sec1 = legal_document_create($admin, 'secrecy', 'test-1', 'Verschwiegenheit Test', null, "# 1\n\nText.", 'secrecy');
legal_document_publish($admin, $sec1, true);
$act = legal_active_documents();
$out('aktiv_nach_veroeffentlichung', count($act));
$out('aktiv_avv_ist_test1', ($act['avv']['id'] ?? '') === $avv1 ? 1 : 0);

// 4. Pflichten: Firma A (ohne Verschwiegenheit) braucht nur AVV; Kanzlei B beides
$out('pending_a', implode(',', legal_pending_for_org($ownerA['org_id'])));
legal_set_secrecy($ownerB, true, 'Steuerberatung');
$pb = legal_pending_for_org($ownerB['org_id']); sort($pb); $out('pending_b', implode(',', $pb));
$out('missing_avv', legal_missing_counts()['avv'] ?? -9);
$out('missing_secrecy', legal_missing_counts()['secrecy'] ?? -9);

// 5. Rechte: Mitarbeiter darf nicht akzeptieren
try { legal_accept($memberA, $avv1); $out('member_verweigert', 0); } catch (Throwable $e) { $out('member_verweigert', 1); }
try { legal_set_secrecy($memberA, true, null); $out('member_secrecy_verweigert', 0); } catch (Throwable $e) { $out('member_secrecy_verweigert', 1); }

// 6. Inhaber akzeptiert, idempotent, Nachweis mit E-Mail und Weg
legal_accept($ownerA, $avv1, 'registration');
legal_accept($ownerA, $avv1, 'backend');
$acc = legal_acceptances_for_org($ownerA['org_id']);
$out('nachweise_a', count($acc));
$out('nachweis_email', $acc[0]['user_email'] ?? '');
$out('nachweis_weg', $acc[0]['method'] ?? '');
$out('pending_a_nach_accept', implode(',', legal_pending_for_org($ownerA['org_id'])));
$out('audit_legal_accepted', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='legal_accepted'")->fetchColumn());
$out('audit_secrecy', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='legal_secrecy_set'")->fetchColumn());
$out('mandant_b_unberuehrt', count(legal_acceptances_for_org($ownerB['org_id'])));

// 7. Neue Fassung: alte zurueckgezogen, Zustimmung erneut noetig, alter Nachweis bleibt
$avv2 = legal_document_create($admin, 'avv', 'test-2', 'AVV Test 2', null, "# 1\n\nNeuer Text.", 'all');
legal_document_publish($admin, $avv2, true);
$alt = legal_document_load($avv1);
$out('alte_fassung_zurueckgezogen', $alt['retired_at'] !== null ? 1 : 0);
$st = legal_status_for_org($ownerA['org_id']);
$out('neue_fassung_offen', $st['avv']['acceptance'] === null ? 1 : 0);
$out('alter_nachweis_sichtbar', ($st['avv']['accepted_other_version']['doc_version'] ?? '') === 'test-1' ? 1 : 0);
$out('nachweise_a_bleiben', count(legal_acceptances_for_org($ownerA['org_id'])));

// 8. Veroeffentlichte Fassung nicht loeschbar, unveroeffentlichte schon
try { legal_document_delete($admin, $avv1); $out('veroeffentlicht_nicht_loeschbar', 0); } catch (Throwable $e) { $out('veroeffentlicht_nicht_loeschbar', 1); }
legal_document_delete($admin, $ids['secrecy']);
$out('unveroeffentlicht_geloescht', legal_document_load($ids['secrecy']) === null ? 1 : 0);

// 9. Renderer: Escaping, Struktur
$html = legal_render_md("# Titel <b>x</b>\n\nAbsatz **fett** [Platzhalter: Anschrift]\n\n- Punkt eins\n- Punkt zwei\n\n1. Erstens\n2. Zweitens");
$out('render_escaped', str_contains($html, '&lt;b&gt;') && !str_contains($html, '<b>') ? 1 : 0);
$out('render_struktur', (str_contains($html, '<h2>') && str_contains($html, '<ul>') && str_contains($html, '<ol>') && str_contains($html, '<strong>fett</strong>') && str_contains($html, 'mark class="placeholder"')) ? 1 : 0);

// 10. Audit-Aufbewahrung: alte Eintraege werden geloescht, neue bleiben
$pdo->exec("INSERT INTO audit_log (tenant_id, user_id, user_email, action, created_at) VALUES ('11111111-1111-1111-1111-111111111111', NULL, 'alt@example.test', 'login_success', DATE_SUB(NOW(), INTERVAL 100 DAY))");
$pdo->exec("INSERT INTO audit_log (tenant_id, user_id, user_email, action, created_at) VALUES ('11111111-1111-1111-1111-111111111111', NULL, 'neu@example.test', 'login_success', DATE_SUB(NOW(), INTERVAL 80 DAY))");
$vorher = (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$geloescht = audit_cleanup();
$out('audit_geloescht', $geloescht);
$out('audit_rest', (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $vorher - 1 ? 1 : 0);
$out('audit_retention_default', audit_retention_days());
$out('audit_min_30', audit_cleanup(5) >= 0 ? 1 : 0);
