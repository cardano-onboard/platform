# Contributing to Onboard.Ninja

Thank you for your interest in contributing to Onboard.Ninja! This guide will help you get set up and submit quality contributions.

## Getting Started

### Prerequisites

- PHP 8.2+
- Composer 2
- Node.js 22 LTS
- MySQL 8 or MariaDB 10.6+
- Redis 7+
- Docker (for running the full stack or E2E tests)

### Local Setup

```bash
git clone https://github.com/cardano-onboard/platform.git
cd platform
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

### Docker Setup

```bash
cp .env.docker .env
docker compose up -d --build
# App available at http://localhost:8080
```

## Development Workflow

### Branching

- `main` is the stable release branch
- Create feature branches from `main`: `feature/your-feature-name`
- Bug fix branches: `fix/description-of-fix`
- Documentation: `docs/what-you-changed`

### Pull Requests

1. Fork the repository and create your branch from `main`
2. Write or update tests for your changes
3. Ensure all tests pass (see Testing below)
4. Run `vendor/bin/pint` to format your PHP code
5. Submit a PR targeting `main`
6. All CI checks must pass before merge

### Commit Messages

Write clear, concise commit messages that describe the "why" rather than the "what":

- `Add campaign status badges to dashboard` (good)
- `Update Dashboard.vue` (too vague)
- `Fix claim validation for enterprise addresses` (good)

## Code Standards

### PHP

- **Formatter:** Laravel Pint (run `vendor/bin/pint` before committing)
- **Style:** PSR-12 with Laravel conventions
- **Models:** Use ULIDs (`HasUlids` trait), soft deletes where appropriate
- **Controllers:** Resource controllers with `authorizeResource()` in constructor
- **Validation:** Use `$request->validate()` in controllers
- **Config:** Use `config()` helper, never `env()` outside of config files

### JavaScript / Vue

- **Framework:** Vue 3 with Composition API (`<script setup>`)
- **UI Library:** Vuetify 3 components (`v-btn`, `v-card`, `v-dialog`, etc.)
- **No TypeScript** — plain JavaScript only
- **Forms:** Use `useForm()` from `@inertiajs/vue3`
- **Routing:** Use `route('name', params)` via Ziggy
- **Wallet Integration:** MeshJS (`@meshsdk/core`) for CIP-30 wallet interactions

### General

- Don't add features beyond what's requested in the issue
- Don't add comments or docstrings to code you didn't change
- Prefer editing existing files over creating new ones

## Testing

### Running Tests

```bash
# PHP code style check
vendor/bin/pint --test

# PHP tests (PHPUnit)
php artisan test

# PHP tests with coverage
php artisan test --coverage --min=50

# Frontend tests (Vitest)
npm test

# E2E browser tests (requires Docker stack + Chrome)
docker compose -f docker-compose.prod.yml -f docker-compose.dusk.yml up -d
php artisan dusk --env=dusk.local
```

### Writing Tests

- **PHP feature tests** go in `tests/Feature/`
- **PHP unit tests** go in `tests/Unit/`
- **Frontend tests** go in `tests/js/` (Vitest)
- **E2E tests** go in `tests/Browser/` (Laravel Dusk)
- Follow existing test patterns and naming conventions
- All new features must include tests

### CI Pipeline

Pull requests trigger the CI workflow which runs:
1. **Lint** — `vendor/bin/pint --test`
2. **PHP Tests** — PHPUnit with PCOV coverage (minimum 50%)
3. **Frontend Tests** — Vitest
4. **Docker Build** — Validates the Dockerfile builds successfully

All checks must pass before a PR can be merged.

## Architecture Overview

### Stack

- **Backend:** Laravel 12 (PHP 8.2+)
- **Frontend:** Vue 3 + Vuetify 3 + Tailwind CSS
- **Glue:** Inertia.js (server-driven SPA)
- **Build:** Vite
- **Database:** MySQL 8
- **Auth:** Laravel Breeze (session-based, single admin for self-hosted)

### Transaction Backend

The platform uses a pluggable transaction backend architecture:

- **`null`** — Test mode (fake wallets, instant transactions)
- **`phyrhose`** — Direct connection to Phyrhose transaction service
- **`proxy`** — Routes through the SaaS platform relay

Set via `TRANSACTION_BACKEND` in your `.env` file. The backend is injected via `TransactionBackend` interface in `app/Contracts/TransactionBackend.php`.

### Key Directories

```
app/
  Contracts/          # TransactionBackend interface
  Http/Controllers/   # Campaign, Code, Profile, Proxy controllers
  Jobs/               # CheckClaims, ProcessClaims, CreateCampaignBucket
  Models/             # Campaign, Code, Claim, Wallet, Reward, User
  Services/           # NullBackend, PhyrhoseBackend, ProxyBackend
resources/js/
  Pages/              # Vue page components (Inertia)
  Layouts/            # AuthenticatedLayout, GuestLayout
  Components/         # Shared Vue components
  plugins/            # vue-cardano (MeshJS wallet integration)
tests/
  Feature/            # HTTP/integration tests
  Unit/               # Unit tests
  Browser/            # Laravel Dusk E2E tests
  js/                 # Vitest frontend tests
  load/               # k6 load test scripts
  security/           # OWASP ZAP scan scripts
```

## Issue Response & Resolution

We aim to respond to issues and pull requests within the following timeframes:

| Issue Type | Initial Response | Resolution Target |
|------------|------------------|-------------------|
| Critical bug (data loss, security, payment failure) | 24 hours | 72 hours |
| High-priority bug (broken core functionality) | 48 hours | 1 week |
| Standard bug | 1 week | 2 weeks |
| Feature request | 1 week | Triage to roadmap |
| Documentation fix | 1 week | 1 week |

These are targets, not guarantees — the project is maintained by a small team. Issues that include clear reproduction steps, environment details, and screenshots will be addressed faster.

## Security

If you discover a security vulnerability, please **do not** open a public issue. Instead, email security concerns to the project maintainers directly. We take security seriously and will respond within 48 hours.

See `docs/security-and-load-testing.md` for our security audit methodology and findings.

## License

By contributing, you agree that your contributions will be licensed under the same license as the project (Apache License 2.0).
