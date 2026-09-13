# Stripe-Review: Kontomodell, Geldfluss, Risiken, Tests (13.09.2026)

Stand 13.09.2026, Code 4.79 (Arbeitsbranch). Primärquellen von Stripe waren in dieser Sitzung nicht abrufbar (Netzsperre);
Aussagen über Stripe-Verhalten sind als Annahme gekennzeichnet, Aussagen über den eigenen Code sind aus dem Quelltext belegt.

## 1. Zwei getrennte Geldflüsse

| | A: Abonnement der Firma für SmartEinzug | B: Einzüge der Firma bei ihren Kunden |
|---|---|---|
| Stripe-Konto | Konto der Müller Holding AG (`config('billing')`, `billing_client()`) | eigenes Konto der Firma (`integrations.stripe_secret_key_encrypted`) |
| Objekte | Customer, Subscription, Price (`lookup_key lexsepa_<tarif>`), Checkout Session mode=subscription, Billing Portal | Customer, PaymentMethod (sepa_debit), PaymentIntent, Charge, Dispute, Refund, Mandate, Checkout Session mode=setup |
| Webhook | `billing-webhook.php`, Secret `billing.stripe_webhook_secret` (Plattform) | `stripe-webhook.php`, Secret je Firma, Zuordnung über `metadata.tenant_id` oder PaymentIntent/Charge |
| Code | `app/billing.php`, `subscription.php` | `app/collections.php`, `app/mandate_requests.php`, `app/stripe_import.php` |
| Vermischung | ausgeschlossen: getrennte Schlüssel, getrennte Endpunkte, getrennte Tabellen (`organizations.platform_stripe_*` gegen `payment_collections`) | |

SmartEinzug hält kein Kundengeld und ist kein Zahlungsdienstleister im Geldfluss B: Die Lastschrift wird im Stripe-Konto der
Firma erzeugt, das Geld fließt an die Firma. Auszahlungen (payouts) liest die Anwendung nicht.

## 2. Ist-Architektur der Anbindung B

- Schlüsselart: Secret Key (`sk_live_`, `sk_test_`) oder Restricted Key (`rk_live_`, `rk_test_`), Format geprüft
  (`settings.php save_stripe`), Modus aus dem Präfix. Kein Stripe Connect, keine Stripe App, kein OAuth (weder Connect-OAuth
  noch App-Auth). Eine Migration auf Connect ist nicht vorgesehen; sie würde bestehende Kunden-, Zahlungsmethoden- und
  Mandatsobjekte, das Preismodell und die Verantwortung für Auszahlungen ändern und wird hier nicht empfohlen, solange kein
  fachlicher Grund vorliegt.
- Speicherung: AES-256-GCM (`app/crypto.php`), Schlüssel nie im Frontend, nie in Logs oder Fehlermeldungen (Fehlertexte
  von Stripe enthalten den Schlüssel nicht; das Audit speichert nur Konto-ID und Modus). Eingabe ausschließlich über das
  POST-Formular der Einstellungen (CSRF, Rolle owner/admin, Support-Modus gesperrt).
- Minimale Rechte: Der Hinweistext empfiehlt einen eingeschränkten Schlüssel mit Schreibrecht Customers, Payment Methods,
  Payment Intents und Leserecht Charges, Disputes; zusätzlich nötig ist Leserecht auf das Konto (`GET /v1/account`, seit
  jeher Grundlage der Prüfung). Eine programmatische Rechteprüfung eines Restricted Keys bietet Stripe nicht; der Zustand
  `permission_missing` entsteht, wenn die Kontoprüfung 403 liefert.
- Rotation und Widerruf: neuer Schlüssel über `save_stripe` (Konto- und Moduswechsel setzen die Stripe-Zuordnung der
  Mandate zurück, Audit `stripe_account_changed`), Trennen über `disconnect_stripe` (löscht Schlüssel und Webhook-Secret,
  nennt die Zahl terminierter Einzüge). Ein widerrufener Schlüssel führt bei „Verbindung prüfen“ zu `auth_failed`.

## 3. Verbindungszustände (seit 4.79)

Kontoprüfung (`integration_verify_stripe`) liest `id`, `business_profile.name`, `charges_enabled` und
`capabilities.sepa_debit_payments` aus `GET /v1/account` und speichert `stripe_charges_enabled`, `stripe_sepa_capability`
(`active`, `inactive`, `pending`, `unrequested`, `unknown`) und `stripe_verify_error` (`auth`, `permission`, `technical`).
`stripe_connection_state()` bildet daraus: `not_connected`, `disconnected`, `auth_failed`, `permission_missing`,
`degraded`, `charges_disabled`, `sepa_unavailable`, `sepa_pending`, `unverified`, `ready`.

