#!/usr/bin/env bash
set -euo pipefail

# 用法:
#   ./scripts/run-migrations.sh status
#   ./scripts/run-migrations.sh up --step=1
#   ./scripts/run-migrations.sh down --step=1

php ./scripts/migrate-easy-rent.php "$@"
