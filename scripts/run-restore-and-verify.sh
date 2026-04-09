#!/usr/bin/env bash
set -euo pipefail

# 恢复+验证总控脚本
#
# 用法:
#   ./scripts/run-restore-and-verify.sh [mode] [backup_file] [base_url] [db_user] [db_password] [db_name] [db_host] [db_port]
#
# 参数:
#   mode: preview | commit (默认 preview)
#   backup_file: 备份文件路径（可为空，为空时自动使用最新备份）
#   base_url: 站点地址（默认 http://127.0.0.1:8080）

MODE="${1:-preview}"
BACKUP_FILE="${2:-}"
BASE_URL="${3:-http://127.0.0.1:8080}"
DB_USER="${4:-xmtdk}"
DB_PASSWORD="${5:-}"
DB_NAME="${6:-easy_rent}"
DB_HOST="${7:-127.0.0.1}"
DB_PORT="${8:-3306}"
CURRENT_STAGE="初始化"
START_EPOCH="$(date +%s)"

LOG_DIR="storage/logs"
mkdir -p "$LOG_DIR"
TS="$(date +%Y%m%d_%H%M%S)"
LOG_FILE="$LOG_DIR/restore-verify_${MODE}_${TS}.log"
REPORT_FILE="$LOG_DIR/restore-verify_${MODE}_${TS}.json"

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

write_report() {
  local status="$1"
  local failed_stage="${2:-}"
  local exit_code="${3:-0}"
  local end_epoch
  end_epoch="$(date +%s)"
  local duration
  duration="$((end_epoch - START_EPOCH))"

  cat > "$REPORT_FILE" <<EOF
{
  "mode": "$(json_escape "$MODE")",
  "status": "$(json_escape "$status")",
  "timestamp": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "duration_seconds": $duration,
  "backup_file": "$(json_escape "$BACKUP_FILE")",
  "base_url": "$(json_escape "$BASE_URL")",
  "database": {
    "name": "$(json_escape "$DB_NAME")",
    "host": "$(json_escape "$DB_HOST")",
    "port": "$(json_escape "$DB_PORT")"
  },
  "failed_stage": "$(json_escape "$failed_stage")",
  "exit_code": $exit_code,
  "log_file": "$(json_escape "$LOG_FILE")"
}
EOF
}

on_error() {
  local exit_code="$?"
  set +e
  write_report "failed" "$CURRENT_STAGE" "$exit_code"
  echo "----------------------------------------"
  echo "恢复与验证失败"
  echo "- 失败阶段: $CURRENT_STAGE"
  echo "- 退出码: $exit_code"
  echo "- 日志文件: $LOG_FILE"
  echo "- 报告文件: $REPORT_FILE"
  echo "建议操作:"
  echo "1) 先运行 preview 确认输入参数和目标备份文件"
  echo "2) 若失败在恢复阶段，优先核对数据库凭据/连通性"
  echo "3) 若失败在健康检查阶段，可单独执行:"
  echo "   ./scripts/post-restore-health-check.sh \"$BASE_URL\" \"$DB_USER\" <db_password> \"$DB_NAME\" \"$DB_HOST\" \"$DB_PORT\""
  exit "$exit_code"
}

trap on_error ERR

if [[ "$MODE" != "preview" && "$MODE" != "commit" ]]; then
  echo "错误: mode 必须是 preview 或 commit" >&2
  exit 1
fi

if [[ ! -x "scripts/restore-easy-rent.sh" ]]; then
  echo "错误: 未找到可执行脚本 scripts/restore-easy-rent.sh" >&2
  exit 1
fi

if [[ ! -x "scripts/post-restore-health-check.sh" ]]; then
  echo "错误: 未找到可执行脚本 scripts/post-restore-health-check.sh" >&2
  exit 1
fi

if [[ -z "$BACKUP_FILE" ]]; then
  LATEST_FILE="$(ls -1t storage/backups/*.sql 2>/dev/null | head -n 1 || true)"
  if [[ -z "$LATEST_FILE" ]]; then
    echo "错误: 未指定备份文件，且 storage/backups 下未找到可用 .sql 文件" >&2
    exit 1
  fi
  BACKUP_FILE="$LATEST_FILE"
fi

if [[ ! -f "$BACKUP_FILE" ]]; then
  echo "错误: 备份文件不存在: $BACKUP_FILE" >&2
  exit 1
fi

echo "========================================"
echo "恢复与验证总控"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "备份文件: $BACKUP_FILE"
echo "站点: $BASE_URL"
echo "数据库: $DB_NAME@$DB_HOST:$DB_PORT"
echo "日志文件: $LOG_FILE"
echo "报告文件: $REPORT_FILE"
echo "========================================"

if [[ "$MODE" == "preview" ]]; then
  CURRENT_STAGE="恢复预演"
  echo "[1/2] 恢复预演"
  ./scripts/restore-easy-rent.sh "$BACKUP_FILE" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT" --dry-run

  CURRENT_STAGE="健康检查预演"
  echo "[2/2] 健康检查预演"
  ./scripts/post-restore-health-check.sh "$BASE_URL" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT" --dry-run
else
  CURRENT_STAGE="执行恢复"
  echo "[1/2] 执行恢复"
  ./scripts/restore-easy-rent.sh "$BACKUP_FILE" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT" --force

  CURRENT_STAGE="执行健康检查"
  echo "[2/2] 执行健康检查"
  ./scripts/post-restore-health-check.sh "$BASE_URL" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT"
fi

CURRENT_STAGE="完成"
write_report "success" "" "0"
echo "恢复与验证总控执行完成"
echo "日志文件: $LOG_FILE"
echo "报告文件: $REPORT_FILE"
