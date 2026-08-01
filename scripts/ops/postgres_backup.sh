#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
database="${POSTGRES_DATABASE:-zrodlo_slowa}"
database_user="${POSTGRES_DATABASE_USER:-zrodlo}"
output="${1:-${repo_root}/backups/postgres-$(date -u +%Y%m%d-%H%M%S).dump}"

mkdir -p "$(dirname "${output}")"
cd "${repo_root}"
docker compose exec -T postgres \
  pg_dump --format=custom --no-owner --no-acl \
  --username="${database_user}" --dbname="${database}" > "${output}"

test -s "${output}"
printf '%s\n' "${output}"