Wirkung: `_get_stripe_client($tenantId, true)` (Sofort-Einzug, Vormerkung, Fälligkeitslauf, IBAN-Registrierung, digitales
Mandat) verweigert bei nicht bereitem Konto mit deutscher Meldung; im Fälligkeitslauf wird daraus eine Zurückstellung, kein
Fehlschlag. Lesepfade (Statusabgleich, Klärung, Webhook, Import) laufen weiter. Annahme: Der Name der Fähigkeit lautet
`sepa_debit_payments` mit den Werten active, inactive, pending (Stripe-Dokumentation in dieser Sitzung nicht geprüft);
unbekannte Werte werden als `unknown` behandelt und sperren nicht.

## 4. Webhook-Sicherheit

- Signatur über den unveränderten Body mit dem Secret der Firma (`stripe_verify_webhook_signature`), Beanspruchung je
  Ereignis (`webhook_event_claim`), Reihenfolge je Objekt (`webhook_event_is_stale`), Freigabe und HTTP 500 bei
  Verarbeitungsfehlern, 200 bei bewusstem Ignorieren. Seit 4.79 wird `livemode` gegen `stripe_mode` der Firma geprüft.
- Nicht benötigte, aber signierte Ereignisse werden mit 200 quittiert (kein Fehlerrauschen). Benötigte Ereignisse werden
  erst nach dauerhafter Verarbeitung mit 200 bestätigt.
- Späte Rücklastschriften löschen keinen Erfolgseintrag, sondern setzen `disputed` mit Audit; der Statusabgleich holt
  Rücklastschriften und Erstattungen bis 70 Tage nach dem Einzug nach (4.73).

## 5. Idempotenz und Abgleich

Versuchsjournal `collection_attempts` mit eindeutigem Idempotenzschlüssel je fachlichem Versuch, Stripe-Idempotency-Key auf
`POST /payment_intents`, `uq_collection_tenant_pi` (ein PaymentIntent, ein Einzug), Serialisierung je Firma über
`GET_LOCK`, Klärung unbekannter Ergebnisse über Suche und konsistente Liste (`_stripe_find_payment_intent_by_attempt_key`),
kurze Transaktionen ohne Netzaufrufe unter Sperre. 5xx und 409 sind unbekannte Ergebnisse (Klärung, nie Wiederholung).
Details und Invarianten: `docs/audit/PAYMENT_INVARIANTS.md`.

## 6. Risiken und Restpunkte

| Risiko | Bewertung | Maßnahme |
|---|---|---|
| Firma hinterlegt einen Vollzugriffsschlüssel statt eines eingeschränkten | mittel (Kundenentscheidung) | Hinweistext; Empfehlung dokumentiert; keine technische Erzwingung möglich |
| Dasselbe Stripe- oder Lexware-Konto in zwei Firmenaccounts (B-04) | P1 offen | Entscheidung des Betreibers, Identitätsfeld nötig |
| Rohtexte von Stripe in Kundenmeldungen außerhalb der Kontoprüfung (E-03) | P2 offen | Zuordnung zu deutschen Meldungen je Fehlercode |
| Restricted Key ohne Leserecht auf Disputes: Rücklastschrift-Rückschau des Abgleichs liefert dann keine Charge | niedrig | Zustand `permission_missing` nur bei 403 auf `/account`; Empfehlung im Hinweistext ergänzt |
| Idempotenzschlüssel nach echtem 429 (F-17) | P3 offen | Annahme dokumentiert |

## 7. Testergebnisse (lokal, ohne Stripe-Konto)

| Suite | Ergebnis | Inhalt |
|---|---|---|
| `bash tools/collections-check.sh` | 201/0 | Abschnitt 16 neu: Fähigkeit aktiv/inaktiv/in Prüfung, charges_enabled false, 403, 401, 500, Wiederherstellung, Sperre der Einreichung, Lesepfad frei, Mandantentrennung; 16a Webhook im falschen Modus |
| `php tools/payment-safety-check.php` | 74/0 | Sicherungen unverändert |
| `bash tools/migrations-check.sh` | 12/0 | Migration 035 gegen Vorzustand und schema.sql |

Nicht getestet (kein Konto): echtes Verhalten von `GET /v1/account` mit Restricted Key ohne Kontorecht, tatsächliche
Werte der Capability, Live-Webhook-Ereignisse. Abnahme auf Staging mit Stripe-Testkonto erforderlich
(`docs/backend/release-checklist.md`).
