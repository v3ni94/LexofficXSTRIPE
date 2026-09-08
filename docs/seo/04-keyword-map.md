# Themen- und URL-Zuordnung (SEO_KEYWORD_MAP)

Stand: 08.09.2026. Quelle: Clusteranalyse und Kritik vom 07.09.2026, kuratiert; lastschrift-einfach.de ausgenommen (Weiterleitung), sevdesk-Domains ergänzt. Maschinenlesbar in `keyword-map.json`, geprüft durch `python3 tools/seo-map-check.py`. Regel: Eine Suchintention, eine bevorzugte organische Zielseite über alle Domains. Verlagerungen, Weiterleitungen und Indexierungsänderungen nur nach Freigabe und mit Search-Console-Daten.

Rollen: **primaer** (bevorzugte organische Zielseite), **ergaenzend** (eigener Mehrwert, andere Perspektive), **konkurrent** (gleiche Intention, Konflikt), **kampagne** (Anzeigenvariante, noindex), **rechtlich**, **technisch**. Empfehlungen mit „pruefen“ brauchen Search-Console-Daten und die Freigabe der Geschäftsführung (Maßnahmenplan M4).

## C01_lexware_office_lastschrift_einziehen

**Suchintention:** Ein Unternehmen schreibt Rechnungen in Lexware Office und sucht eine Lösung, um diese per SEPA-Lastschrift einzuziehen.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** Lexware Office Lastschrift · **Varianten:** Lexware Office SEPA-Lastschrift, SEPA-Lastschrift Lexware Office, Lexware Office Lastschrift einziehen, Lexware SEPA Einzug, Lexware-Office-Rechnungen per Lastschrift einziehen  
**Bevorzugte Zielseite:** https://smart-einzug.de/  
**Konflikt:** gleiche_intention_zwei_domains · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexware-einzug.de/lexware-office-lastschrift → https://lexware-einzug.de/; https://lexware-einzug.de/lexware-office-sepa-lastschrift → https://lexware-einzug.de/. Startseite der Leaddomain lexware-einzug.de (DETM) bleibt als eigene Fassung; Spiegelseiten lexware-office-lastschrift und lexware-office-sepa-lastschrift am 08.09.2026 per 301 auf die Startseite der Leaddomain geleitet.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/ | primaer | index | Einzige Seite mit Produktmarke im Title, 940 Wörter, FAQ-Block, Preise, 16 eingehende Links; Domain ist laut Betreiber organischer Schwerpunkt. |
| https://lexware-einzug.de/ | konkurrent | index | 1.252 Wörter, umfangreichste Ausformulierung der gleichen Intention (drei Schritte, Synchronisation, Team, 2FA, Preise, FAQ); eigener Mehrwert gegenüber smart-einzug.de/ nur in der Ausführlichkeit, nicht in der Intention |
| https://lexware-einzug.de/lexware-sepa-einzug | ergaenzend | index | Bedient die Variante "Lexware SEPA Einzug" ohne "Office" und erklärt, dass Lexware Office selbst keine Lastschrift ausführt (538 Wörter, Abschnitt Kosten und Stolpersteine); einziger eigener Blickwinkel im Cluster. |
| https://smart-einzug.de/lp/lexware-lastschrift/ | kampagne | noindex | Kampagnenseite mit noindex, nicht in der Sitemap, 0 eingehende Links; kein organischer Nutzen, Kampagnenzweck. |
| https://lexware-einzug.de/lp/sepa-lastschrift/ | kampagne | noindex | Kampagnenseite mit noindex, nicht in der Sitemap, 0 eingehende Links; H1 identisch mit der H1 von smart-einzug.de/ (QA-Warnung Zeile 15). |

## C02_lexoffice_lastschrift

**Suchintention:** Ein Nutzer, der sein Programm noch "lexoffice" nennt, will wissen, wie er Rechnungen aus lexoffice per Lastschrift einzieht.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** lexoffice Lastschrift · **Varianten:** lexoffice Lastschrift einrichten, lexoffice Lastschriftverfahren, lexoffice SEPA, lexoffice SEPA Zahlungen, lexoffice Rechnungen Lastschrift  
**Bevorzugte Zielseite:** https://lexoffice-einzug.de/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexoffice-einzug.de/lexoffice-lastschrift → https://lexoffice-einzug.de/; https://lexoffice-einzug.de/lexoffice-sepa-lastschrift → https://lexoffice-einzug.de/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://lexoffice-einzug.de/ | primaer | index | Einzige Startseite mit Begriff "Lexoffice" im Title, 1.283 Wörter, erklärt Umbenennung, Voraussetzungen, Ablauf und FAQ für ehemalige lexoffice-Nutzer; 22 eingehende Links. |
| https://lexoffice-einzug.de/lp/lastschrift-einrichten/ | kampagne | noindex | Kampagnenseite mit noindex, 641 Wörter, nicht in Sitemap, 0 eingehende Links; H1 identisch mit smart-einzug.de/lp/lexoffice-lastschrift/ (QA-Warnung Zeile 16). |
| https://smart-einzug.de/lp/lexoffice-lastschrift/ | kampagne | noindex | Kampagnenseite mit noindex auf der Produktdomain, 324 Wörter, 0 eingehende Links; kein organischer Nutzen. |

