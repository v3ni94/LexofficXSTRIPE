# LexSEPA

LexSEPA verbindet **Lexoffice** mit **Stripe** und automatisiert den SEPA-Lastschrifteinzug für offene Rechnungen.

> **Produktive Variante "Lexware-Einzug" (PHP für IONOS Webhosting):** Unter
> [`php-ionos/`](php-ionos/) liegt die mehrmandantenfähige SaaS-Anwendung
> (Betreiber Müller Holding AG) mit verpflichtender 2FA, Rollen Inhaber/Mitarbeiter,
> Tarifen, Audit-Log, SEPA-Mandatsdokument und Plattform-Abrechnung. Sie läuft ohne
> Docker per FTP auf IONOS (`app.lexware-einzug.de`, derzeit `sepa.muellerhv.de`).
> Die beiden Marketingseiten liegen unter [`websites/`](websites/).
> Installation und Betrieb: [`php-ionos/ANLEITUNG-IONOS.md`](php-ionos/ANLEITUNG-IONOS.md).
> Die nachfolgend beschriebene FastAPI/React-Variante ist der ältere Prototyp.

## Features

- Synchronisation offener Rechnungen von Lexoffice
- Automatische Kundenverwaltung mit IBAN-Hinterlegung
- SEPA-Mandate (Stammkunden- und Laufkundensystem)
- Einzel- und Sammeleinzug via Stripe SEPA Direct Debit
- Stripe-Webhook-Verarbeitung (Succeeded / Failed)
- Multi-Tenant-Architektur: Jeder Nutzer sieht nur seine eigenen Daten
- JWT-Authentifizierung mit Refresh-Token-Rotation
- Strukturiertes JSON-Logging, Rate Limiting, verschlüsselte Schlüsselspeicherung

## Tech Stack

