#!/usr/bin/env bash
set -euo pipefail

# 统一运维巡检脚本
# 用法:
#   ./scripts/run-ops-health-check.sh [mode] [base_url] [db_user] [db_password] [db_name] [db_host] [db_port] [max_report_age_hours]
# 示例:
#   ./scripts/run-ops-health-check.sh preview
#   ./scripts/run-ops-health-check.sh commit http://127.0.0.1:8080 xmtdk 12345678 easy_rent 127.0.0.1 3306 24

MODE="${1:-preview}"
BASE_URL="${2:-http://127.0.0.1:8080}"
DB_USER="${3:-}"
DB_PASSWORD="${4:-}"
DB_NAME="${5:-easy_rent}"
DB_HOST="${6:-127.0.0.1}"
DB_PORT="${7:-3306}"
MAX_REPORT_AGE_HOURS="${8:-24}"

if [[ "$MODE" != "preview" && "$MODE" != "commit" ]]; then
  echo "错误: mode 仅支持 preview 或 commit" >&2
  exit 1
fi

LOG_DIR="storage/logs"
mkdir -p "$LOG_DIR"
TS="$(date +%Y%m%d_%H%M%S)"
LOG_FILE="$LOG_DIR/ops-health-check_${MODE}_${TS}.log"
REPORT_FILE="$LOG_DIR/ops-health-check_${MODE}_${TS}.json"

exec > >(tee -a "$LOG_FILE") 2>&1

json_escape() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="${s//$'\n'/\\n}"
  s="${s//$'\r'/\\r}"
  s="${s//$'\t'/\\t}"
  printf '%s' "$s"
}

check_ok_count=0
check_fail_count=0
http_status_summary=""
latest_nightly_report=""
latest_nightly_report_age_hours=-1

mark_ok() {
  local msg="$1"
  check_ok_count=$((check_ok_count + 1))
  echo "[OK] $msg"
}

mark_fail() {
  local msg="$1"
  check_fail_count=$((check_fail_count + 1))
  echo "[FAIL] $msg"
}

check_http() {
  local path="$1"
  local expected_csv="$2"

  if ! command -v curl >/dev/null 2>&1; then
    mark_fail "curl 不存在，无法检查 $path"
    return
  fi

  local code
  code="$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}${path}" || true)"
  if [[ -z "$code" ]]; then
    mark_fail "HTTP 无响应: $path"
    return
  fi

  if [[ ",${expected_csv}," == *",${code},"* ]]; then
    mark_ok "HTTP ${path} => ${code}"
  else
    mark_fail "HTTP ${path} => ${code} (期望 ${expected_csv})"
  fi

  if [[ -n "$http_status_summary" ]]; then
    http_status_summary+=";"
  fi
  http_status_summary+="${path}:${code}"
}

check_latest_nightly_report() {
  latest_nightly_report="$(ls -1t storage/logs/nightly-maintenance_*.json 2>/dev/null | head -n 1 || true)"
  if [[ -z "$latest_nightly_report" ]]; then
    mark_fail "未找到 nightly-maintenance 报告"
    return
  fi

  local now_epoch file_epoch
  now_epoch="$(date +%s)"

  if stat -f "%m" "$latest_nightly_report" >/dev/null 2>&1; then
    file_epoch="$(stat -f "%m" "$latest_nightly_report")"
  else
    file_epoch="$(date +%s)"
  fi

  latest_nightly_report_age_hours=$(( (now_epoch - file_epoch) / 3600 ))
  if (( latest_nightly_report_age_hours <= MAX_REPORT_AGE_HOURS )); then
    mark_ok "夜间总控报告新鲜度满足阈值(${latest_nightly_report_age_hours}h <= ${MAX_REPORT_AGE_HOURS}h)"
  else
    mark_fail "夜间总控报告过旧(${latest_nightly_report_age_hours}h > ${MAX_REPORT_AGE_HOURS}h)"
  fi
}

check_storage_paths() {
  if [[ -d "storage/logs" && -w "storage/logs" ]]; then
    mark_ok "storage/logs 可写"
  else
    mark_fail "storage/logs 不可写"
  fi

  if [[ -d "storage/backups" && -w "storage/backups" ]]; then
    mark_ok "storage/backups 可写"
  else
    mark_fail "storage/backups 不可写"
  fi
}

check_db_ping() {
  if [[ -z "$DB_USER" || -z "$DB_PASSWORD" ]]; then
    mark_fail "数据库凭据缺失，跳过 DB 检查"
    return
  fi

  if ! command -v mysql >/dev/null 2>&1; then
    mark_fail "mysql 命令不存在，无法执行 DB 检查"
    return
  fi

  if mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --password="$DB_PASSWORD" "$DB_NAME" -Nse "SELECT 1" >/dev/null 2>&1; then
    mark_ok "数据库连接与 SELECT 1 正常"
  else
    mark_fail "数据库连接或查询失败"
  fi
}

write_report() {
  local status="$1"
  cat > "$REPORT_FILE" <<EOF
{
  "mode": "$(json_escape "$MODE")",
  "status": "$(json_escape "$status")",
  "timestamp": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "base_url": "$(json_escape "$BASE_URL")",
  "ok_count": $check_ok_count,
  "fail_count": $check_fail_count,
  "max_report_age_hours": $MAX_REPORT_AGE_HOURS,
  "latest_nightly_report": "$(json_escape "$latest_nightly_report")",
  "latest_nightly_report_age_hours": $latest_nightly_report_age_hours,
  "http_status": "$(json_escape "$http_status_summary")",
  "log_file": "$(json_escape "$LOG_FILE")",
  "report_file": "$(json_escape "$REPORT_FILE")"
}
EOF
}

echo "========================================"
echo "运维巡检总控"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "BASE_URL: $BASE_URL"
echo "报告阈值: ${MAX_REPORT_AGE_HOURS}h"
echo "========================================"

if [[ "$MODE" == "preview" ]]; then
  echo "[preview] 将检查 HTTP: /auth/login /dashboard /reports/financial /notifications"
  echo "[preview] 将检查 storage 路径可写性与 nightly-maintenance 报告新鲜度"
  echo "[preview] 若提供 DB 凭据，将检查数据库连接"
  write_report "preview"
  echo "报告文件: $REPORT_FILE"
  exit 0
fi

check_http "/auth/login" "200"
check_http "/dashboard" "200,302,401"
check_http "/reports/financial" "200,302,401"
check_http "/notifications" "200,302,401"
check_storage_paths
check_latest_nightly_report
check_db_ping

echo "----------------------------------------"
if (( check_fail_count > 0 )); then
  write_report "failed"
  echo "巡检失败: ${check_fail_count} 项"
  echo "报告文件: $REPORT_FILE"
  exit 1
fi

write_report "success"
echo "巡检通过: ${check_ok_count} 项"
echo "报告文件: $REPORT_FILE"