## C03_lastschrifteinzug_automatisieren

**Suchintention:** Ein Unternehmen will den wiederkehrenden Lastschrifteinzug offener Lexware-Office-Rechnungen automatisieren und den Ablauf von Synchronisation bis Status verstehen.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** Lexware Office Lastschrifteinzug · **Varianten:** Lastschrifteinzug Lexware Office automatisieren, lexoffice Lastschrifteinzug, offene Rechnungen per Lastschrift einziehen, wiederkehrender Lastschrifteinzug  
**Bevorzugte Zielseite:** https://lexware-einzug.de/lexware-office-lastschrifteinzug  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexoffice-einzug.de/lexoffice-lastschrifteinzug → https://lexoffice-einzug.de/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://lexware-einzug.de/lexware-office-lastschrifteinzug | primaer | index | Einzige Seite, die Automatisierung (Hintergrundsynchronisation, Terminierung, Statusverfolgung) als Kern behandelt (310 Wörter); aber 0 eingehende interne Links, also verwaist. |
| https://lexware-einzug.de/offene-rechnungen-per-lastschrift-einziehen | ergaenzend | index | Fokus auf Erkennung und Synchronisation offener Rechnungen einschließlich Fälligkeit und Vorlauf (289 Wörter, 5 eingehende Links); Teilaspekt des Clusters. |

## C04_lexware_office_verbinden_api

**Suchintention:** Ein Nutzer will Lexware Office über die Public API mit SmartEinzug verbinden, sucht den API-Schlüssel, den Menüpunkt oder den benötigten Tarif.  
**Zielgruppe:** bestandsnutzer_einrichtung · **Hauptbegriff:** Lexware Office verbinden · **Varianten:** Lexware Office API-Schlüssel einrichten, Lexware Office Public API, Lexware Office Public API Tarif XL, Public API in Lexware Office nicht gefunden, Lexware-Office-Integration  
**Bevorzugte Zielseite:** https://smart-einzug.de/anleitungen/lexware-office-verbinden/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexware-einzug.de/anleitung/lexware-office-api → https://smart-einzug.de/anleitungen/lexware-office-verbinden/; https://lexware-einzug.de/lexware-office-api → https://smart-einzug.de/anleitungen/lexware-office-verbinden/; https://lexoffice-einzug.de/lexoffice-public-api-xl → https://smart-einzug.de/anleitungen/lexware-office-verbinden/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/integrationen/lexware-office/ | ergaenzend | index | Integrationsseite: was gelesen wird, wie oft synchronisiert wird, Voraussetzungen; die Anleitung beschreibt die Einrichtung. |
| https://smart-einzug.de/anleitungen/lexware-office-verbinden/ | primaer | index | Schritt-für-Schritt-Anleitung mit den Bezeichnungen der Anwendung: API-Schlüssel erzeugen, hinterlegen, Verbindungstest, was synchronisiert wird. |

## C05_stripe_rolle_verbinden

**Suchintention:** Ein Unternehmen will verstehen, warum und wie Lexware Office mit Stripe verbunden wird und welche Rolle Stripe beim Einzug übernimmt.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** Lexware Office mit Stripe verbinden · **Varianten:** lexoffice Stripe verbinden, Lexware Office Stripe, lexoffice Stripe Lastschrift, Stripe SEPA-Lastschrift Lexware Office  
**Bevorzugte Zielseite:** https://lexware-einzug.de/lexware-office-stripe  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexoffice-einzug.de/lexoffice-stripe-lastschrift → https://lexoffice-einzug.de/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://lexware-einzug.de/lexware-office-stripe | primaer | index | Erklärt Aufgabenteilung der drei Systeme, Datenfluss und Voraussetzungen (291 Wörter, 5 eingehende Links); aktuelle Produktbezeichnung im Title. |

## C06_stripe_verbinden_anleitung

**Suchintention:** Ein Bestandsnutzer will sein Stripe-Konto Schritt für Schritt mit SmartEinzug verbinden, einschließlich Secret Key und Webhook.  
**Zielgruppe:** bestandsnutzer_einrichtung · **Hauptbegriff:** Stripe mit SmartEinzug verbinden · **Varianten:** Stripe Secret Key SmartEinzug, Stripe Webhook einrichten SmartEinzug, Stripe Testmodus Livemodus SmartEinzug  
**Bevorzugte Zielseite:** https://smart-einzug.de/anleitungen/stripe-verbinden/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexware-einzug.de/anleitung/stripe-verbinden → https://smart-einzug.de/anleitungen/stripe-verbinden/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/anleitungen/stripe-verbinden/ | primaer | index | Anleitung für das eigene Stripe-Konto einschließlich Webhook mit den sieben verarbeiteten Ereignissen und Test-/Livemodus. |

## C07_einrichtung_erster_einzug

