# Orbit

A REST API to manage your **contacts** (like a phone's contacts app) and track the **real-life
interactions** you have with them: when you met, how it went, what you felt, the issues that came up,
where it happened, and which services, shops or other people it led to.

Orbit is **API-only**: a separate frontend (e.g. Next.js) consumes it. It is built with
**Laravel 13 / PHP 8.4**, packaged with **Docker** (nginx + PHP-FPM) and deployed on
**Kubernetes** with **Helm**.

```
                 ┌──────────────────────── Kubernetes ─────────────────────────┐
  Next.js  ──▶  Ingress ──▶ Service ──▶ web pod: [ nginx :8080 ─▶ php-fpm :9000 ]  (HPA, PDB)
  3rd-party ──▶   (links:read token)          worker pod:    php artisan queue:work  ──▶ photo thumbnails
                                               scheduler pod: php artisan schedule:work ─▶ pruning
                                               migrate Job:   helm hook (post-install / pre-upgrade)
                                                     │                │
                                                 PostgreSQL         Redis (cache + queue)   Photos: PVC or S3
                 └─────────────────────────────────────────────────────────────┘
```

## Contents

- [Domain model](#domain-model)
- [API](#api)
  - [Authentication and tokens](#authentication-and-tokens)
  - [Contacts](#contacts)
  - [Interactions](#interactions)
  - [Interaction link exports (third-party)](#interaction-link-exports-third-party)
- [Running locally](#running-locally)
- [Docker](#docker)
- [Kubernetes / Helm](#kubernetes--helm)
- [Configuration](#configuration)
- [Development](#development)

## Domain model

| Entity | Fields |
|--------|--------|
| **Contact** | first/last name, nickname, company, job title, birthday, notes, favorite, photo (+ thumbnail) |
| ↳ phone numbers / emails / URLs / addresses | many per contact, each with a `label` (mobile, work, home, …) and one `is_primary` |
| **Interaction** | `occurred_at`, title, note, `mood` (great · good · neutral · bad · awful), thoughts, `outcome` (positive · neutral · negative), issues, location (name, latitude, longitude) |
| ↳ contacts | one or more contacts per interaction, each with an optional `role` (e.g. "host") |
| ↳ **links** | outbound links: `type` (service · shop · person · other), label, URL (normalized `url_host`), optional short `note`, optional `linked_contact_id` when it points to someone you know |

Everything is scoped per user. Contacts and interactions are soft-deleted and permanently pruned after
30 days by the scheduler (`orbit:prune-deleted`).

## API

Base URL: `/api/v1`. JSON everywhere. Authenticate with `Authorization: Bearer <token>`.
Validation errors return `422` with an `errors` object. Records that belong to someone else return
`404`. List endpoints are paginated (`?page=`, `?per_page=` up to 100) with `data`, `links` and `meta`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/up` | Liveness (outside `/api/v1`) |
| `GET` | `/health/ready` | Readiness: checks the database and Redis (`503` when unavailable) |
| `POST` | `/auth/register` | Create an account → full-access token |
| `POST` | `/auth/login` | Log in → full-access token |
| `POST` | `/auth/logout` | Revoke the current token |
| `GET` | `/auth/me` | Current user and token abilities |
| `GET` `POST` `DELETE` | `/auth/tokens[/{id}]` | List, mint and revoke **scoped** tokens for third parties |
| `GET` `POST` | `/contacts` | List (`q`, `favorite`, `sort`), create |
| `GET` `PATCH` `DELETE` | `/contacts/{id}` | Show, update, delete |
| `POST` `DELETE` | `/contacts/{id}/photo` | Upload (multipart `photo`) or remove the photo |
| `GET` | `/contacts/{id}/interactions` | A contact's interaction timeline |
| `GET` `POST` | `/interactions` | List (`contact_id`, `from`, `to`, `mood`, `outcome`, `q`), create |
| `GET` `PATCH` `DELETE` | `/interactions/{id}` | Show, update, delete |
| `GET` | `/stats/overview` | Counts by mood/outcome, top contacts |
| `GET` | `/exports/interaction-links` | Flat export of links: JSON (cursor pages), NDJSON or CSV |
| `GET` | `/exports/interaction-links/recurrence` | Recurrence analysis of link targets |

### Authentication and tokens

```bash
curl -X POST localhost:8080/api/v1/auth/register -H 'Content-Type: application/json' -d '{
  "name": "Ada", "email": "ada@example.com",
  "password": "secret-password", "password_confirmation": "secret-password"
}'
# => { "token_type": "Bearer", "access_token": "1|…", "user": { … } }
```

Tokens from `register`/`login` have **full access** (`*`). To let another service read your data, mint a
**scoped** token that can only call the export endpoints:

```bash
curl -X POST localhost:8080/api/v1/auth/tokens -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"name": "analytics", "abilities": ["links:read"], "expires_in_days": 90}'
# => { "data": { "id": 7, "abilities": ["links:read"], … }, "access_token": "7|…" }   (shown once)
```

A `links:read` token gets `403` on every other endpoint.

### Contacts

```bash
curl -X POST localhost:8080/api/v1/contacts -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{
  "first_name": "Anna", "last_name": "Rossi", "company": "Acme", "birthday": "1990-04-12",
  "is_favorite": true,
  "phone_numbers": [{ "label": "mobile", "number": "+39 333 1234567" },
                    { "label": "work",   "number": "+39 02 555 0100", "is_primary": true }],
  "emails":    [{ "label": "work", "email": "anna@acme.test" }],
  "urls":      [{ "label": "linkedin", "url": "https://linkedin.com/in/anna" }],
  "addresses": [{ "label": "home", "city": "Milano", "country": "IT" }]
}'
```

On `PATCH`, a nested collection (`phone_numbers`, `emails`, `urls`, `addresses`) **replaces** the
existing one when present, and is left untouched when omitted.

Photos: `POST /contacts/{id}/photo` with a multipart `photo` (jpg, png, webp, gif; up to 5 MB). The
original is stored right away. A queued job then resizes it to at most 1024 px, re-encodes it as JPEG
(which strips EXIF), and creates a 256 px square thumbnail. The response includes `photo_url` and
`photo_thumb_url`, which are signed temporary URLs on S3.

### Interactions

```bash
curl -X POST localhost:8080/api/v1/interactions -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{
  "occurred_at": "2026-09-20T19:30:00+02:00",
  "title": "Dinner at the lake",
  "note": "Long talk about the new job.",
  "mood": "good",
  "thoughts": "Should see them more often.",
  "outcome": "positive",
  "issues": "Marco is worried about moving.",
  "location_name": "Lago di Como", "latitude": 45.987, "longitude": 9.2572,
  "contacts": [{ "id": 1, "role": "host" }, { "id": 2 }],
  "links": [
    { "type": "shop",    "label": "Trattoria Da Mario", "url": "https://www.damario.it", "note": "Great risotto" },
    { "type": "service", "label": "Booking", "url": "https://booking.com" },
    { "type": "person",  "linked_contact_id": 3, "note": "They suggested calling Luca" }
  ]
}'
```

- `occurred_at` accepts any offset and is stored in UTC.
- `contact_ids: [1, 2]` is a shorthand for `contacts` when there are no roles.
- On `PATCH`, `links` is synced: items with an `id` are updated in place (their ids stay stable
  for exports), new items are created, and missing ones are removed.
- A `person` link with `linked_contact_id` may omit `label`; it defaults to the contact's name.

### Interaction link exports (third-party)

These are read-only endpoints for analysing how often real-life interactions lead out to the same
services, shops or people. They work with any token that has the `links:read` ability and are
rate-limited to 60 requests per minute per token.

**Filters** (both endpoints): `from`, `to` (on the interaction's `occurred_at`), `type`
(`type=shop,service` or `type[]=shop`), `contact_id` (links of interactions with that contact),
`linked_contact_id`, `host` (e.g. `amazon.it`), `q` (label/note search). Links of deleted interactions
are excluded.

#### `GET /exports/interaction-links`

| `format` | Response |
|----------|----------|
| `json` (default) | Cursor-paginated: `?per_page=` up to 500, then follow `meta.next_cursor` / `links.next` |
| `ndjson` | Streamed: one JSON document per line, all matching rows |
| `csv` | Streamed: flat columns (lists joined with `\|`), all matching rows |

One row per link:

```json
{
  "id": 42, "type": "shop", "label": "Blue Bottle", "url": "https://bluebottlecoffee.com/menu",
  "url_host": "bluebottlecoffee.com", "note": "Great flat white",
  "linked_contact": null,
  "interaction": {
    "id": 17, "occurred_at": "2026-05-16T07:30:00+00:00", "title": "Coffee",
    "mood": "good", "mood_score": 1, "outcome": "positive",
    "location_name": "Milano", "latitude": 45.4642, "longitude": 9.19
  },
  "contacts": [{ "id": 1, "name": "Anna Rossi", "role": "met" }],
  "created_at": "2026-05-16T08:02:11+00:00"
}
```

CSV columns: `id, type, label, url, url_host, note, linked_contact_id, linked_contact_name,
interaction_id, occurred_at, interaction_title, mood, mood_score, outcome, location_name, latitude,
longitude, contact_ids, contact_names, created_at`.

#### `GET /exports/interaction-links/recurrence`

Aggregates links by **target**: the linked contact if there is one, otherwise the URL host, otherwise
the normalized label. `group_by=type` or `group_by=month` are also available, and `min_occurrences=`
filters out rare targets.

```json
{
  "data": [{
    "key": "host:bluebottlecoffee.com", "type": "shop", "label": "Blue Bottle",
    "url_host": "bluebottlecoffee.com", "linked_contact": null,
    "occurrences": 3, "interactions": 3, "distinct_days": 3,
    "first_seen": "2026-05-02T07:00:00+00:00", "last_seen": "2026-06-01T06:45:00+00:00",
    "avg_days_between": 15,
    "mood_counts": { "good": 3 }, "avg_mood_score": 1, "outcome_counts": { "positive": 3 }
  }],
  "meta": { "group_by": "target", "groups": 1, "links": 3, "filters": {}, "generated_at": "…" }
}
```

`mood_score` maps moods to numbers (great 2, good 1, neutral 0, bad −1, awful −2) so you can compute
averages and trends.

## Running locally

### With Docker Compose (production-like)

```bash
make up          # creates .env with an APP_KEY, builds images, starts everything on :8080
make smoke       # end-to-end check: register, contacts, photo, interactions, exports, recurrence
make logs
make down
```

The stack runs **nginx** (`:8080`), **php-fpm**, a **queue worker**, the **scheduler**, a one-shot
**migrate** job, **PostgreSQL 17** and **Redis 8**. Photos are stored on a shared volume.

### Without Docker

```bash
composer install
cp .env.example .env && php artisan key:generate
# point DB_*/REDIS_* at local services, or use sqlite: DB_CONNECTION=sqlite, CACHE_STORE=file, QUEUE_CONNECTION=sync
php artisan migrate --seed      # demo@orbit.test / password
php artisan serve
```

## Docker

One `Dockerfile` with two runtime targets:

| Target | Image | Contents |
|--------|-------|----------|
| `app` | `orbit-app` | PHP 8.4-FPM (Alpine) with pdo_pgsql, redis, gd, intl, zip, opcache + JIT; runs as `www-data`; used for web, worker, scheduler and migrations |
| `nginx` | `orbit-nginx` | Unprivileged nginx serving `public/` and proxying `index.php` to PHP-FPM (`PHP_FPM_HOST`, default `127.0.0.1`) |

```bash
docker build --target app   -t orbit-app .
docker build --target nginx -t orbit-nginx .
```

The entrypoint builds Laravel's config, route and event caches at start-up, because environment
variables only exist at runtime. It then execs the command, so the same image can run
`php-fpm`, `php artisan queue:work` and so on. PHP-FPM pool sizing is controlled by environment
variables (`PHP_FPM_PM_MAX_CHILDREN`, …). Logs go to stderr.

CI publishes `ghcr.io/jackie-d/orbit-app` and `ghcr.io/jackie-d/orbit-nginx` on pushes to `main`
and on `v*` tags.

## Kubernetes / Helm

The chart lives in [`helm/orbit`](helm/orbit); see its [README](helm/orbit/README.md) for every value.

```bash
# Local (kind): build images, create the cluster, install with in-chart Postgres/Redis, run helm test
make kind-up
make kind-port-forward     # then, in another shell:
make smoke

# Production: managed Postgres/Redis, S3 photos, pre-created secret, autoscaling, TLS ingress
helm upgrade --install orbit helm/orbit -n orbit -f helm/orbit/values-prod.yaml \
  --set image.app.tag=v0.1.0 --set image.nginx.tag=v0.1.0
```

What you get:

- A **web** Deployment: nginx and php-fpm in the same pod, with a readiness probe that checks the
  DB and Redis, an HPA and a PDB.
- A **worker** Deployment (queue) and a single-replica **scheduler**.
- A **migration Job** that runs as a Helm hook after install and before every upgrade.
- An `APP_KEY` that is generated once and kept across upgrades, or supplied through an existing Secret.
- Config checksums on the pods, so changing configuration rolls them.
- Pods that meet the **Pod Security "restricted"** profile: non-root, read-only root filesystem,
  no capabilities.

## Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_KEY` | – | Encryption key (`php artisan key:generate --show`) |
| `APP_URL` | `http://localhost:8080` | Public base URL (used for photo URLs on the `public` disk) |
| `DB_*` | pgsql / orbit | PostgreSQL connection |
| `REDIS_*`, `CACHE_STORE`, `QUEUE_CONNECTION` | redis | Cache and queue |
| `ORBIT_PHOTO_DISK` | `public` | `public` (local volume) or `s3` |
| `AWS_*` | – | S3 bucket and credentials when `ORBIT_PHOTO_DISK=s3` |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:3000` | Comma-separated origins allowed to call the API |
| `ORBIT_PRUNE_AFTER_DAYS` | `30` | Retention of soft-deleted records |
| `ORBIT_EXPORT_RATE_LIMIT` | `60` | Export requests per minute per token |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stderr` / `debug` | Logging |

## Development

```bash
make test        # PHPUnit (sqlite in memory); CI also runs it against PostgreSQL
make lint        # Laravel Pint
make helm-lint   # chart lint with dev and prod values
```

CI (`.github/workflows/ci.yml`) runs:

- the tests on sqlite and PostgreSQL, plus Pint;
- `helm lint` and kubeconform validation of the chart;
- Docker builds of both images;
- a full **end-to-end run on kind**: install the chart, `helm test`, the smoke test through the
  Service, and an upgrade.
