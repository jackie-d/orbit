#!/usr/bin/env bash
# End-to-end smoke test of a running Orbit deployment (docker compose or Kubernetes).
#
#   scripts/smoke-test.sh                          # http://localhost:8080
#   BASE_URL=http://orbit.localtest.me scripts/smoke-test.sh
#   SMOKE_PHOTO=0 scripts/smoke-test.sh            # skip the photo upload step
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
API="$BASE_URL/api/v1"
SMOKE_PHOTO="${SMOKE_PHOTO:-1}"

step() { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
fail() { printf '  \033[31m✗ %s\033[0m\n' "$*"; exit 1; }
json() { php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$d=$d[$k]??null;} echo is_array($d)?json_encode($d):$d;' "$1"; }

call() { # call METHOD PATH [curl args...] -> prints body, fails on HTTP >= 400
  local method=$1 path=$2; shift 2
  local out code
  out=$(curl -sS -X "$method" "$API$path" -H 'Accept: application/json' -w '\n%{http_code}' "$@")
  code=${out##*$'\n'}
  body=${out%$'\n'*}
  [[ $code -lt 400 ]] || fail "$method $path -> HTTP $code: $body"
  printf '%s' "$body"
}

step "Health"
curl -fsS "$BASE_URL/up" >/dev/null && ok "/up (liveness)"
ready=$(call GET /health/ready)
[[ $(json status <<<"$ready") == ok ]] || fail "not ready: $ready"
ok "/api/v1/health/ready -> $(json checks <<<"$ready")"

step "Register a user"
email="smoke+$(date +%s)$RANDOM@orbit.test"
res=$(call POST /auth/register -H 'Content-Type: application/json' \
  -d "{\"name\":\"Smoke Test\",\"email\":\"$email\",\"password\":\"smoke-password\",\"password_confirmation\":\"smoke-password\"}")
TOKEN=$(json access_token <<<"$res")
AUTH=(-H "Authorization: Bearer $TOKEN")
ok "registered $email"

step "Create contacts"
anna=$(call POST /contacts "${AUTH[@]}" -H 'Content-Type: application/json' -d '{
  "first_name":"Anna","last_name":"Rossi","company":"Acme","is_favorite":true,
  "phone_numbers":[{"label":"mobile","number":"+39 333 1234567"}],
  "emails":[{"label":"work","email":"anna@acme.test"}],
  "urls":[{"label":"linkedin","url":"https://linkedin.com/in/anna"}]}')
ANNA=$(json data.id <<<"$anna")
luca=$(call POST /contacts "${AUTH[@]}" -H 'Content-Type: application/json' -d '{"first_name":"Luca","last_name":"Bianchi"}')
LUCA=$(json data.id <<<"$luca")
ok "Anna (#$ANNA, $(json data.phone_numbers.0.number <<<"$anna")), Luca (#$LUCA)"

if [[ $SMOKE_PHOTO == 1 ]]; then
  step "Upload a photo (processed by the queue worker)"
  tmp=$(mktemp --suffix=.png)
  php -r '$i=imagecreatetruecolor(1600,900); imagefill($i,0,0,imagecolorallocate($i,40,120,200)); imagepng($i,$argv[1]);' "$tmp" 2>/dev/null \
    || printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC' | base64 -d >"$tmp"
  call POST "/contacts/$ANNA/photo" "${AUTH[@]}" -F "photo=@$tmp" >/dev/null
  rm -f "$tmp"
  for _ in $(seq 1 20); do
    thumb=$(json data.photo_thumb_url <<<"$(call GET "/contacts/$ANNA" "${AUTH[@]}")")
    [[ -n $thumb ]] && break
    sleep 1
  done
  [[ -n $thumb ]] || fail "thumbnail was not generated (is the worker running?)"
  curl -fsS -o /dev/null "$thumb" && ok "thumbnail served: $thumb"
fi

step "Log interactions"
for when in 2026-05-02T09:00:00+02:00 2026-05-16T09:30:00+02:00 2026-06-01T08:45:00+02:00; do
  call POST /interactions "${AUTH[@]}" -H 'Content-Type: application/json' -d "{
    \"occurred_at\":\"$when\",\"title\":\"Coffee\",\"mood\":\"good\",\"outcome\":\"positive\",
    \"note\":\"Caught up about work\",\"location_name\":\"Milano\",\"latitude\":45.4642,\"longitude\":9.19,
    \"contacts\":[{\"id\":$ANNA,\"role\":\"met\"}],
    \"links\":[{\"type\":\"shop\",\"label\":\"Blue Bottle\",\"url\":\"https://bluebottlecoffee.com\",\"note\":\"Great flat white\"},
               {\"type\":\"person\",\"linked_contact_id\":$LUCA,\"note\":\"Anna mentioned Luca\"}]}" >/dev/null
done
timeline=$(call GET "/contacts/$ANNA/interactions" "${AUTH[@]}")
ok "timeline of Anna: $(json meta.total <<<"$timeline") interactions"

step "Mint a third-party token (links:read)"
tok=$(call POST /auth/tokens "${AUTH[@]}" -H 'Content-Type: application/json' -d '{"name":"smoke-analytics","abilities":["links:read"]}')
LINKS=(-H "Authorization: Bearer $(json access_token <<<"$tok")")
code=$(curl -s -o /dev/null -w '%{http_code}' "$API/contacts" -H 'Accept: application/json' "${LINKS[@]}")
[[ $code == 403 ]] || fail "links:read token should not read contacts (got $code)"
ok "scoped token cannot read contacts (403)"

step "Export interaction links"
page=$(call GET "/exports/interaction-links?per_page=2" "${LINKS[@]}")
ok "JSON page: $(json data <<<"$page" | php -r 'echo count(json_decode(stream_get_contents(STDIN)));') rows, next_cursor=$(json meta.next_cursor <<<"$page" | cut -c1-16)…"
csv_rows=$(( $(curl -fsS "$API/exports/interaction-links?format=csv" "${LINKS[@]}" | wc -l) - 1 ))
[[ $csv_rows == 6 ]] || fail "expected 6 CSV rows, got $csv_rows"
ok "CSV: $csv_rows rows"
ndjson_rows=$(curl -fsS "$API/exports/interaction-links?format=ndjson&type=shop" "${LINKS[@]}" | wc -l)
ok "NDJSON (type=shop): $ndjson_rows rows"

step "Recurrence analysis"
rec=$(call GET "/exports/interaction-links/recurrence" "${LINKS[@]}")
top=$(json data.0 <<<"$rec")
[[ $(json occurrences <<<"$top") == 3 ]] || fail "unexpected recurrence: $top"
ok "top target $(json key <<<"$top"): $(json occurrences <<<"$top") occurrences, every $(json avg_days_between <<<"$top") days"

step "Stats"
ok "$(json data <<<"$(call GET /stats/overview "${AUTH[@]}")" | cut -c1-160)…"

printf '\n\033[1;32mAll smoke tests passed against %s\033[0m\n' "$BASE_URL"