**Suchintention:** Ein neuer Nutzer will SmartEinzug von der Registrierung bis zum ersten SEPA-Einzug einrichten und wissen, welche Voraussetzungen er dafür braucht.  
**Zielgruppe:** bestandsnutzer_einrichtung · **Hauptbegriff:** SmartEinzug einrichten Schritt für Schritt · **Varianten:** lexoffice Lastschrift einrichten Anleitung, SEPA-Lastschrift für lexoffice einrichten, erster Lastschrifteinzug, Was brauche ich für eine SEPA-Lastschrift, So funktioniert SmartEinzug  
**Bevorzugte Zielseite:** https://smart-einzug.de/anleitungen/erster-lastschrifteinzug/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Verlagert (M4, 08.09.2026): https://lexoffice-einzug.de/ratgeber/was-brauche-ich-fuer-eine-sepa-lastschrift → https://smart-einzug.de/wissen/voraussetzungen-sepa-lastschrift/. Weitergeleitet per 301 (M4, 08.09.2026): https://lexoffice-einzug.de/lexoffice-lastschrift-einrichten → https://smart-einzug.de/anleitungen/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/so-funktionierts/ | ergaenzend | index | Kurzfassung des Ablaufs in drei Schritten mit Voraussetzungen; die Anleitung vertieft. |
| https://smart-einzug.de/wissen/voraussetzungen-sepa-lastschrift/ | ergaenzend | index | Checkliste der Voraussetzungen vor dem ersten Einzug (Mandat, IBAN, Gläubiger-ID, Stripe-Verbindung, 322 Wörter); Teilaspekt, nur 2 eingehende Links. Am 08.09.2026 von der Leaddomain auf die Hauptdomain verlagert (M4), a |
| https://smart-einzug.de/anleitungen/erster-lastschrifteinzug/ | primaer | index | Vollständiger Weg vom eingerichteten Firmenaccount bis zum ersten Einzug und zur Statuskontrolle, mit Karenzzeit, Einreichfenster und Klärungsfällen. |

## C08_hilfe_faq_hub

**Suchintention:** Ein Bestandsnutzer oder Interessent sucht die Hilfeübersicht oder häufige Fragen zu SmartEinzug (Marken- und Navigationssuche).  
**Zielgruppe:** gemischt · **Hauptbegriff:** SmartEinzug Hilfe · **Varianten:** SmartEinzug FAQ, Häufige Fragen SmartEinzug, SmartEinzug Anleitung, Webhook einrichten SmartEinzug  
**Bevorzugte Zielseite:** https://smart-einzug.de/hilfe/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexware-einzug.de/hilfe → https://smart-einzug.de/hilfe/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/hilfe/ | primaer | index | Hilfeübersicht auf der Produktdomain mit Schnellstart in fünf Schritten, Webhook-Abschnitt, Startfragen und Kontakt (405 Wörter, 14 eingehende Links). |
| https://lexware-einzug.de/faq | ergaenzend | index | Einzige eigenständige FAQ-Seite mit elf Fragen zu Lexware Office, Stripe, Datenspeicherung und Rücklastschriften (374 Wörter, 25 eingehende Links); auf smart-einzug.de gibt es FAQ nur als Abschnitt der Startseite. |

## C09_funktionen

**Suchintention:** Ein Interessent will wissen, welche Funktionen SmartEinzug bietet.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** SmartEinzug Funktionen · **Varianten:** Funktionen SmartEinzug, SEPA-Lastschrift Software Funktionen Lexware Office  
**Bevorzugte Zielseite:** https://smart-einzug.de/funktionen/  
**Konflikt:** gleiche_intention_zwei_domains · **Empfehlung:** zusammenfuehren_pruefen · **Freigabe nötig:** ja · **Status:** in_bereinigung_phase_2 · **Prüftermin:** 08.12.2026  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/funktionen/ | primaer | index | Funktionsübersicht mit sechs Abschnitten von Forderungen bis Mitarbeiterzugang (305 Wörter, 14 eingehende Links) auf der Produktdomain. |
| https://lexware-einzug.de/funktionen | konkurrent | index | Gleiche Intention, identischer Title "Funktionen von SmartEinzug", vier Abschnitte (254 Wörter, 25 eingehende Links); kein Inhalt, der auf smart-einzug.de/funktionen/ fehlt. |

## C10_preise

**Suchintention:** Ein Interessent will wissen, was SmartEinzug kostet und was im Tarif UNLIMITED START enthalten ist.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** SmartEinzug Preise · **Varianten:** SmartEinzug Kosten, UNLIMITED START Preis, Kosten SEPA-Lastschrift Software Lexware Office  
**Bevorzugte Zielseite:** https://smart-einzug.de/preise/  
**Konflikt:** gleiche_intention_zwei_domains · **Empfehlung:** zusammenfuehren_pruefen · **Freigabe nötig:** ja · **Status:** in_bereinigung_phase_2 · **Prüftermin:** 08.12.2026  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/preise/ | primaer | index | Preisseite der Produktdomain mit Leistungsumfang, Voraussetzungen und Fragen vor der Registrierung (352 Wörter, 14 eingehende Links). |
| https://lexware-einzug.de/preise | konkurrent | index | Gleicher Tarif, gleiche Angaben, zusätzlich Preis-FAQ (325 Wörter, 25 eingehende Links); kein eigener Nutzen erkennbar über die Preis-FAQ hinaus. |

