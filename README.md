# LexSEPA

Multi-Tenant SaaS web application for managing SEPA direct debit collections via Lexoffice and Stripe integrations.

## Architecture

- **Backend**: Python 3.12, FastAPI, SQLAlchemy (async), MySQL
- **Frontend**: React, TypeScript, Vite, Tailwind CSS
- **Reverse Proxy**: Nginx
- **Database**: MySQL 8

## Quick Start

```bash
# Copy environment file
cp .env.example .env

# Start all services
docker-compose up --build

# Start with frontend dev server
docker-compose --profile dev up --build
```

The application will be available at:
- **App**: http://localhost
- **API Health Check**: http://localhost/api/health
- **API Docs**: http://localhost/api/docs

## Project Structure

```
lexsepa/
├── backend/          # FastAPI backend
├── frontend/         # React + Vite frontend
├── nginx/            # Reverse proxy configuration
├── docker-compose.yml
└── .env.example
```

## Development

### Backend

```bash
cd backend
pip install -e ".[dev]"
uvicorn app.main:app --reload
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

### Database Migrations

```bash
cd backend
alembic upgrade head
alembic revision --autogenerate -m "description"
```
