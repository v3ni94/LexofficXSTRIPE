-- 035: Stripe-Verbindungszustand je Firma (Version 4.76, wiederholbar, rein additiv)
--
-- Bis 4.75 pruefte das Verbinden nur, ob GET /v1/account mit dem Schluessel antwortet (Konto-ID, Name, Modus). Ob das
-- Konto Zahlungen annehmen darf (charges_enabled) und ob SEPA-Lastschrift freigeschaltet ist (capabilities.sepa_debit_payments),
-- blieb ungeprueft; ein Einzug scheiterte dann erst bei Stripe mit einem Rohtext. Die drei Spalten halten das Ergebnis der
-- letzten Kontopruefung fest; app/integrations.php leitet daraus den Verbindungszustand ab (stripe_connection_state()).
-- NULL bedeutet: mit dem Code vor 4.76 verbunden, noch nicht neu geprueft (kein Einzug wird deshalb gesperrt).
ALTER TABLE integrations
    ADD COLUMN IF NOT EXISTS stripe_charges_enabled TINYINT(1)   NULL AFTER stripe_last_verified_at,   -- 1/0 laut GET /v1/account, NULL = unbekannt
    ADD COLUMN IF NOT EXISTS stripe_sepa_capability VARCHAR(16)  NULL AFTER stripe_charges_enabled,    -- active | inactive | pending | unrequested | unknown
    ADD COLUMN IF NOT EXISTS stripe_verify_error    VARCHAR(16)  NULL AFTER stripe_sepa_capability;    -- auth | permission | technical, NULL = letzte Pruefung erfolgreich