## C11_sicherheit

**Suchintention:** Ein Interessent oder Kunde will wissen, wie SmartEinzug Zugangsdaten, Mandantendaten und Zahlungsvorgänge schützt.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** SmartEinzug Sicherheit · **Varianten:** SmartEinzug Datenschutz Sicherheit, Zwei-Faktor-Authentifizierung SmartEinzug, Webhook-Signaturprüfung  
**Bevorzugte Zielseite:** https://smart-einzug.de/sicherheit/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexware-einzug.de/sicherheit → https://smart-einzug.de/sicherheit/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/sicherheit/ | primaer | index | Belegbare Sicherheitsaussagen mit offen benannten Grenzen (Protokoll 90 Tage, keine Verfügbarkeitszusage, IBAN maskiert statt verschlüsselt). |

## C12_integrationen_uebersicht

**Suchintention:** Ein Interessent will wissen, welche Rechnungssysteme SmartEinzug anbindet.  
**Zielgruppe:** gemischt · **Hauptbegriff:** SmartEinzug Integrationen · **Varianten:** SmartEinzug unterstützte Rechnungssysteme, SEPA-Lastschrift Integration Buchhaltung  
**Bevorzugte Zielseite:** https://smart-einzug.de/integrationen/  
**Konflikt:** keine · **Empfehlung:** ausbauen · **Freigabe nötig:** nein · **Status:** in_bereinigung_phase_2 · **Prüftermin:** 08.12.2026  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/integrationen/ | primaer | index | Einzige Integrationsübersicht, nennt Lexware Office als verfügbar und sevdesk als geplant, aber nur 128 Wörter. |

## C13_sevdesk

**Suchintention:** Ein sevdesk-Nutzer sucht eine Möglichkeit, sevdesk-Rechnungen per SEPA-Lastschrift einzuziehen, und will sich für die geplante Anbindung vormerken.  
**Zielgruppe:** gemischt · **Hauptbegriff:** sevdesk SEPA-Lastschrift · **Varianten:** sevdesk Lastschrift, sevdesk Lastschrifteinzug, sevdesk Stripe, sevdesk SEPA-Lastschrift Vorregistrierung  
**Bevorzugte Zielseite:** https://smart-einzug.de/integrationen/sevdesk/  
**Konflikt:** gleiche_intention_zwei_domains · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** in_bereinigung_phase_2 · **Prüftermin:** 08.12.2026  
**Hinweis:** Betreiberentscheidung 07.09.2026: eigene Leaddomain; Inhalte getrennt (Ähnlichkeit unter 10 Prozent), Rankings nach Start beobachten.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/integrationen/sevdesk/ | primaer | index | Umfangreichste Seite des Bestands (1.895 Wörter) mit geplantem Ablauf, Abgrenzung, FAQ und Vormerkung; einzige sevdesk-Seite, 3 eingehende Links. |
| https://sevdesk-einzug.de/ | ergaenzend | index | Leadseite mit Vormerkformular im Fokus, kompakter Planungsstand, Voraussetzungen; die Detailseite bleibt smart-einzug.de/integrationen/sevdesk/. |

## C14_lexoffice_umbenennung

**Suchintention:** Ein Nutzer will wissen, ob lexoffice und Lexware Office dasselbe sind und was sich durch die Umbenennung ändert.  
**Zielgruppe:** bestandsnutzer_einrichtung · **Hauptbegriff:** lexoffice heißt jetzt Lexware Office · **Varianten:** lexoffice Lexware Office Umbenennung, lexoffice Lexware Office Unterschied, ist lexoffice Lexware Office  
**Bevorzugte Zielseite:** https://lexoffice-einzug.de/lexoffice-heisst-jetzt-lexware-office  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** in_bereinigung_phase_2 · **Prüftermin:** 08.12.2026  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://lexoffice-einzug.de/lexoffice-heisst-jetzt-lexware-office | primaer | index | Einzige Seite zur Umbenennung mit sechs Fragen zu Software, Konto, API-Einstellungen und alten Anleitungen (484 Wörter, 21 eingehende Links); entspricht genau der Domainrolle. |

## C15_sepa_mandat_verwalten_produkt

**Suchintention:** Ein Unternehmen will SEPA-Mandate zu seinen Lexware-Office-Kunden hinterlegen, Rechnungen zuordnen und verwalten.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** SEPA-Mandat Lexware Office · **Varianten:** lexoffice SEPA-Mandat, SEPA-Mandat verwalten Lexware Office, Mandatsverwaltung SmartEinzug, lexoffice SEPA-Mandat verwalten  
**Bevorzugte Zielseite:** https://lexware-einzug.de/lexware-office-sepa-mandat  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexoffice-einzug.de/lexoffice-sepa-mandat → https://lexoffice-einzug.de/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://lexware-einzug.de/lexware-office-sepa-mandat | primaer | index | Mandatsreferenzen in SmartEinzug, Zusammenspiel mit offenen Rechnungen, Verantwortung des Kunden (298 Wörter, 6 eingehende Links). |

