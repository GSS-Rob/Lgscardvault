# AGENTS.md

## Cursor Cloud specific instructions

This is a multi-tenant TCG store marketplace: a **Symfony 8 / PHP 8.4** backend
(`backend/`) + **React 19 / Vite** frontend (`frontend/`) backed by
**PostgreSQL 16**. See `README.md` for the full setup guide, demo accounts, API
list, and standard lint/test/build commands; only the non-obvious,
cloud-environment-specific caveats are captured here.

### Environment Builds (`.cursor/environment.json`)

This repo uses **Cursor Cloud Builds**. System packages (PHP 8.4 + extensions,
Composer, Node 22, PostgreSQL 16) come from `.cursor/Dockerfile`. Repo
dependencies are baked during Build creation via the `install` script in
`.cursor/environment.json`:

```bash
composer install --working-dir=backend --no-interaction --no-progress
npm install --prefix frontend --no-fund --no-audit
```

After changing `install` or the Dockerfile, trigger a new Build on the
[Cloud Agents → Builds](https://cursor.com/dashboard/cloud-agents#environments)
tab and **activate** the successful build. Agents boot from the active Build —
you do not reinstall on every run unless the Build is stale.

**Latest validated build:** `bld-20260818-f2a2e4a6-7aee-4e48-8f24-96f7cf5a5b40`
(environment `f7539bed-9077-11f1-ba66-0e7d0216e441`) — re-build after merging
`.cursor/Dockerfile` if `composer` was missing in an earlier draft.

### Postgres is NOT started automatically — start it first

Unlike the README (which uses Docker Compose for Postgres), this environment
runs **PostgreSQL 16 natively** and Docker is not used. The server does not
auto-start on boot. Before running the backend, migrations, or tests, start it:

```bash
sudo pg_ctlcluster 16 main start   # idempotent; ignore "already running"
pg_isready                         # expect: accepting connections
```

Connection matches `backend/.env` exactly: role `store` / password `store` on
`127.0.0.1:5432`, databases `store` (dev) and `store_test` (PHPUnit). The role
and both databases already exist in the snapshot. There is no Mailpit; the
`MAILER_DSN` in `.env` points at `127.0.0.1:1025`, so email *delivery* fails,
but nothing in the core login/browse/inventory flows needs it.

### One-time state that persists in the snapshot

The JWT keypair (`backend/config/jwt/*.pem`), the migrated dev database, and the
seeded demo data (`app:seed`) are already in place from initial setup. You do
**not** need to regenerate keys, re-migrate, or re-seed on a normal run. If you
ever reset the dev DB, re-run migrations then `php bin/console app:seed`.

### Running the services

Standard commands are in `README.md` (§Quick start). In short, from `backend/`:
`php -S 127.0.0.1:8000 -t public` (API on 8000) and
`php bin/console messenger:consume async -vv` (worker for CSV imports / catalog
sync jobs — uploads queue forever without it). From `frontend/`:
`npm run dev` (Vite on 5173, proxies `/api` → `127.0.0.1:8000`). Use separate
long-lived shells (e.g. tmux) for each. `./start-dev.sh` also works **only after
Postgres is running**, but note it invokes Docker Compose and will fail here —
prefer starting Postgres manually and launching the three processes directly.

### Testing gotchas

- Backend tests need `store_test` migrated once per schema change:
  `APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction`,
  then `php bin/phpunit`. `dama/doctrine-test-bundle` wraps each test in a
  rolled-back transaction, so tests are isolated.
- The `AccessDenied` lines printed during PHPUnit are expected (authz tests),
  not failures — trust the final summary line.
- Frontend checks: `npm run lint` (oxlint), `npx tsc --noEmit`, `npm run build`.
  A harmless `EBADENGINE` warning appears because `react-router` requests a
  slightly newer Node patch than the snapshot's Node 22.x; builds/tests pass.
