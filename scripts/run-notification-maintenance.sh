#!/usr/bin/env bash
set -euo pipefail

# 通知维护总控
#
# 用法:
#   ./scripts/run-notification-maintenance.sh [mode] [reminder_days] [cleanup_days]
#
# 参数:
#   mode: preview | commit (默认 preview)
#   reminder_days: 合同到期提醒阈值天数（默认 30）
#   cleanup_days: 已读通知保留天数（默认 30）

MODE="${1:-preview}"
REMINDER_DAYS="${2:-30}"
CLEANUP_DAYS="${3:-30}"
ALERT_DIR="${ALERT_DIR:-storage/logs/alerts}"
ALERT_WEBHOOK_URL="${ALERT_WEBHOOK_URL:-}"
CURRENT_STAGE="初始化"
START_EPOCH="$(date +%s)"
ALERT_FILE=""

LOG_DIR="storage/logs"
mkdir -p "$LOG_DIR"
TS="$(date +%Y%m%d_%H%M%S)"
LOG_FILE="$LOG_DIR/notification-maintenance_${MODE}_${TS}.log"
REPORT_FILE="$LOG_DIR/notification-maintenance_${MODE}_${TS}.json"
mkdir -p "$ALERT_DIR"

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

emit_alert() {
  local status="$1"
  local failed_stage="${2:-}"
  local exit_code="${3:-0}"
  local message="${4:-}"

  ALERT_FILE="$ALERT_DIR/notification-maintenance-alert_${status}_${TS}.json"
  cat > "$ALERT_FILE" <<EOF
{
  "task": "notification-maintenance",
  "mode": "$(json_escape "$MODE")",
  "status": "$(json_escape "$status")",
  "timestamp": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "failed_stage": "$(json_escape "$failed_stage")",
  "exit_code": $exit_code,
  "message": "$(json_escape "$message")",
  "log_file": "$(json_escape "$LOG_FILE")",
  "report_file": "$(json_escape "$REPORT_FILE")"
}
EOF

  if [[ -n "$ALERT_WEBHOOK_URL" ]]; then
    if command -v curl >/dev/null 2>&1; then
      if ! curl -fsS --max-time 10 -H "Content-Type: application/json" --data-binary "@$ALERT_FILE" "$ALERT_WEBHOOK_URL" >/dev/null; then
        echo "警告: webhook 告警推送失败: $ALERT_WEBHOOK_URL" >&2
      fi
    else
      echo "警告: 未找到 curl，跳过 webhook 告警推送" >&2
    fi
  fi
}

fail_and_exit() {
  local message="$1"
  local exit_code="${2:-1}"
  emit_alert "failed" "$CURRENT_STAGE" "$exit_code" "$message"
  write_report "failed" "$CURRENT_STAGE" "$exit_code"
  echo "错误: $message" >&2
  echo "告警文件: $ALERT_FILE" >&2
  echo "报告文件: $REPORT_FILE" >&2
  exit "$exit_code"
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
  "reminder_days": $REMINDER_DAYS,
  "cleanup_days": $CLEANUP_DAYS,
  "alert": {
    "alert_file": "$(json_escape "$ALERT_FILE")",
    "webhook_enabled": $( [[ -n "$ALERT_WEBHOOK_URL" ]] && echo true || echo false )
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
  emit_alert "failed" "$CURRENT_STAGE" "$exit_code" "通知维护任务执行失败"
  write_report "failed" "$CURRENT_STAGE" "$exit_code"
  echo "----------------------------------------"
  echo "通知维护任务失败"
  echo "- 失败阶段: $CURRENT_STAGE"
  echo "- 退出码: $exit_code"
  echo "- 日志文件: $LOG_FILE"
  echo "- 报告文件: $REPORT_FILE"
  echo "- 告警文件: $ALERT_FILE"
  echo "建议操作:"
  echo "1) 先用 preview 模式确认扫描/清理数量"
  echo "2) 单独执行 generate-contract-reminders.php 与 cleanup-read-notifications.php 排查"
  exit "$exit_code"
}

trap on_error ERR

if [[ "$MODE" != "preview" && "$MODE" != "commit" ]]; then
  fail_and_exit "mode 必须是 preview 或 commit"
fi

if ! command -v php >/dev/null 2>&1; then
  fail_and_exit "未找到 php 命令"
fi

echo "========================================"
echo "通知维护任务"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "提醒阈值天数: $REMINDER_DAYS"
echo "已读保留天数: $CLEANUP_DAYS"
echo "日志文件: $LOG_FILE"
echo "报告文件: $REPORT_FILE"
echo "========================================"

if [[ "$MODE" == "preview" ]]; then
  CURRENT_STAGE="合同提醒预览"
  php scripts/generate-contract-reminders.php --days="$REMINDER_DAYS" --dry-run
  CURRENT_STAGE="已读清理预览"
  php scripts/cleanup-read-notifications.php --days="$CLEANUP_DAYS" --dry-run
else
  CURRENT_STAGE="合同提醒执行"
  php scripts/generate-contract-reminders.php --days="$REMINDER_DAYS"
  CURRENT_STAGE="已读清理执行"
  php scripts/cleanup-read-notifications.php --days="$CLEANUP_DAYS"
fi

CURRENT_STAGE="完成"
write_report "success" "" "0"
echo "通知维护任务执行完成"
echo "日志文件: $LOG_FILE"
echo "报告文件: $REPORT_FILE"