## C16_sepa_mandat_wissen

**Suchintention:** Ein Unternehmen oder Zahlungspflichtiger will verstehen, was ein SEPA-Mandat ist, welche Pflichtangaben es hat und wie Widerruf, Verfall, Mandatsreferenz und Gläubiger-ID funktionieren.  
**Zielgruppe:** gemischt · **Hauptbegriff:** SEPA-Mandat · **Varianten:** Wie funktioniert ein SEPA-Mandat, SEPA-Mandat Pflichtangaben, SEPA-Lastschriftmandat, Mandatsreferenz, Gläubiger-Identifikationsnummer, Mandatsreferenz Gläubiger-ID Kontoauszug  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/sepa-mandat/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Verlagert (M4, 08.09.2026): https://lexware-einzug.de/ratgeber/mandatsreferenz-glaeubiger-id → https://smart-einzug.de/wissen/mandatsreferenz-und-glaeubiger-id/. Weitergeleitet per 301 (M4, 08.09.2026): https://lexoffice-einzug.de/ratgeber/wie-funktioniert-ein-sepa-mandat → https://smart-einzug.de/wissen/sepa-mandat/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/mandatsreferenz-und-glaeubiger-id/ | ergaenzend | index | Teilaspekt Mandatsreferenz und Gläubiger-ID mit Hinweis zum Kontoauszug des Zahlers (274 Wörter); einziger Abschnitt im Bestand, der Zahlungspflichtige indirekt anspricht (websites/lexware-einzug.de/ratgeber/mandatsrefer |
| https://smart-einzug.de/wissen/sepa-mandat/ | primaer | index | Mandat so, wie SmartEinzug es abbildet: Basislastschrift, Mandatsreferenz automatisch, Gläubiger-ID auf dem Dokument, Papiererteilung, Widerruf, Verfall. |

## C17_mandat_einholen_bestandskunden

**Suchintention:** Ein Unternehmen will von bestehenden Kunden ein SEPA-Mandat einholen und sucht Vorgehen und Anschreiben.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** SEPA-Mandat bei Bestandskunden einholen · **Varianten:** SEPA-Mandat einholen Anschreiben, SEPA-Mandat Muster Anschreiben Bestandskunden, Kunden auf Lastschrift umstellen  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/sepa-mandat-einholen/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Verlagert (M4, 08.09.2026): https://lexoffice-einzug.de/ratgeber/sepa-mandat-einholen-bestandskunden → https://smart-einzug.de/wissen/sepa-mandat-einholen/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/sepa-mandat-einholen/ | primaer | index | Sechsstufiges Vorgehen mit Formulierungsvorschlag und Fehlerliste (795 Wörter, 4 eingehende Links); einzige Seite der Intention, ohne lexoffice-spezifischen Inhalt. Am 08.09.2026 von der Leaddomain auf die Hauptdomain ve |

## C18_ruecklastschrift

**Suchintention:** Ein Unternehmen oder Zahlungspflichtiger will verstehen, warum eine Lastschrift zurückgegangen ist, welche Fristen und Kosten gelten und was zu tun ist.  
**Zielgruppe:** gemischt · **Hauptbegriff:** Rücklastschrift · **Varianten:** Was passiert bei einer Rücklastschrift, Rücklastschrift Gründe Fristen, SEPA Rücklastschrift was tun, Rücklastschrift Kosten  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/ruecklastschrift/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexoffice-einzug.de/ratgeber/was-passiert-bei-einer-ruecklastschrift → https://smart-einzug.de/wissen/ruecklastschrift/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/ruecklastschrift/ | primaer | index | Rücklastschrift und Erstattung bei Einzug über Stripe: Erkennung nur per Webhook, kein Neuversuch, Klärungsbedarf, Grenzen der Software. |

## C19_ablauf_fristen_zahlungsstatus

**Suchintention:** Ein Unternehmen will wissen, wie lange eine SEPA-Lastschrift dauert, welche Fristen und Vorlaufzeiten gelten und wann sie als bezahlt gilt.  
**Zielgruppe:** gemischt · **Hauptbegriff:** SEPA-Lastschrift Fristen · **Varianten:** SEPA-Lastschrift Vorlaufzeit, Ablauf SEPA-Lastschrift, Wie lange dauert eine Lastschrift, Lastschrift nicht sofort bezahlt, Lastschrift Status in Bearbeitung, SEPA-Lastschrift Bankarbeitstage  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/fristen-und-vorlaufzeiten/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Verlagert (M4, 08.09.2026): https://lexware-einzug.de/ratgeber/sepa-lastschrift-fristen-vorlaufzeiten → https://smart-einzug.de/wissen/fristen-und-vorlaufzeiten/; https://lexoffice-einzug.de/ratgeber/warum-ist-eine-lastschrift-nicht-sofort-bezahlt → https://smart-einzug.de/wissen/zahlungsstatus-einer-lastschrift/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/fristen-und-vorlaufzeiten/ | primaer | index | Vollständigste Darstellung von Vorabankündigung, Einreichungsfrist bei Stripe, Fälligkeit, Rückgabefristen und Planung terminierter Einzüge (755 Wörter, 3 eingehende Links). Am 08.09.2026 von der Leaddomain auf die Haupt |
| https://smart-einzug.de/wissen/zahlungsstatus-einer-lastschrift/ | ergaenzend | index | Beantwortet die konkrete Frage nach dem Status "in Bearbeitung" mit Beispiel und Hinweis zur Buchhaltung (324 Wörter, 2 eingehende Links); Frageform passt auch für Zahlungspflichtige. Am 08.09.2026 von der Leaddomain auf |