| Schicht | Technologie |
|---------|-------------|
| Backend | FastAPI · SQLAlchemy (async) · Alembic · MySQL 8 |
| Frontend | React 18 · TypeScript · Vite · Tailwind CSS |
| Payments | Stripe (SEPA Direct Debit) |
| Buchhaltung | Lexoffice API |
| Deployment | Docker Compose · nginx (SSL/Let's Encrypt) |

---

## Schnellstart (Entwicklung)

### Voraussetzungen

- Docker >= 24
- Docker Compose >= 2.20

### Setup

```bash
# 1. Repository klonen
git clone <repo-url>
cd LexofficXSTRIPE

# 2. Umgebungsvariablen anlegen
cp .env.production.example .env
# .env editieren: MYSQL_PASSWORD, JWT_SECRET_KEY, ENCRYPTION_KEY setzen

# 3. Stack starten (override.yml wird automatisch geladen)
docker compose up --build

# Frontend:  http://localhost (via nginx)
# Backend:   http://localhost:8000
# API Docs:  http://localhost:8000/docs
```

Der Backend-Container führt **automatisch** `alembic upgrade head` beim Start aus.

---

## Produktion

### 1. SSL-Zertifikat ausstellen (Let's Encrypt)

```bash
certbot certonly --standalone -d lexsepa.example.com
```

### 2. Umgebungsvariablen

```bash
cp .env.production.example .env
```

Alle Pflichtfelder ausfüllen:

| Variable | Beschreibung | Generieren mit |
|----------|-------------|----------------|
| `DOMAIN` | Öffentliche Domain | — |
| `MYSQL_ROOT_PASSWORD` | MySQL Root-Passwort | `openssl rand -hex 32` |
| `MYSQL_PASSWORD` | MySQL App-Passwort | `openssl rand -hex 32` |
| `JWT_SECRET_KEY` | JWT-Signierungsschlüssel | `python -c "import secrets; print(secrets.token_hex(64))"` |
| `ENCRYPTION_KEY` | Fernet-Schlüssel (API-Keys at rest) | `python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"` |

### 3. Stack starten

```bash
docker compose -f docker-compose.yml up -d --build
```

### 4. Health Check

```bash
curl https://lexsepa.example.com/api/health
# {"status": "ok"}
```

---

## Lexoffice-Integration einrichten

1. In Lexoffice: **Einstellungen → API → API-Schlüssel generieren**
2. Im Frontend unter **Einstellungen → Lexoffice** den Schlüssel eintragen
3. **Synchronisieren** – Rechnungen werden importiert

---

## Stripe-Integration einrichten

1. Stripe-Dashboard: **Developers → API Keys** – Secret Key kopieren
2. Im Frontend unter **Einstellungen → Stripe** eintragen
3. Webhook-Endpunkt in Stripe registrieren:
   - URL: `https://<domain>/api/webhooks/stripe`
   - Events: `payment_intent.succeeded`, `payment_intent.payment_failed`
4. Webhook-Secret im Frontend hinterlegen

---

## Tests ausführen

```bash
cd backend

# Abhängigkeiten (einmalig)
pip install -e ".[dev]"

# Alle Tests
pytest

# Mit Coverage
pytest --cov=app --cov-report=term-missing
```

Die Tests verwenden eine **SQLite In-Memory-Datenbank** – kein laufender MySQL-Server nötig.

---

## Projektstruktur

```
LexofficXSTRIPE/
├── backend/
│   ├── app/
│   │   ├── main.py              # FastAPI App, Middleware, Exception Handler
│   │   ├── config.py            # Pydantic Settings
│   │   ├── models/              # SQLAlchemy ORM-Modelle
│   │   ├── routers/             # API-Endpunkte
│   │   ├── services/            # Business-Logik
│   │   │   ├── collection_service.py
│   │   │   ├── sync_service.py
│   │   │   ├── lexoffice_service.py
│   │   │   └── stripe_service.py
│   │   ├── middleware/
│   │   │   ├── rate_limiter.py
│   │   │   └── request_logger.py
│   │   └── utils/
│   │       ├── exceptions.py
│   │       ├── logging_config.py
│   │       └── iban.py
│   ├── alembic/                 # Datenbankmigrationen
│   ├── tests/                   # pytest Test-Suite
│   ├── Dockerfile
│   ├── entrypoint.sh
│   └── pyproject.toml
├── frontend/
│   ├── src/
│   │   ├── api/client.ts        # Axios mit Auth + Toast-Fehlerbehandlung
│   │   ├── components/
│   │   │   └── ErrorBoundary.tsx
│   │   └── pages/
│   ├── Dockerfile               # Multi-Stage: deps → dev → builder → production
│   └── nginx.conf               # SPA-Serving (try_files)
├── nginx/
│   ├── nginx.conf               # Produktion: SSL, Rate Limiting, envsubst-Template
│   ├── nginx.dev.conf           # Entwicklung: Plain HTTP Proxy
│   ├── docker-entrypoint.sh
│   └── Dockerfile
├── docker-compose.yml           # Produktion
├── docker-compose.override.yml  # Entwicklung (automatisch geladen)
└── .env.production.example
```

---

## API-Referenz

Interaktive Dokumentation: `/docs` (Swagger UI) und `/redoc`.

### Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/auth/register` | Registrieren |
| `POST` | `/auth/login` | Einloggen, JWT erhalten |
| `POST` | `/auth/refresh` | Access Token erneuern |
| `GET` | `/invoices` | Rechnungen auflisten |
| `POST` | `/invoices/sync` | Lexoffice-Sync auslösen |
| `GET` | `/customers` | Kunden auflisten |
| `PUT` | `/customers/{id}/iban` | IBAN aktualisieren |
| `POST` | `/collections/submit` | Einzel-Einzug starten |
| `POST` | `/collections/batch` | Sammel-Einzug starten |
| `GET` | `/dashboard/stats` | Dashboard-Kennzahlen |
| `POST` | `/webhooks/stripe` | Stripe Webhook-Empfänger |

---

## Datenbankmigrationen

```bash
# Neue Migration erstellen
cd backend
alembic revision --autogenerate -m "add new table"

# Migrationen anwenden
alembic upgrade head
```

---

## Lizenz

Proprietär – alle Rechte vorbehalten.
