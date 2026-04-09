#!/usr/bin/env bash
set -euo pipefail

# API 访问日志保留策略总控
#
# 用法:
#   ./scripts/run-api-access-log-retention.sh [mode] [retention_days] [archive_dir]
#
# 参数:
#   mode: preview | commit (默认 preview)
#   retention_days: 保留天数（默认 90）
#   archive_dir: 归档目录（默认 storage/logs/api-access-archives）

MODE="${1:-preview}"
RETENTION_DAYS="${2:-90}"
ARCHIVE_DIR="${3:-storage/logs/api-access-archives}"
ALERT_DIR="${ALERT_DIR:-storage/logs/alerts}"
ALERT_WEBHOOK_URL="${ALERT_WEBHOOK_URL:-}"
CURRENT_STAGE="初始化"
START_EPOCH="$(date +%s)"
ARCHIVE_FILE=""
ARCHIVE_SIZE_BYTES="0"
ARCHIVE_SHA256=""
ALERT_FILE=""

LOG_DIR="storage/logs"
mkdir -p "$LOG_DIR"
TS="$(date +%Y%m%d_%H%M%S)"
LOG_FILE="$LOG_DIR/api-access-retention_${MODE}_${TS}.log"
REPORT_FILE="$LOG_DIR/api-access-retention_${MODE}_${TS}.json"
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

resolve_abs_path() {
  local path="$1"
  if [[ "$path" == /* ]]; then
    printf '%s' "$path"
  else
    printf '%s' "$PWD/${path#./}"
  fi
}

find_latest_archive_file() {
  local abs_dir
  abs_dir="$(resolve_abs_path "$ARCHIVE_DIR")"
  ls -1t "$abs_dir"/api-access-logs_*.csv 2>/dev/null | head -n 1 || true
}

collect_archive_metadata() {
  local file_path
  file_path="$(find_latest_archive_file)"
  if [[ -z "$file_path" || ! -f "$file_path" ]]; then
    return 0
  fi

  ARCHIVE_FILE="$file_path"
  ARCHIVE_SIZE_BYTES="$(wc -c < "$file_path" | tr -d ' ')"
  ARCHIVE_SHA256="$(shasum -a 256 "$file_path" | awk '{print $1}')"
}

emit_alert() {
  local status="$1"
  local failed_stage="${2:-}"
  local exit_code="${3:-0}"
  local message="${4:-}"

  ALERT_FILE="$ALERT_DIR/api-access-retention-alert_${status}_${TS}.json"
  cat > "$ALERT_FILE" <<EOF
{
  "task": "api-access-retention",
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
  local cutoff
  cutoff="$(date -v-"${RETENTION_DAYS}"d '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -d "-${RETENTION_DAYS} days" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '-')"

  cat > "$REPORT_FILE" <<EOF
{
  "mode": "$(json_escape "$MODE")",
  "status": "$(json_escape "$status")",
  "timestamp": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "duration_seconds": $duration,
  "retention_days": $RETENTION_DAYS,
  "cutoff": "$(json_escape "$cutoff")",
  "archive_dir": "$(json_escape "$ARCHIVE_DIR")",
  "archive_file": "$(json_escape "$ARCHIVE_FILE")",
  "archive_size_bytes": $ARCHIVE_SIZE_BYTES,
  "archive_sha256": "$(json_escape "$ARCHIVE_SHA256")",
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
  emit_alert "failed" "$CURRENT_STAGE" "$exit_code" "API 审计日志保留任务执行失败"
  write_report "failed" "$CURRENT_STAGE" "$exit_code"
  echo "----------------------------------------"
  echo "API 审计日志保留任务失败"
  echo "- 失败阶段: $CURRENT_STAGE"
  echo "- 退出码: $exit_code"
  echo "- 日志文件: $LOG_FILE"
  echo "- 报告文件: $REPORT_FILE"
  echo "- 告警文件: $ALERT_FILE"
  echo "建议操作:"
  echo "1) 先运行 preview 模式确认待清理数量和截止时间"
  echo "2) 若失败在执行阶段，优先检查数据库连接与写入权限"
  echo "3) 若失败在归档阶段，检查目录可写性: $ARCHIVE_DIR"
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
echo "API 审计日志保留任务"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "保留天数: $RETENTION_DAYS"
echo "归档目录: $ARCHIVE_DIR"
echo "日志文件: $LOG_FILE"
echo "报告文件: $REPORT_FILE"
echo "========================================"

CURRENT_STAGE="执行保留策略"
php ./scripts/manage-api-access-logs.php "$MODE" "$RETENTION_DAYS" "$ARCHIVE_DIR"

if [[ "$MODE" == "commit" ]]; then
  CURRENT_STAGE="收集归档元数据"
  collect_archive_metadata
fi

CURRENT_STAGE="完成"

write_report "success" "" "0"
echo "API 审计日志保留任务执行完成"
echo "日志文件: $LOG_FILE"
echo "报告文件: $REPORT_FILE"