## C20_vorabankuendigung

**Suchintention:** Ein Unternehmen will wissen, was die Vorabankündigung einer SEPA-Lastschrift ist und welche Frist üblich ist.  
**Zielgruppe:** gemischt · **Hauptbegriff:** Vorabankündigung SEPA-Lastschrift · **Varianten:** Pre-Notification SEPA, Vorabankündigung Frist 14 Tage, Vorabinformation Lastschrift  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/vorabankuendigung/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexware-einzug.de/ratgeber/vorabankuendigung-sepa-lastschrift → https://smart-einzug.de/wissen/vorabankuendigung/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/vorabankuendigung/ | primaer | index | Vorabankündigung als optionale Firmeneinstellung mit Frist, Versandinhalt, Sperre von Sofort-Einzügen; Grenzen offen benannt. |

## C21_lastschrift_oder_ueberweisung

**Suchintention:** Ein Unternehmen prüft, ob sich SEPA-Lastschrift für den eigenen Betrieb lohnt oder ob die Überweisung sinnvoller bleibt.  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** Lastschrift oder Überweisung · **Varianten:** Für wen sich SEPA-Lastschrift lohnt, Lastschrift Vorteile Nachteile Unternehmen, lexoffice Lastschrift oder Überweisung  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/lastschrift-oder-ueberweisung/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Verlagert (M4, 08.09.2026): https://lexoffice-einzug.de/ratgeber/lexoffice-lastschrift-oder-ueberweisung → https://smart-einzug.de/wissen/lastschrift-oder-ueberweisung/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/lastschrift-oder-ueberweisung/ | primaer | index | Vergleich nach Liquidität, Mahnaufwand, Rücklastschriftrisiko, Kundenakzeptanz und Einrichtungsaufwand mit Einschätzung (761 Wörter, 3 eingehende Links); Begriff "lexoffice" im Title passt zur Domainrolle. Am 08.09.2026  |

## C22_lastschrift_verbuchen_lexware_office

**Suchintention:** Ein Bestandsnutzer will eingezogene Stripe-Lastschriften, Sammelauszahlungen, Gebühren und Rücklastschriften in Lexware Office verbuchen.  
**Zielgruppe:** bestandsnutzer_einrichtung · **Hauptbegriff:** Lastschrift in Lexware Office verbuchen · **Varianten:** Stripe Auszahlung Lexware Office buchen, Sammelauszahlung Stripe verbuchen, Stripe Gebühren Lexware Office  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/lastschrift-in-lexware-office-verbuchen/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Verlagert (M4, 08.09.2026): https://lexware-einzug.de/ratgeber/sepa-lastschrift-buchen-lexware-office → https://smart-einzug.de/wissen/lastschrift-in-lexware-office-verbuchen/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/lastschrift-in-lexware-office-verbuchen/ | primaer | index | Einzige Seite zur Verbuchung mit Auszahlungen, Sammelauszahlungen, Gebühren, Rücklastschriften und Abstimmung (764 Wörter, 3 eingehende Links). Am 08.09.2026 von der Leaddomain auf die Hauptdomain verlagert (M4), alte Ad |

## C23_kunden_rechnungen_zuordnen

**Suchintention:** Ein Bestandsnutzer will verstehen, wie SmartEinzug Kunden und Rechnungen aus Lexware Office erkennt, dedupliziert und zuordnet.  
**Zielgruppe:** bestandsnutzer_einrichtung · **Hauptbegriff:** Kunden und Rechnungen zuordnen SmartEinzug · **Varianten:** Laufkunden-Sammelnummer, Kundennummer Lexware-ID Zuordnung, Deduplizierung Kunden Lexware Office  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/kunden-und-rechnungen-zuordnen/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Verlagert (M4, 08.09.2026): https://lexoffice-einzug.de/ratgeber/kunden-und-rechnungen-richtig-zuordnen → https://smart-einzug.de/wissen/kunden-und-rechnungen-zuordnen/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/kunden-und-rechnungen-zuordnen/ | primaer | index | Einzige Seite zur Zuordnungslogik (Kundennummer, Lexware-ID, Laufkunden-Sammelnummer, 395 Wörter), aber nur 1 eingehender Link; reiner Produkt-Hilfeinhalt ohne Suchbegriff mit Nachfragepotenzial außerhalb der Marke. Am 0 |

## C24_ratgeber_uebersicht

