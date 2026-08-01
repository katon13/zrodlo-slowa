#!/usr/bin/env bash
set -euo pipefail

if [[ "${2:-}" != "RESTORE_POSTGRESQL" ]]; then
  printf 'Odmowa: użycie: %s PLIK.dump RESTORE_POSTGRESQL\n' "$0" >&2
  exit 2
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
input="$(realpath "${1}")"
database="${POSTGRES_DATABASE:-zrodlo_slowa}"
database_user="${POSTGRES_DATABASE_USER:-zrodlo}"

test -s "${input}"
cd "${repo_root}"
docker compose exec -T postgres \
  pg_restore --clean --if-exists --no-owner --no-acl --exit-on-error \
  --username="${database_user}" --dbname="${database}" < "${input}"

printf 'Odtworzenie zakończone. Uruchom kontrolę: php scripts/reconcile_finances.php\n'
