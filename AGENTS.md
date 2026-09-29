# Orbit — notes for AI coding agents

Orbit is an **API-only** Laravel 13 / PHP 8.4 app (contacts + real-life interactions), shipped as
Docker images (nginx + PHP-FPM) and deployed to Kubernetes with the Helm chart in `helm/orbit`.

## Commands

- `php artisan test` — PHPUnit feature tests (sqlite in memory). Must stay green.
- `vendor/bin/pint` — code style (run before committing; CI runs `pint --test`).
- `make help` — compose, image build, Helm and kind targets.
- `scripts/smoke-test.sh` — end-to-end check against a running deployment (`BASE_URL=...`).

## Layout and conventions

- Routes: `routes/api.php`, prefixed with `/api/v1` (set in `bootstrap/app.php`). Every API response is JSON
  (`ForceJsonResponse` middleware).
- Controllers are thin (`app/Http/Controllers/Api/V1`). Validation lives in FormRequests
  (`app/Http/Requests`), output in API Resources (`app/Http/Resources`), write logic in services
  (`app/Services`).
- Models use PHP attributes (`#[Fillable]`, `#[Hidden]`) and a `casts()` method, like the rest of the codebase.
  `Model::shouldBeStrict()` is on outside production, so eager-load relations.
- Ownership: `{contact}` and `{interaction}` route bindings are scoped to the authenticated user in
  `AppServiceProvider` (foreign ids give 404). Validate contact ids with `Rule::exists(...)->where('user_id', ...)`.
- Token abilities: `App\Support\TokenAbility`. Login tokens are `*`. Third-party tokens get `links:read`, which only
  opens `/exports/interaction-links*`.
- Timestamps are stored in UTC. `Interaction::occurred_at` normalizes incoming offsets.
- Enums are in `app/Enums` (Mood, Outcome, LinkType).
- Background work: `ProcessContactPhoto` (queue), `orbit:prune-deleted` (scheduler, `routes/console.php`).

## Containers and Kubernetes

- `Dockerfile` targets: `app` (php-fpm, also used by the worker, scheduler and migrate) and `nginx`.
  `docker/php/entrypoint.sh` caches config, routes and events at start. There are no Blade views.
- The PHP-FPM pool uses `clear_env = no` so the pod's environment reaches Laravel.
- The Helm chart must pass `helm lint --strict` with `values-dev.yaml` and `values-prod.yaml`, and it must keep
  the Pod Security "restricted" profile (non-root, read-only root filesystem: write only to the mounted
  `storage/`, `bootstrap/cache` and `/tmp`).
- New environment variables go in `.env.example`, `docker-compose.yml` and `helm/orbit/templates/configmap.yaml`
  (or `secret.yaml` for secrets).