**Suchintention:** Ein Leser sucht eine Übersicht von Ratgeberartikeln zur SEPA-Lastschrift (Navigations- und Einstiegsseite).  
**Zielgruppe:** gemischt · **Hauptbegriff:** Ratgeber SEPA-Lastschrift · **Varianten:** SEPA-Lastschrift einfach erklärt, lexoffice Lastschrift Ratgeber, Ratgeber Lexware Office SEPA  
**Bevorzugte Zielseite:** https://smart-einzug.de/wissen/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** m4_umgesetzt_ungetestet_live · **Prüftermin:** 08.12.2026  
**Hinweis:** Weitergeleitet per 301 (M4, 08.09.2026): https://lexoffice-einzug.de/ratgeber/ → https://smart-einzug.de/wissen/; https://lexware-einzug.de/ratgeber/ → https://smart-einzug.de/wissen/.  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/wissen/ | primaer | index | Übersicht der Wissensseiten der Hauptdomain; die Anleitungsübersicht ergänzt sie. |
| https://smart-einzug.de/anleitungen/ | ergaenzend | index | Übersicht der Anleitungen (Einrichtung), getrennt von den Wissensseiten (Verfahren). |

## C27_vergleich_wettbewerber

**Suchintention:** Ein Interessent vergleicht SmartEinzug mit anderen Lastschriftanbietern (SEPA-Held, GoCardless).  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** SmartEinzug Vergleich · **Varianten:** SEPA-Held Alternative, GoCardless Alternative Lexware Office, Lastschrift Anbieter Vergleich  
**Bevorzugte Zielseite:** keine (kein organisches Ziel)  
**Konflikt:** keine · **Empfehlung:** ausbauen · **Freigabe nötig:** ja · **Status:** in_bereinigung_phase_2 · **Prüftermin:** 08.12.2026  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/vergleich/sepaheld-gocardless/ | ergaenzend | noindex | Interner Entwurf mit noindex, ohne Description, ohne Sitemap, 0 eingehende Links, 144 Wörter, nur Platzhalter für Kriterien; kein eigener Nutzen erkennbar im aktuellen Zustand. |

## C28_kontakt

**Suchintention:** Ein Kunde oder Interessent will SmartEinzug kontaktieren (Navigationssuche).  
**Zielgruppe:** gemischt · **Hauptbegriff:** SmartEinzug Kontakt · **Varianten:** SmartEinzug Support, SmartEinzug E-Mail  
**Bevorzugte Zielseite:** https://smart-einzug.de/kontakt/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** in_bereinigung_phase_2 · **Prüftermin:** 08.12.2026  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/kontakt/ | primaer | index | Einzige Kontaktseite im Bestand (68 Wörter, 14 eingehende Links); lexoffice-einzug.de und lexware-einzug.de haben keine eigene Kontaktseite, nur Kontaktabschnitte in den Hilfeseiten. |

## C29_rechtlich_technisch

**Suchintention:** Keine eigene Suchintention: Rechtsseiten (Impressum, Datenschutz, AGB) und Fehlerseiten, die je Domain vorhanden sein müssen.  
**Zielgruppe:** gemischt · **Hauptbegriff:** SmartEinzug Impressum · **Varianten:** SmartEinzug AGB, SmartEinzug Datenschutz, Impressum lastschrift-einfach.de  
**Bevorzugte Zielseite:** keine (kein organisches Ziel)  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** in_bereinigung_phase_2 · **Prüftermin:** 08.12.2026  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://smart-einzug.de/404 | technisch | noindex | Fehlerseite mit noindex, kein organischer Nutzen. |
| https://smart-einzug.de/agb/ | rechtlich | index | AGB der Produktdomain (610 Wörter), rechtlich erforderlich. |
| https://smart-einzug.de/datenschutz/ | rechtlich | index | Datenschutzerklärung der Produktdomain (1.172 Wörter), rechtlich erforderlich; einzige mit Abschnitt zur Vorregistrierung. |
| https://smart-einzug.de/impressum/ | rechtlich | index | Impressum der Produktdomain (192 Wörter), rechtlich erforderlich. |
| https://lexoffice-einzug.de/404 | technisch | noindex | Fehlerseite mit noindex, kein organischer Nutzen. |
| https://lexoffice-einzug.de/agb | rechtlich | index | AGB-Kopie für lexoffice-einzug.de (530 Wörter), rechtlich erforderlich je Domain. |
| https://lexoffice-einzug.de/datenschutz | rechtlich | index | Datenschutzerklärung für lexoffice-einzug.de (859 Wörter), rechtlich erforderlich je Domain. |
| https://lexoffice-einzug.de/impressum | rechtlich | index | Impressum für lexoffice-einzug.de (141 Wörter), rechtlich erforderlich je Domain. |
| https://lexware-einzug.de/404 | technisch | noindex | Fehlerseite mit noindex, kein organischer Nutzen. |
| https://lexware-einzug.de/agb | rechtlich | index | AGB-Kopie für lexware-einzug.de (573 Wörter), identische Description mit smart-einzug.de/agb/, rechtlich erforderlich je Domain. |
| https://lexware-einzug.de/datenschutz | rechtlich | index | Datenschutzerklärung für lexware-einzug.de (1.008 Wörter), rechtlich erforderlich je Domain. |
| https://lexware-einzug.de/impressum | rechtlich | index | Impressum für lexware-einzug.de (155 Wörter), identische Description mit smart-einzug.de/impressum/, rechtlich erforderlich je Domain. |
| https://sevdesk-einzug.de/impressum | rechtlich | index | Pflichtseite |
| https://sevdesk-einzug.de/datenschutz | rechtlich | index | Pflichtseite |
| https://sevdesk-einzug.de/404 | technisch | noindex | Pflichtseite |
| https://sevdesk-sepa.de/impressum | rechtlich | index | Pflichtseite |
| https://sevdesk-sepa.de/datenschutz | rechtlich | index | Pflichtseite |
| https://sevdesk-sepa.de/404 | technisch | noindex | Pflichtseite |

