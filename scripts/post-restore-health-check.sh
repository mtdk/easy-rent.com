#!/usr/bin/env bash
set -euo pipefail

# 用法:
#   ./scripts/post-restore-health-check.sh [base_url] [db_user] [db_password] [db_name] [db_host] [db_port] [--dry-run] [--skip-http] [--skip-db]
#
# 示例:
#   ./scripts/post-restore-health-check.sh http://127.0.0.1:8080 xmtdk 12345678 easy_rent 127.0.0.1 3306
#   ./scripts/post-restore-health-check.sh "" xmtdk 12345678 easy_rent 127.0.0.1 3306 --dry-run

MODE="commit"
SKIP_HTTP="0"
SKIP_DB="0"
POSITIONALS=()

for arg in "$@"; do
  case "$arg" in
    --dry-run)
      MODE="dry-run"
      ;;
    --skip-http)
      SKIP_HTTP="1"
      ;;
    --skip-db)
      SKIP_DB="1"
      ;;
    *)
      POSITIONALS+=("$arg")
      ;;
  esac
done

BASE_URL="${POSITIONALS[0]:-http://127.0.0.1:8080}"
DB_USER="${POSITIONALS[1]:-xmtdk}"
DB_PASSWORD="${POSITIONALS[2]:-}"
DB_NAME="${POSITIONALS[3]:-easy_rent}"
DB_HOST="${POSITIONALS[4]:-127.0.0.1}"
DB_PORT="${POSITIONALS[5]:-3306}"

if ! command -v curl >/dev/null 2>&1; then
  echo "错误: 未找到 curl 命令" >&2
  exit 1
fi

if [[ "$SKIP_DB" != "1" ]] && ! command -v mysql >/dev/null 2>&1; then
  echo "错误: 未找到 mysql 命令" >&2
  exit 1
fi

echo "========================================"
echo "恢复后健康检查"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "站点: $BASE_URL"
echo "数据库: $DB_NAME@$DB_HOST:$DB_PORT"
echo "========================================"

if [[ "$MODE" == "dry-run" ]]; then
  echo "[dry-run] 将检查 HTTP 端点:"
  echo "- $BASE_URL/auth/login (期望 200)"
  echo "- $BASE_URL/dashboard (期望 200/302/401)"
  echo "- $BASE_URL/reports/financial (期望 200/302/401)"
  echo "- $BASE_URL/notifications (期望 200/302/401)"
  echo "[dry-run] 将检查 DB 连接: SELECT 1"
  echo "[dry-run] 未执行真实网络/数据库请求"
  exit 0
fi

check_http() {
  local path="$1"
  local expected="$2"
  local url="${BASE_URL}${path}"
  local code

  code="$(curl -s -o /dev/null -w '%{http_code}' "$url" || true)"
  if [[ -z "$code" ]]; then
    echo "[HTTP][FAIL] $path -> 无响应"
    return 1
  fi

  if [[ ",$expected," == *",$code,"* ]]; then
    echo "[HTTP][OK] $path -> $code"
    return 0
  fi

  echo "[HTTP][FAIL] $path -> $code (期望: $expected)"
  return 1
}

fail_count=0

if [[ "$SKIP_HTTP" != "1" ]]; then
  check_http "/auth/login" "200" || fail_count=$((fail_count + 1))
  check_http "/dashboard" "200,302,401" || fail_count=$((fail_count + 1))
  check_http "/reports/financial" "200,302,401" || fail_count=$((fail_count + 1))
  check_http "/notifications" "200,302,401" || fail_count=$((fail_count + 1))
else
  echo "[HTTP][SKIP] 已按参数跳过 HTTP 检查"
fi

if [[ "$SKIP_DB" != "1" ]]; then
  if mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --password="$DB_PASSWORD" "$DB_NAME" -Nse "SELECT 1" >/dev/null 2>&1; then
    echo "[DB][OK] 连接与基础查询正常"
  else
    echo "[DB][FAIL] 无法连接数据库或执行查询"
    fail_count=$((fail_count + 1))
  fi
else
  echo "[DB][SKIP] 已按参数跳过数据库检查"
fi

echo "----------------------------------------"
if [[ "$fail_count" -gt 0 ]]; then
  echo "健康检查未通过，失败项: $fail_count"
  exit 1
fi

echo "健康检查通过"
