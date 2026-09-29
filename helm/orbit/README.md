# Orbit Helm chart

Deploys the Orbit API on Kubernetes:

| Component | Kind | Notes |
|-----------|------|-------|
| `web` | Deployment (+ HPA, PDB) | Pod with **nginx** (`:8080`) + **php-fpm** (`127.0.0.1:9000`) containers |
| `worker` | Deployment (+ HPA) | `php artisan queue:work redis` — processes contact photos |
| `scheduler` | Deployment (1 replica, `Recreate`) | `php artisan schedule:work` — pruning, token cleanup |
| `migrate` | Job (Helm hook) | `orbit:wait-for-db` then `migrate --force --isolated`; runs **post-install** and **pre-upgrade** |
| `postgresql` / `redis` | StatefulSet / Deployment | Optional, **development only** |
| photos | PVC or S3 | `app.photoDisk=public` (PVC) or `s3` |

All pods run as non-root with a read-only root filesystem, drop all capabilities and
satisfy the Kubernetes **Pod Security "restricted"** profile.

## Probes

- **php-fpm**: startup + liveness via FastCGI ping (`fpm-healthcheck`).
- **nginx**: liveness on `/nginx-health` (nginx only).
- **readiness**: `/api/v1/health/ready` — goes through PHP and checks Postgres and Redis,
  so a pod only receives traffic when its backing services are reachable.

## Quick start (local cluster)

```bash
# builds orbit-app:dev / orbit-nginx:dev, creates a kind cluster, installs the chart
make kind-up
make kind-port-forward        # http://localhost:8080 -> svc/orbit
curl http://localhost:8080/api/v1/health/ready
```

Manually, on any cluster that can see the images:

```bash
helm upgrade --install orbit helm/orbit -n orbit --create-namespace -f helm/orbit/values-dev.yaml
helm test orbit -n orbit
```

## Production

Use managed Postgres/Redis, object storage for photos and a pre-created Secret —
see [`values-prod.yaml`](values-prod.yaml):

```bash
kubectl -n orbit create secret generic orbit-secrets \
  --from-literal=APP_KEY="base64:$(openssl rand -base64 32)" \
  --from-literal=DB_PASSWORD=... \
  --from-literal=REDIS_PASSWORD=... \
  --from-literal=AWS_ACCESS_KEY_ID=... \
  --from-literal=AWS_SECRET_ACCESS_KEY=...

helm upgrade --install orbit helm/orbit -n orbit -f helm/orbit/values-prod.yaml \
  --set image.app.tag=v0.1.0 --set image.nginx.tag=v0.1.0
```

## Key values

| Value | Default | Description |
|-------|---------|-------------|
| `image.app.repository` / `image.nginx.repository` | `ghcr.io/jackie-d/orbit-{app,nginx}` | Images built from the repo `Dockerfile` (`--target app` / `--target nginx`) |
| `image.*.tag` | `.Chart.AppVersion` | Image tags |
| `app.key` | generated | `APP_KEY`; generated on install and kept across upgrades |
| `app.url` | first ingress host | `APP_URL` |
| `app.corsAllowedOrigins` | `""` | Origins allowed to call the API (e.g. the Next.js app) |
| `app.photoDisk` | `public` | `public` (PVC mounted in web + worker) or `s3` |
| `existingSecret` | `""` | Secret with `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `AWS_*` |
| `web.replicaCount` | `2` | Web replicas (ignored when `web.autoscaling.enabled`) |
| `web.fpm.*` | see values | PHP-FPM pool sizing |
| `worker.enabled` / `worker.queues` | `true` / `default` | Queue worker |
| `scheduler.enabled` | `true` | Laravel scheduler |
| `migrations.enabled` | `true` | Migration hook Job |
| `externalDatabase.*` | | Host, port, database, username, password, sslmode |
| `externalRedis.*` | | Host, port, password |
| `postgresql.enabled` / `redis.enabled` | `false` | In-chart dev services |
| `persistence.*` | `5Gi`, RWO | Photos volume (`app.photoDisk=public`) |
| `s3.*` | | Bucket, region, endpoint, credentials (`app.photoDisk=s3`) |
| `ingress.*` | disabled | Ingress class, hosts, TLS, annotations |

> **ReadWriteOnce photos volume:** fine on single-node clusters (kind, minikube).
> On multi-node clusters use `app.photoDisk=s3` or a `ReadWriteMany` storage class.
