#!/bin/sh
# Probe PHP-FPM's ping endpoint over FastCGI (used by Kubernetes / compose healthchecks).
set -eu

response=$(
  SCRIPT_NAME=/fpm-ping \
  SCRIPT_FILENAME=/fpm-ping \
  REQUEST_METHOD=GET \
  cgi-fcgi -bind -connect "${FPM_HEALTHCHECK_ADDRESS:-127.0.0.1:9000}" 2>/dev/null
)

case "$response" in
  *pong*) exit 0 ;;
  *) echo "php-fpm ping failed" >&2; exit 1 ;;
esac