## C30_sevdesk_sepa_wissen

**Suchintention:** Ein Unternehmen, das Rechnungen in sevdesk schreibt, will das SEPA-Lastschriftverfahren verstehen und den Einzug vorbereiten (Mandat, Gläubiger-ID, Vorabankündigung, Rücklastschrift).  
**Zielgruppe:** unternehmen_einziehen · **Hauptbegriff:** sevdesk SEPA Lastschrift · **Varianten:** sevdesk Lastschriftmandat, sevdesk Gläubiger-ID, sevdesk Vorabankündigung, sevdesk Rücklastschrift  
**Bevorzugte Zielseite:** https://sevdesk-sepa.de/  
**Konflikt:** keine · **Empfehlung:** behalten · **Freigabe nötig:** nein · **Status:** technische_pruefung · **Prüftermin:** 08.12.2026  

| URL | Rolle | Index | Eigenständiger Mehrwert |
|---|---|---|---|
| https://sevdesk-sepa.de/ | primaer | index | Verfahren, Mandat, Gläubiger-ID, Vorabankündigung, Rücklastschrift, Checkliste aus Sicht eines sevdesk-Nutzers; keine Produktbeschreibung. |

## Lücken auf der Hauptdomain (Stand der Clusteranalyse vom 07.09.2026, vor Phase 3b)

- **Lexware Office verbinden (API-Schlüssel, Public API, Tarif)** (C04_lexware_office_verbinden_api): teilweise vorhanden, dünn: smart-einzug.de/integrationen/lexware-office/ mit 149 Wörtern und 2 eingehenden Links; keine Schritt-für-Schritt-Anleitung
- **Erster Lastschrifteinzug (Einrichtung bis zum ersten Einzug, Voraussetzungen)** (C07_einrichtung_erster_einzug): teilweise vorhanden: smart-einzug.de/so-funktionierts/ (276 Wörter) und Schnellstart in smart-einzug.de/hilfe/ (405 Wörter); keine ausführliche Anleitung mit Voraussetzungsliste
- **SEPA-Mandat (Wissen und Verwaltung)** (C15_sepa_mandat_verwalten_produkt, C16_sepa_mandat_wissen, C17_mandat_einholen_bestandskunden): nicht vorhanden: keine Seite mit Mandat im Title oder in der H1; Mandatsverwaltung nur als Abschnitt auf smart-einzug.de/funktionen/ ("Mandatsverwaltung je Kunde")
- **Rücklastschrift** (C18_ruecklastschrift): nicht vorhanden: Begriff nur in Prüfwörtern der Startseite, Funktionsseite und sevdesk-Seite, keine eigene Seite
- **Sicherheit** (C11_sicherheit): nicht vorhanden: keine Sicherheitsseite (docs/seo/01-bestandsaufnahme.md Zeile 13); Sicherheitsaspekte nur als Abschnitte auf Startseite und Datenschutzerklärung
- **sevdesk** (C13_sevdesk): vorhanden: smart-einzug.de/integrationen/sevdesk/ mit 1.895 Wörtern; keine Lücke
- **Integrationsübersicht** (C12_integrationen_uebersicht): vorhanden, aber dünn: smart-einzug.de/integrationen/ mit 128 Wörtern
- **Stripe mit SmartEinzug verbinden (Anleitung mit Webhook)** (C06_stripe_verbinden_anleitung): teilweise vorhanden: nur Abschnitt "Webhook einrichten, damit Stripe den Status zurückmeldet" auf smart-einzug.de/hilfe/; keine eigene Anleitungsseite
- **Fristen, Vorlaufzeiten, Vorabankündigung und Verbuchung (Wissens- und Bestandsnutzerinhalte)** (C19_ablauf_fristen_zahlungsstatus, C20_vorabankuendigung, C22_lastschrift_verbuchen_lexware_office): nicht vorhanden: keine Ratgeber- oder Anleitungsseiten auf smart-einzug.de (16 Seiten im Inventar, davon keine vom Typ ratgeber oder anleitung)
- **Zahlungspflichtige, die eine Abbuchung auf dem Kontoauszug verstehen wollen** (kein Cluster; Teilaspekt in C16_sepa_mandat_wissen): nicht vorhanden; im gesamten Bestand spricht nur der Abschnitt "Hinweis zum Kontoauszug" auf lexware-einzug.de/ratgeber/mandatsreferenz-glaeubiger-id Zahler indirekt an