#!/usr/bin/env bash
set -euo pipefail

# 夜间运维总控（通知维护 + API 审计日志保留）
#
# 用法:
#   ./scripts/run-nightly-maintenance.sh [mode] [reminder_days] [cleanup_days] [retention_days] [archive_dir] [retry_count] [retry_delay_seconds]
#
# 参数:
#   mode: preview | commit (默认 preview)
#   reminder_days: 通知提醒阈值（默认 30）
#   cleanup_days: 已读通知保留天数（默认 30）
#   retention_days: API 审计日志保留天数（默认 90）
#   archive_dir: API 审计日志归档目录（默认 storage/logs/api-access-archives）
#   retry_count: 失败重试次数（默认 0）
#   retry_delay_seconds: 每次重试间隔秒数（默认 3）

MODE="${1:-preview}"
REMINDER_DAYS="${2:-30}"
CLEANUP_DAYS="${3:-30}"
RETENTION_DAYS="${4:-90}"
ARCHIVE_DIR="${5:-storage/logs/api-access-archives}"
RETRY_COUNT="${6:-0}"
RETRY_DELAY_SECONDS="${7:-3}"
ALERT_DIR="${ALERT_DIR:-storage/logs/alerts}"
ALERT_WEBHOOK_URL="${ALERT_WEBHOOK_URL:-}"
RISK_EVALUATION_ENABLED="${RISK_EVALUATION_ENABLED:-true}"
RISK_TREND_WINDOW="${RISK_TREND_WINDOW:-10}"
RISK_FAIL_RATE_THRESHOLD="${RISK_FAIL_RATE_THRESHOLD:-0.30}"
RISK_ALERT_DIR="${RISK_ALERT_DIR:-$ALERT_DIR}"
RISK_WEBHOOK_URL="${RISK_ALERT_WEBHOOK_URL:-$ALERT_WEBHOOK_URL}"
RISK_WARNING_WEBHOOK_URL="${RISK_WARNING_WEBHOOK_URL:-}"
RISK_CRITICAL_WEBHOOK_URL="${RISK_CRITICAL_WEBHOOK_URL:-}"
RISK_NOTIFY_ON_INCREASE_ONLY="${RISK_NOTIFY_ON_INCREASE_ONLY:-true}"
RISK_NOTIFICATION_COOLDOWN_MINUTES="${RISK_NOTIFICATION_COOLDOWN_MINUTES:-60}"
RISK_NOTIFICATION_COOLDOWN_PREVIEW_MINUTES="${RISK_NOTIFICATION_COOLDOWN_PREVIEW_MINUTES:-$RISK_NOTIFICATION_COOLDOWN_MINUTES}"
RISK_NOTIFICATION_COOLDOWN_COMMIT_MINUTES="${RISK_NOTIFICATION_COOLDOWN_COMMIT_MINUTES:-$RISK_NOTIFICATION_COOLDOWN_MINUTES}"
RISK_SUPPRESSION_STATS_WINDOW="${RISK_SUPPRESSION_STATS_WINDOW:-30}"
RISK_NOTIFICATION_MESSAGE_ID_PREFIX="${RISK_NOTIFICATION_MESSAGE_ID_PREFIX:-risk}"
RISK_SUPPRESSION_RATIO_ALERT_ENABLED="${RISK_SUPPRESSION_RATIO_ALERT_ENABLED:-true}"
RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD="${RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD:-0.50}"
CURRENT_STAGE="初始化"
START_EPOCH="$(date +%s)"
SUB_NOTIFICATION_LOG=""
SUB_NOTIFICATION_REPORT=""
SUB_RETENTION_LOG=""
SUB_RETENTION_REPORT=""
NOTIFICATION_DURATION_SECONDS=0
RETENTION_DURATION_SECONDS=0
STAGE_LAST_DURATION_SECONDS=0
ALERT_FILE=""
ALERT_MESSAGE_ID=""
RISK_EVAL_EXECUTED="false"
RISK_EVAL_ERROR=""
RISK_API_LEVEL="unknown"
RISK_API_ALERT_FILE=""
RISK_API_THRESHOLD_BREACHED="false"
RISK_NIGHTLY_LEVEL="unknown"
RISK_NIGHTLY_ALERT_FILE=""
RISK_NIGHTLY_THRESHOLD_BREACHED="false"
RISK_OVERALL_LEVEL="unknown"
RISK_OVERALL_RANK=-1
RISK_PREVIOUS_OVERALL_LEVEL=""
RISK_PREVIOUS_OVERALL_RANK=-1
RISK_NOTIFICATION_WEBHOOK_TARGET=""
RISK_NOTIFICATION_WEBHOOK_PUSHED="false"
RISK_NOTIFICATION_SUPPRESSED="false"
RISK_NOTIFICATION_SUPPRESSED_REASON=""
RISK_NOTIFICATION_SUPPRESSED_REASON_CODE=""
RISK_NOTIFICATION_COOLDOWN_ACTIVE="false"
RISK_NOTIFICATION_TIMESTAMP=""
RISK_PREVIOUS_NOTIFICATION_TIMESTAMP=""
RISK_PREVIOUS_NOTIFICATION_LEVEL=""
RISK_EFFECTIVE_COOLDOWN_MINUTES=0
RISK_SUPPRESSION_STATS_TOTAL=0
RISK_SUPPRESSION_STATS_SUPPRESSED=0
RISK_SUPPRESSION_STATS_NOT_INCREASED=0
RISK_SUPPRESSION_STATS_COOLDOWN_ACTIVE=0
RISK_SUPPRESSION_STATS_NO_WEBHOOK_CONFIGURED=0
RISK_SUPPRESSION_STATS_NO_ALERT_FILE=0
RISK_SUPPRESSION_STATS_PREVIEW_REPORTS=0
RISK_SUPPRESSION_STATS_PREVIEW_SUPPRESSED=0
RISK_SUPPRESSION_STATS_PREVIEW_NOT_INCREASED=0
RISK_SUPPRESSION_STATS_PREVIEW_COOLDOWN_ACTIVE=0
RISK_SUPPRESSION_STATS_PREVIEW_NO_WEBHOOK_CONFIGURED=0
RISK_SUPPRESSION_STATS_PREVIEW_NO_ALERT_FILE=0
RISK_SUPPRESSION_STATS_COMMIT_REPORTS=0
RISK_SUPPRESSION_STATS_COMMIT_SUPPRESSED=0
RISK_SUPPRESSION_STATS_COMMIT_NOT_INCREASED=0
RISK_SUPPRESSION_STATS_COMMIT_COOLDOWN_ACTIVE=0
RISK_SUPPRESSION_STATS_COMMIT_NO_WEBHOOK_CONFIGURED=0
RISK_SUPPRESSION_STATS_COMMIT_NO_ALERT_FILE=0
RISK_NOTIFICATION_MESSAGE_ID=""
RISK_SUPPRESSION_RATIO=0
RISK_SUPPRESSION_RATIO_PREVIEW=0
RISK_SUPPRESSION_RATIO_COMMIT=0
RISK_SUPPRESSION_RATIO_ALERT_BREACHED="false"
RISK_SUPPRESSION_RATIO_ALERT_FILE=""
RISK_SUPPRESSION_RATIO_ALERT_MESSAGE_ID=""
RISK_SUPPRESSION_RATIO_ALERT_WEBHOOK_PUSHED="false"
RISK_SUPPRESSION_RATIO_ALERT_CONSECUTIVE_BREACHES=0

LOG_DIR="storage/logs"
mkdir -p "$LOG_DIR"
TS="$(date +%Y%m%d_%H%M%S)"
LOG_FILE="$LOG_DIR/nightly-maintenance_${MODE}_${TS}.log"
REPORT_FILE="$LOG_DIR/nightly-maintenance_${MODE}_${TS}.json"
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

find_latest_file() {
  local pattern="$1"
  ls -1t $pattern 2>/dev/null | head -n 1 || true
}

emit_alert() {
  local status="$1"
  local failed_stage="${2:-}"
  local exit_code="${3:-0}"
  local message="${4:-}"
  ALERT_MESSAGE_ID="${RISK_NOTIFICATION_MESSAGE_ID_PREFIX}-nightly-maintenance-alert-${status}-${MODE}-${TS}"

  ALERT_FILE="$ALERT_DIR/nightly-maintenance-alert_${status}_${TS}.json"
  cat > "$ALERT_FILE" <<EOF
{
  "task": "nightly-maintenance",
  "notification_message_id": "$(json_escape "$ALERT_MESSAGE_ID")",
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
      if ! curl -fsS --max-time 10 -H "Content-Type: application/json" -H "X-Message-Id: $ALERT_MESSAGE_ID" -H "X-Idempotency-Key: $ALERT_MESSAGE_ID" --data-binary "@$ALERT_FILE" "$ALERT_WEBHOOK_URL" >/dev/null; then
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
  "notification": {
    "reminder_days": $REMINDER_DAYS,
    "cleanup_days": $CLEANUP_DAYS
  },
  "api_access_retention": {
    "retention_days": $RETENTION_DAYS,
    "archive_dir": "$(json_escape "$ARCHIVE_DIR")"
  },
  "subtask_artifacts": {
    "notification": {
      "log_file": "$(json_escape "$SUB_NOTIFICATION_LOG")",
      "report_file": "$(json_escape "$SUB_NOTIFICATION_REPORT")"
    },
    "api_access_retention": {
      "log_file": "$(json_escape "$SUB_RETENTION_LOG")",
      "report_file": "$(json_escape "$SUB_RETENTION_REPORT")"
    }
  },
    "subtask_durations": {
      "notification_seconds": $NOTIFICATION_DURATION_SECONDS,
      "api_access_retention_seconds": $RETENTION_DURATION_SECONDS
    },
  "retry": {
    "retry_count": $RETRY_COUNT,
    "retry_delay_seconds": $RETRY_DELAY_SECONDS
  },
  "alert": {
    "alert_file": "$(json_escape "$ALERT_FILE")",
    "notification_message_id": "$(json_escape "$ALERT_MESSAGE_ID")",
    "webhook_enabled": $( [[ -n "$ALERT_WEBHOOK_URL" ]] && echo true || echo false )
  },
  "risk_assessment": {
    "enabled": $( [[ "$RISK_EVALUATION_ENABLED" == "true" ]] && echo true || echo false ),
    "executed": $( [[ "$RISK_EVAL_EXECUTED" == "true" ]] && echo true || echo false ),
    "trend_window": $RISK_TREND_WINDOW,
    "fail_rate_threshold": $RISK_FAIL_RATE_THRESHOLD,
    "error": "$(json_escape "$RISK_EVAL_ERROR")",
    "api_access_retention_history": {
      "risk_level": "$(json_escape "$RISK_API_LEVEL")",
      "threshold_breached": $( [[ "$RISK_API_THRESHOLD_BREACHED" == "true" ]] && echo true || echo false ),
      "risk_alert_file": "$(json_escape "$RISK_API_ALERT_FILE")"
    },
    "nightly_maintenance_history": {
      "risk_level": "$(json_escape "$RISK_NIGHTLY_LEVEL")",
      "threshold_breached": $( [[ "$RISK_NIGHTLY_THRESHOLD_BREACHED" == "true" ]] && echo true || echo false ),
      "risk_alert_file": "$(json_escape "$RISK_NIGHTLY_ALERT_FILE")"
    },
    "overall": {
      "risk_level": "$(json_escape "$RISK_OVERALL_LEVEL")",
      "risk_rank": $RISK_OVERALL_RANK,
      "previous_risk_level": "$(json_escape "$RISK_PREVIOUS_OVERALL_LEVEL")",
      "previous_risk_rank": $RISK_PREVIOUS_OVERALL_RANK,
      "notify_on_increase_only": $( [[ "$RISK_NOTIFY_ON_INCREASE_ONLY" == "true" ]] && echo true || echo false ),
      "notification_cooldown_minutes": $RISK_NOTIFICATION_COOLDOWN_MINUTES,
      "effective_notification_cooldown_minutes": $RISK_EFFECTIVE_COOLDOWN_MINUTES,
      "previous_notification_timestamp": "$(json_escape "$RISK_PREVIOUS_NOTIFICATION_TIMESTAMP")",
      "previous_notification_level": "$(json_escape "$RISK_PREVIOUS_NOTIFICATION_LEVEL")",
      "notification_webhook_target": "$(json_escape "$RISK_NOTIFICATION_WEBHOOK_TARGET")",
      "notification_webhook_pushed": $( [[ "$RISK_NOTIFICATION_WEBHOOK_PUSHED" == "true" ]] && echo true || echo false ),
      "notification_message_id": "$(json_escape "$RISK_NOTIFICATION_MESSAGE_ID")",
      "notification_timestamp": "$(json_escape "$RISK_NOTIFICATION_TIMESTAMP")",
      "notification_cooldown_active": $( [[ "$RISK_NOTIFICATION_COOLDOWN_ACTIVE" == "true" ]] && echo true || echo false ),
      "notification_suppressed": $( [[ "$RISK_NOTIFICATION_SUPPRESSED" == "true" ]] && echo true || echo false ),
      "notification_suppressed_reason": "$(json_escape "$RISK_NOTIFICATION_SUPPRESSED_REASON")",
      "notification_suppressed_reason_code": "$(json_escape "$RISK_NOTIFICATION_SUPPRESSED_REASON_CODE")",
      "suppression_stats_window": $RISK_SUPPRESSION_STATS_WINDOW,
      "suppression_stats": {
        "reports": $RISK_SUPPRESSION_STATS_TOTAL,
        "suppressed": $RISK_SUPPRESSION_STATS_SUPPRESSED,
        "suppressed_ratio": $RISK_SUPPRESSION_RATIO,
        "not_increased": $RISK_SUPPRESSION_STATS_NOT_INCREASED,
        "cooldown_active": $RISK_SUPPRESSION_STATS_COOLDOWN_ACTIVE,
        "no_webhook_configured": $RISK_SUPPRESSION_STATS_NO_WEBHOOK_CONFIGURED,
        "no_alert_file": $RISK_SUPPRESSION_STATS_NO_ALERT_FILE,
        "by_mode": {
          "preview": {
            "reports": $RISK_SUPPRESSION_STATS_PREVIEW_REPORTS,
            "suppressed": $RISK_SUPPRESSION_STATS_PREVIEW_SUPPRESSED,
            "suppressed_ratio": $RISK_SUPPRESSION_RATIO_PREVIEW,
            "not_increased": $RISK_SUPPRESSION_STATS_PREVIEW_NOT_INCREASED,
            "cooldown_active": $RISK_SUPPRESSION_STATS_PREVIEW_COOLDOWN_ACTIVE,
            "no_webhook_configured": $RISK_SUPPRESSION_STATS_PREVIEW_NO_WEBHOOK_CONFIGURED,
            "no_alert_file": $RISK_SUPPRESSION_STATS_PREVIEW_NO_ALERT_FILE
          },
          "commit": {
            "reports": $RISK_SUPPRESSION_STATS_COMMIT_REPORTS,
            "suppressed": $RISK_SUPPRESSION_STATS_COMMIT_SUPPRESSED,
            "suppressed_ratio": $RISK_SUPPRESSION_RATIO_COMMIT,
            "not_increased": $RISK_SUPPRESSION_STATS_COMMIT_NOT_INCREASED,
            "cooldown_active": $RISK_SUPPRESSION_STATS_COMMIT_COOLDOWN_ACTIVE,
            "no_webhook_configured": $RISK_SUPPRESSION_STATS_COMMIT_NO_WEBHOOK_CONFIGURED,
            "no_alert_file": $RISK_SUPPRESSION_STATS_COMMIT_NO_ALERT_FILE
          }
        }
      },
      "suppression_ratio_alert": {
        "enabled": $( [[ "$RISK_SUPPRESSION_RATIO_ALERT_ENABLED" == "true" ]] && echo true || echo false ),
        "threshold": $RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD,
        "current_ratio": $RISK_SUPPRESSION_RATIO,
        "breached": $( [[ "$RISK_SUPPRESSION_RATIO_ALERT_BREACHED" == "true" ]] && echo true || echo false ),
        "consecutive_breaches_from_latest": $RISK_SUPPRESSION_RATIO_ALERT_CONSECUTIVE_BREACHES,
        "alert_file": "$(json_escape "$RISK_SUPPRESSION_RATIO_ALERT_FILE")",
        "notification_message_id": "$(json_escape "$RISK_SUPPRESSION_RATIO_ALERT_MESSAGE_ID")",
        "webhook_pushed": $( [[ "$RISK_SUPPRESSION_RATIO_ALERT_WEBHOOK_PUSHED" == "true" ]] && echo true || echo false )
      }
    }
  },
  "failed_stage": "$(json_escape "$failed_stage")",
  "exit_code": $exit_code,
  "log_file": "$(json_escape "$LOG_FILE")"
}
EOF
}

json_payload_string_field() {
  local payload="$1"
  local key="$2"
  local raw
  raw="$(printf '%s' "$payload" | sed -n -E "s/.*\"${key}\":\"([^\"]*)\".*/\\1/p" | head -n 1)"
  printf '%s' "$raw"
}

json_payload_bool_field() {
  local payload="$1"
  local key="$2"
  local raw
  raw="$(printf '%s' "$payload" | sed -n -E "s/.*\"${key}\":(true|false).*/\\1/p" | head -n 1)"
  if [[ "$raw" == "true" || "$raw" == "false" ]]; then
    printf '%s' "$raw"
  else
    printf '%s' "false"
  fi
}

top_level_string_field_from_report() {
  local file="$1"
  local key="$2"
  sed -n -E "s/^[[:space:]]*\"${key}\"[[:space:]]*:[[:space:]]*\"([^\"]+)\".*/\\1/p" "$file" | head -n 1
}

risk_rank() {
  local level="$1"
  case "$level" in
    none)
      echo 0
      ;;
    warning)
      echo 1
      ;;
    critical)
      echo 2
      ;;
    *)
      echo -1
      ;;
  esac
}

find_previous_nightly_report() {
  ls -1t "$LOG_DIR"/nightly-maintenance_*.json 2>/dev/null | grep -v -F "$REPORT_FILE" | head -n 1 || true
}

extract_overall_risk_level_from_report() {
  local file="$1"
  sed -n '/"overall"[[:space:]]*:[[:space:]]*{/,/}/p' "$file" | sed -n -E 's/.*"risk_level"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/p' | head -n 1
}

extract_overall_string_field_from_report() {
  local file="$1"
  local key="$2"
  sed -n '/"overall"[[:space:]]*:[[:space:]]*{/,/}/p' "$file" | sed -n -E "s/.*\"${key}\"[[:space:]]*:[[:space:]]*\"([^\"]*)\".*/\\1/p" | head -n 1
}

extract_overall_bool_field_from_report() {
  local file="$1"
  local key="$2"
  sed -n '/"overall"[[:space:]]*:[[:space:]]*{/,/}/p' "$file" | sed -n -E "s/.*\"${key}\"[[:space:]]*:[[:space:]]*(true|false).*/\\1/p" | head -n 1
}

current_mode_cooldown_minutes() {
  if [[ "$MODE" == "commit" ]]; then
    printf '%s' "$RISK_NOTIFICATION_COOLDOWN_COMMIT_MINUTES"
  else
    printf '%s' "$RISK_NOTIFICATION_COOLDOWN_PREVIEW_MINUTES"
  fi
}

collect_suppression_stats() {
  RISK_SUPPRESSION_STATS_TOTAL=0
  RISK_SUPPRESSION_STATS_SUPPRESSED=0
  RISK_SUPPRESSION_STATS_NOT_INCREASED=0
  RISK_SUPPRESSION_STATS_COOLDOWN_ACTIVE=0
  RISK_SUPPRESSION_STATS_NO_WEBHOOK_CONFIGURED=0
  RISK_SUPPRESSION_STATS_NO_ALERT_FILE=0
  RISK_SUPPRESSION_STATS_PREVIEW_REPORTS=0
  RISK_SUPPRESSION_STATS_PREVIEW_SUPPRESSED=0
  RISK_SUPPRESSION_STATS_PREVIEW_NOT_INCREASED=0
  RISK_SUPPRESSION_STATS_PREVIEW_COOLDOWN_ACTIVE=0
  RISK_SUPPRESSION_STATS_PREVIEW_NO_WEBHOOK_CONFIGURED=0
  RISK_SUPPRESSION_STATS_PREVIEW_NO_ALERT_FILE=0
  RISK_SUPPRESSION_STATS_COMMIT_REPORTS=0
  RISK_SUPPRESSION_STATS_COMMIT_SUPPRESSED=0
  RISK_SUPPRESSION_STATS_COMMIT_NOT_INCREASED=0
  RISK_SUPPRESSION_STATS_COMMIT_COOLDOWN_ACTIVE=0
  RISK_SUPPRESSION_STATS_COMMIT_NO_WEBHOOK_CONFIGURED=0
  RISK_SUPPRESSION_STATS_COMMIT_NO_ALERT_FILE=0

  while IFS= read -r report_file; do
    [[ -z "$report_file" ]] && continue
    RISK_SUPPRESSION_STATS_TOTAL=$((RISK_SUPPRESSION_STATS_TOTAL + 1))

    local suppressed reason_code report_mode
    suppressed="$(extract_overall_bool_field_from_report "$report_file" "notification_suppressed")"
    reason_code="$(extract_overall_string_field_from_report "$report_file" "notification_suppressed_reason_code")"
    report_mode="$(top_level_string_field_from_report "$report_file" "mode")"

    if [[ "$report_mode" == "preview" ]]; then
      RISK_SUPPRESSION_STATS_PREVIEW_REPORTS=$((RISK_SUPPRESSION_STATS_PREVIEW_REPORTS + 1))
    elif [[ "$report_mode" == "commit" ]]; then
      RISK_SUPPRESSION_STATS_COMMIT_REPORTS=$((RISK_SUPPRESSION_STATS_COMMIT_REPORTS + 1))
    fi

    if [[ "$suppressed" == "true" ]]; then
      RISK_SUPPRESSION_STATS_SUPPRESSED=$((RISK_SUPPRESSION_STATS_SUPPRESSED + 1))
      if [[ "$report_mode" == "preview" ]]; then
        RISK_SUPPRESSION_STATS_PREVIEW_SUPPRESSED=$((RISK_SUPPRESSION_STATS_PREVIEW_SUPPRESSED + 1))
      elif [[ "$report_mode" == "commit" ]]; then
        RISK_SUPPRESSION_STATS_COMMIT_SUPPRESSED=$((RISK_SUPPRESSION_STATS_COMMIT_SUPPRESSED + 1))
      fi
      case "$reason_code" in
        not_increased)
          RISK_SUPPRESSION_STATS_NOT_INCREASED=$((RISK_SUPPRESSION_STATS_NOT_INCREASED + 1))
          if [[ "$report_mode" == "preview" ]]; then
            RISK_SUPPRESSION_STATS_PREVIEW_NOT_INCREASED=$((RISK_SUPPRESSION_STATS_PREVIEW_NOT_INCREASED + 1))
          elif [[ "$report_mode" == "commit" ]]; then
            RISK_SUPPRESSION_STATS_COMMIT_NOT_INCREASED=$((RISK_SUPPRESSION_STATS_COMMIT_NOT_INCREASED + 1))
          fi
          ;;
        cooldown_active)
          RISK_SUPPRESSION_STATS_COOLDOWN_ACTIVE=$((RISK_SUPPRESSION_STATS_COOLDOWN_ACTIVE + 1))
          if [[ "$report_mode" == "preview" ]]; then
            RISK_SUPPRESSION_STATS_PREVIEW_COOLDOWN_ACTIVE=$((RISK_SUPPRESSION_STATS_PREVIEW_COOLDOWN_ACTIVE + 1))
          elif [[ "$report_mode" == "commit" ]]; then
            RISK_SUPPRESSION_STATS_COMMIT_COOLDOWN_ACTIVE=$((RISK_SUPPRESSION_STATS_COMMIT_COOLDOWN_ACTIVE + 1))
          fi
          ;;
        no_webhook_configured)
          RISK_SUPPRESSION_STATS_NO_WEBHOOK_CONFIGURED=$((RISK_SUPPRESSION_STATS_NO_WEBHOOK_CONFIGURED + 1))
          if [[ "$report_mode" == "preview" ]]; then
            RISK_SUPPRESSION_STATS_PREVIEW_NO_WEBHOOK_CONFIGURED=$((RISK_SUPPRESSION_STATS_PREVIEW_NO_WEBHOOK_CONFIGURED + 1))
          elif [[ "$report_mode" == "commit" ]]; then
            RISK_SUPPRESSION_STATS_COMMIT_NO_WEBHOOK_CONFIGURED=$((RISK_SUPPRESSION_STATS_COMMIT_NO_WEBHOOK_CONFIGURED + 1))
          fi
          ;;
        no_alert_file)
          RISK_SUPPRESSION_STATS_NO_ALERT_FILE=$((RISK_SUPPRESSION_STATS_NO_ALERT_FILE + 1))
          if [[ "$report_mode" == "preview" ]]; then
            RISK_SUPPRESSION_STATS_PREVIEW_NO_ALERT_FILE=$((RISK_SUPPRESSION_STATS_PREVIEW_NO_ALERT_FILE + 1))
          elif [[ "$report_mode" == "commit" ]]; then
            RISK_SUPPRESSION_STATS_COMMIT_NO_ALERT_FILE=$((RISK_SUPPRESSION_STATS_COMMIT_NO_ALERT_FILE + 1))
          fi
          ;;
      esac
    fi
  done < <(ls -1t "$LOG_DIR"/nightly-maintenance_*.json 2>/dev/null | grep -v -F "$REPORT_FILE" | head -n "$RISK_SUPPRESSION_STATS_WINDOW" || true)

  RISK_SUPPRESSION_RATIO="$(awk -v s="$RISK_SUPPRESSION_STATS_SUPPRESSED" -v t="$RISK_SUPPRESSION_STATS_TOTAL" 'BEGIN { if (t<=0) printf "0.0000"; else printf "%.4f", s/t }')"
  RISK_SUPPRESSION_RATIO_PREVIEW="$(awk -v s="$RISK_SUPPRESSION_STATS_PREVIEW_SUPPRESSED" -v t="$RISK_SUPPRESSION_STATS_PREVIEW_REPORTS" 'BEGIN { if (t<=0) printf "0.0000"; else printf "%.4f", s/t }')"
  RISK_SUPPRESSION_RATIO_COMMIT="$(awk -v s="$RISK_SUPPRESSION_STATS_COMMIT_SUPPRESSED" -v t="$RISK_SUPPRESSION_STATS_COMMIT_REPORTS" 'BEGIN { if (t<=0) printf "0.0000"; else printf "%.4f", s/t }')"
}

emit_suppression_ratio_alert() {
  local now_ts now_compact target_webhook
  now_ts="$(date '+%Y-%m-%dT%H:%M:%S%z')"
  now_compact="$(date +%Y%m%d_%H%M%S)"
  RISK_SUPPRESSION_RATIO_ALERT_MESSAGE_ID="${RISK_NOTIFICATION_MESSAGE_ID_PREFIX}-suppression-ratio-alert-${MODE}-${now_compact}"
  RISK_SUPPRESSION_RATIO_ALERT_FILE="$RISK_ALERT_DIR/nightly-maintenance-suppression-ratio-alert_${MODE}_${now_compact}.json"

  cat > "$RISK_SUPPRESSION_RATIO_ALERT_FILE" <<EOF
{
  "task": "nightly-maintenance-suppression-ratio",
  "notification_message_id": "$(json_escape "$RISK_SUPPRESSION_RATIO_ALERT_MESSAGE_ID")",
  "mode": "$(json_escape "$MODE")",
  "timestamp": "$(json_escape "$now_ts")",
  "threshold": $RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD,
  "current_ratio": $RISK_SUPPRESSION_RATIO,
  "suppression_stats_window": $RISK_SUPPRESSION_STATS_WINDOW,
  "suppression_stats": {
    "reports": $RISK_SUPPRESSION_STATS_TOTAL,
    "suppressed": $RISK_SUPPRESSION_STATS_SUPPRESSED,
    "suppressed_ratio": $RISK_SUPPRESSION_RATIO
  }
}
EOF

  target_webhook="$RISK_WARNING_WEBHOOK_URL"
  if [[ -z "$target_webhook" ]]; then
    target_webhook="$RISK_WEBHOOK_URL"
  fi

  if [[ -n "$target_webhook" && -f "$RISK_SUPPRESSION_RATIO_ALERT_FILE" ]]; then
    if command -v curl >/dev/null 2>&1; then
      if curl -fsS --max-time 10 -H "Content-Type: application/json" -H "X-Message-Id: $RISK_SUPPRESSION_RATIO_ALERT_MESSAGE_ID" -H "X-Idempotency-Key: $RISK_SUPPRESSION_RATIO_ALERT_MESSAGE_ID" --data-binary "@$RISK_SUPPRESSION_RATIO_ALERT_FILE" "$target_webhook" >/dev/null; then
        RISK_SUPPRESSION_RATIO_ALERT_WEBHOOK_PUSHED="true"
      else
        RISK_EVAL_ERROR="suppression ratio alert webhook failed"
      fi
    else
      RISK_EVAL_ERROR="curl not found for suppression ratio alert webhook"
    fi
  fi
}

collect_consecutive_suppression_ratio_breaches() {
  RISK_SUPPRESSION_RATIO_ALERT_CONSECUTIVE_BREACHES=0

  while IFS= read -r report_file; do
    [[ -z "$report_file" ]] && continue

    local breached
    breached="$(sed -n '/"suppression_ratio_alert"[[:space:]]*:[[:space:]]*{/,/}/p' "$report_file" | sed -n -E 's/.*"breached"[[:space:]]*:[[:space:]]*(true|false).*/\1/p' | head -n 1)"

    if [[ "$breached" == "true" ]]; then
      RISK_SUPPRESSION_RATIO_ALERT_CONSECUTIVE_BREACHES=$((RISK_SUPPRESSION_RATIO_ALERT_CONSECUTIVE_BREACHES + 1))
    else
      break
    fi
  done < <(ls -1t "$LOG_DIR"/nightly-maintenance_*.json 2>/dev/null | grep -v -F "$REPORT_FILE" | head -n "$RISK_SUPPRESSION_STATS_WINDOW" || true)
}

to_epoch() {
  local input="$1"
  local out

  if [[ -z "$input" ]]; then
    echo ""
    return 0
  fi

  out="$(date -j -f '%Y-%m-%dT%H:%M:%S%z' "$input" +%s 2>/dev/null || true)"
  if [[ -z "$out" ]]; then
    out="$(date -j -f '%Y-%m-%d %H:%M:%S' "$input" +%s 2>/dev/null || true)"
  fi
  if [[ -z "$out" ]]; then
    out="$(date -d "$input" +%s 2>/dev/null || true)"
  fi

  echo "$out"
}

run_risk_evaluation() {
  RISK_EVAL_EXECUTED="false"
  RISK_EVAL_ERROR=""
  RISK_OVERALL_LEVEL="none"
  RISK_OVERALL_RANK=0
  RISK_PREVIOUS_OVERALL_LEVEL=""
  RISK_PREVIOUS_OVERALL_RANK=-1
  RISK_NOTIFICATION_WEBHOOK_TARGET=""
  RISK_NOTIFICATION_WEBHOOK_PUSHED="false"
  RISK_NOTIFICATION_SUPPRESSED="false"
  RISK_NOTIFICATION_SUPPRESSED_REASON=""
  RISK_NOTIFICATION_SUPPRESSED_REASON_CODE=""
  RISK_NOTIFICATION_COOLDOWN_ACTIVE="false"
  RISK_NOTIFICATION_TIMESTAMP=""
  RISK_PREVIOUS_NOTIFICATION_TIMESTAMP=""
  RISK_PREVIOUS_NOTIFICATION_LEVEL=""
  RISK_NOTIFICATION_MESSAGE_ID=""
  RISK_EFFECTIVE_COOLDOWN_MINUTES="$(current_mode_cooldown_minutes)"
  RISK_SUPPRESSION_RATIO_ALERT_BREACHED="false"
  RISK_SUPPRESSION_RATIO_ALERT_FILE=""
  RISK_SUPPRESSION_RATIO_ALERT_MESSAGE_ID=""
  RISK_SUPPRESSION_RATIO_ALERT_WEBHOOK_PUSHED="false"
  RISK_SUPPRESSION_RATIO_ALERT_CONSECUTIVE_BREACHES=0

  collect_suppression_stats

  if [[ "$RISK_SUPPRESSION_RATIO_ALERT_ENABLED" == "true" ]]; then
    if awk -v ratio="$RISK_SUPPRESSION_RATIO" -v threshold="$RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD" 'BEGIN { exit !(ratio >= threshold) }'; then
      RISK_SUPPRESSION_RATIO_ALERT_BREACHED="true"
      emit_suppression_ratio_alert
    fi
    collect_consecutive_suppression_ratio_breaches
    if [[ "$RISK_SUPPRESSION_RATIO_ALERT_BREACHED" == "true" ]]; then
      RISK_SUPPRESSION_RATIO_ALERT_CONSECUTIVE_BREACHES=$((RISK_SUPPRESSION_RATIO_ALERT_CONSECUTIVE_BREACHES + 1))
    fi
  fi

  if [[ "$RISK_EVALUATION_ENABLED" != "true" ]]; then
    return 0
  fi

  local base_args=()
  base_args+=("30" "--json" "--trend-window=$RISK_TREND_WINDOW" "--fail-rate-threshold=$RISK_FAIL_RATE_THRESHOLD")
  base_args+=("--emit-risk-alert" "--risk-alert-dir=$RISK_ALERT_DIR")

  local payload_api=""
  local payload_nightly=""

  set +e
  payload_api="$(./scripts/show-api-access-retention-history.sh "${base_args[@]}" 2>/dev/null | tail -n 1)"
  local ec_api=$?
  payload_nightly="$(./scripts/show-nightly-maintenance-history.sh "${base_args[@]}" 2>/dev/null | tail -n 1)"
  local ec_nightly=$?
  set -e

  if [[ $ec_api -ne 0 || $ec_nightly -ne 0 || -z "$payload_api" || -z "$payload_nightly" ]]; then
    RISK_EVAL_ERROR="risk evaluation command failed"
    return 0
  fi

  RISK_API_LEVEL="$(json_payload_string_field "$payload_api" "risk_level")"
  RISK_API_ALERT_FILE="$(json_payload_string_field "$payload_api" "risk_alert_file")"
  RISK_API_THRESHOLD_BREACHED="$(json_payload_bool_field "$payload_api" "fail_rate_threshold_breached")"

  RISK_NIGHTLY_LEVEL="$(json_payload_string_field "$payload_nightly" "risk_level")"
  RISK_NIGHTLY_ALERT_FILE="$(json_payload_string_field "$payload_nightly" "risk_alert_file")"
  RISK_NIGHTLY_THRESHOLD_BREACHED="$(json_payload_bool_field "$payload_nightly" "fail_rate_threshold_breached")"

  local previous_report=""
  previous_report="$(find_previous_nightly_report)"
  if [[ -n "$previous_report" && -f "$previous_report" ]]; then
    RISK_PREVIOUS_OVERALL_LEVEL="$(extract_overall_risk_level_from_report "$previous_report")"
    RISK_PREVIOUS_OVERALL_RANK="$(risk_rank "$RISK_PREVIOUS_OVERALL_LEVEL")"
    RISK_PREVIOUS_NOTIFICATION_TIMESTAMP="$(extract_overall_string_field_from_report "$previous_report" "notification_timestamp")"
    RISK_PREVIOUS_NOTIFICATION_LEVEL="$(extract_overall_string_field_from_report "$previous_report" "risk_level")"
  fi

  local effective_webhook=""
  if [[ "$RISK_API_LEVEL" == "critical" || "$RISK_NIGHTLY_LEVEL" == "critical" ]]; then
    RISK_OVERALL_LEVEL="critical"
    effective_webhook="$RISK_CRITICAL_WEBHOOK_URL"
  elif [[ "$RISK_API_LEVEL" == "warning" || "$RISK_NIGHTLY_LEVEL" == "warning" ]]; then
    RISK_OVERALL_LEVEL="warning"
    effective_webhook="$RISK_WARNING_WEBHOOK_URL"
  else
    RISK_OVERALL_LEVEL="none"
  fi
  RISK_OVERALL_RANK="$(risk_rank "$RISK_OVERALL_LEVEL")"

  if [[ -z "$effective_webhook" ]]; then
    effective_webhook="$RISK_WEBHOOK_URL"
  fi

  if [[ "$RISK_OVERALL_LEVEL" != "none" ]]; then
    RISK_NOTIFICATION_MESSAGE_ID="${RISK_NOTIFICATION_MESSAGE_ID_PREFIX}-${RISK_OVERALL_LEVEL}-${MODE}-${TS}"

    if [[ -z "$effective_webhook" ]]; then
      RISK_NOTIFICATION_SUPPRESSED="true"
      RISK_NOTIFICATION_SUPPRESSED_REASON="未配置风险通知 webhook"
      RISK_NOTIFICATION_SUPPRESSED_REASON_CODE="no_webhook_configured"
    elif [[ "$RISK_NOTIFY_ON_INCREASE_ONLY" == "true" && "$RISK_PREVIOUS_OVERALL_RANK" -ge 0 && "$RISK_OVERALL_RANK" -le "$RISK_PREVIOUS_OVERALL_RANK" ]]; then
      RISK_NOTIFICATION_SUPPRESSED="true"
      RISK_NOTIFICATION_SUPPRESSED_REASON="风险等级未上升，跳过推送"
      RISK_NOTIFICATION_SUPPRESSED_REASON_CODE="not_increased"
      RISK_NOTIFICATION_WEBHOOK_TARGET="$effective_webhook"
    else
      local skip_for_cooldown="false"
      if [[ "$RISK_EFFECTIVE_COOLDOWN_MINUTES" -gt 0 && -n "$RISK_PREVIOUS_NOTIFICATION_TIMESTAMP" && "$RISK_PREVIOUS_NOTIFICATION_LEVEL" == "$RISK_OVERALL_LEVEL" ]]; then
        local prev_epoch now_epoch delta_seconds cooldown_seconds
        prev_epoch="$(to_epoch "$RISK_PREVIOUS_NOTIFICATION_TIMESTAMP")"
        now_epoch="$(date +%s)"
        if [[ -n "$prev_epoch" ]]; then
          delta_seconds=$((now_epoch - prev_epoch))
          cooldown_seconds=$((RISK_EFFECTIVE_COOLDOWN_MINUTES * 60))
          if (( delta_seconds >= 0 && delta_seconds < cooldown_seconds )); then
            skip_for_cooldown="true"
          fi
        fi
      fi

      if [[ "$skip_for_cooldown" == "true" ]]; then
        RISK_NOTIFICATION_SUPPRESSED="true"
        RISK_NOTIFICATION_COOLDOWN_ACTIVE="true"
        RISK_NOTIFICATION_SUPPRESSED_REASON="风险通知处于冷却窗口内"
        RISK_NOTIFICATION_SUPPRESSED_REASON_CODE="cooldown_active"
        RISK_NOTIFICATION_WEBHOOK_TARGET="$effective_webhook"
      elif [[ -f "$RISK_NIGHTLY_ALERT_FILE" ]]; then
        if command -v curl >/dev/null 2>&1; then
          if curl -fsS --max-time 10 -H "Content-Type: application/json" -H "X-Message-Id: $RISK_NOTIFICATION_MESSAGE_ID" -H "X-Idempotency-Key: $RISK_NOTIFICATION_MESSAGE_ID" --data-binary "@$RISK_NIGHTLY_ALERT_FILE" "$effective_webhook" >/dev/null; then
            RISK_NOTIFICATION_WEBHOOK_PUSHED="true"
            RISK_NOTIFICATION_WEBHOOK_TARGET="$effective_webhook"
            RISK_NOTIFICATION_TIMESTAMP="$(date '+%Y-%m-%dT%H:%M:%S%z')"
          else
            RISK_EVAL_ERROR="risk notification webhook failed"
            RISK_NOTIFICATION_WEBHOOK_TARGET="$effective_webhook"
          fi
        else
          RISK_EVAL_ERROR="curl not found for risk notification webhook"
        fi
      elif [[ -f "$RISK_API_ALERT_FILE" ]]; then
        if command -v curl >/dev/null 2>&1; then
          if curl -fsS --max-time 10 -H "Content-Type: application/json" -H "X-Message-Id: $RISK_NOTIFICATION_MESSAGE_ID" -H "X-Idempotency-Key: $RISK_NOTIFICATION_MESSAGE_ID" --data-binary "@$RISK_API_ALERT_FILE" "$effective_webhook" >/dev/null; then
            RISK_NOTIFICATION_WEBHOOK_PUSHED="true"
            RISK_NOTIFICATION_WEBHOOK_TARGET="$effective_webhook"
            RISK_NOTIFICATION_TIMESTAMP="$(date '+%Y-%m-%dT%H:%M:%S%z')"
          else
            RISK_EVAL_ERROR="risk notification webhook failed"
            RISK_NOTIFICATION_WEBHOOK_TARGET="$effective_webhook"
          fi
        else
          RISK_EVAL_ERROR="curl not found for risk notification webhook"
        fi
      else
        RISK_NOTIFICATION_SUPPRESSED="true"
        RISK_NOTIFICATION_SUPPRESSED_REASON="未找到可推送的风险告警文件"
        RISK_NOTIFICATION_SUPPRESSED_REASON_CODE="no_alert_file"
        RISK_NOTIFICATION_WEBHOOK_TARGET="$effective_webhook"
      fi
    fi
  fi

  if [[ "$RISK_NOTIFICATION_SUPPRESSED" == "true" && -z "$RISK_NOTIFICATION_SUPPRESSED_REASON_CODE" ]]; then
    RISK_NOTIFICATION_SUPPRESSED_REASON_CODE="suppressed"
  fi

  if [[ "$RISK_NOTIFICATION_WEBHOOK_PUSHED" != "true" && -z "$RISK_NOTIFICATION_TIMESTAMP" ]]; then
    RISK_NOTIFICATION_TIMESTAMP="$RISK_PREVIOUS_NOTIFICATION_TIMESTAMP"
  fi

  RISK_EVAL_EXECUTED="true"
}

run_with_retry() {
  local stage="$1"
  shift

  local stage_start
  stage_start="$(date +%s)"
  local attempt=0
  local max_attempts=$((RETRY_COUNT + 1))

  while (( attempt < max_attempts )); do
    attempt=$((attempt + 1))
    CURRENT_STAGE="$stage (attempt ${attempt}/${max_attempts})"
    if "$@"; then
      STAGE_LAST_DURATION_SECONDS="$(( $(date +%s) - stage_start ))"
      return 0
    fi

    if (( attempt < max_attempts )); then
      echo "阶段失败，${RETRY_DELAY_SECONDS}s 后重试: $stage (attempt ${attempt}/${max_attempts})" >&2
      sleep "$RETRY_DELAY_SECONDS"
    fi
  done

  STAGE_LAST_DURATION_SECONDS="$(( $(date +%s) - stage_start ))"

  return 1
}

on_error() {
  local exit_code="$?"
  set +e
  emit_alert "failed" "$CURRENT_STAGE" "$exit_code" "夜间运维总控执行失败"
  write_report "failed" "$CURRENT_STAGE" "$exit_code"
  run_risk_evaluation
  write_report "failed" "$CURRENT_STAGE" "$exit_code"
  echo "----------------------------------------"
  echo "夜间运维总控失败"
  echo "- 失败阶段: $CURRENT_STAGE"
  echo "- 退出码: $exit_code"
  echo "- 日志文件: $LOG_FILE"
  echo "- 报告文件: $REPORT_FILE"
  echo "- 告警文件: $ALERT_FILE"
  echo "建议操作:"
  echo "1) 先执行 preview 模式确认各阶段输出"
  echo "2) 若通知阶段失败，单独执行 run-notification-maintenance.sh 排查"
  echo "3) 若审计保留阶段失败，单独执行 run-api-access-log-retention.sh 排查"
  exit "$exit_code"
}

trap on_error ERR

if [[ "$MODE" != "preview" && "$MODE" != "commit" ]]; then
  fail_and_exit "mode 必须是 preview 或 commit"
fi

if ! [[ "$RETRY_COUNT" =~ ^[0-9]+$ ]]; then
  fail_and_exit "retry_count 必须为非负整数"
fi

if ! [[ "$RETRY_DELAY_SECONDS" =~ ^[0-9]+$ ]]; then
  fail_and_exit "retry_delay_seconds 必须为非负整数"
fi

if [[ "$RISK_EVALUATION_ENABLED" != "true" && "$RISK_EVALUATION_ENABLED" != "false" ]]; then
  fail_and_exit "RISK_EVALUATION_ENABLED 仅支持 true|false"
fi

if [[ "$RISK_NOTIFY_ON_INCREASE_ONLY" != "true" && "$RISK_NOTIFY_ON_INCREASE_ONLY" != "false" ]]; then
  fail_and_exit "RISK_NOTIFY_ON_INCREASE_ONLY 仅支持 true|false"
fi

if ! [[ "$RISK_NOTIFICATION_COOLDOWN_MINUTES" =~ ^[0-9]+$ ]]; then
  fail_and_exit "RISK_NOTIFICATION_COOLDOWN_MINUTES 必须为非负整数"
fi

if ! [[ "$RISK_NOTIFICATION_COOLDOWN_PREVIEW_MINUTES" =~ ^[0-9]+$ ]]; then
  fail_and_exit "RISK_NOTIFICATION_COOLDOWN_PREVIEW_MINUTES 必须为非负整数"
fi

if ! [[ "$RISK_NOTIFICATION_COOLDOWN_COMMIT_MINUTES" =~ ^[0-9]+$ ]]; then
  fail_and_exit "RISK_NOTIFICATION_COOLDOWN_COMMIT_MINUTES 必须为非负整数"
fi

if ! [[ "$RISK_SUPPRESSION_STATS_WINDOW" =~ ^[0-9]+$ ]] || [[ "$RISK_SUPPRESSION_STATS_WINDOW" -le 0 ]]; then
  fail_and_exit "RISK_SUPPRESSION_STATS_WINDOW 必须为正整数"
fi

if [[ -z "$RISK_NOTIFICATION_MESSAGE_ID_PREFIX" ]]; then
  fail_and_exit "RISK_NOTIFICATION_MESSAGE_ID_PREFIX 不能为空"
fi

if ! [[ "$RISK_NOTIFICATION_MESSAGE_ID_PREFIX" =~ ^[A-Za-z0-9._-]{1,32}$ ]]; then
  fail_and_exit "RISK_NOTIFICATION_MESSAGE_ID_PREFIX 仅支持 1-32 位字母数字._-"
fi

if [[ "$RISK_SUPPRESSION_RATIO_ALERT_ENABLED" != "true" && "$RISK_SUPPRESSION_RATIO_ALERT_ENABLED" != "false" ]]; then
  fail_and_exit "RISK_SUPPRESSION_RATIO_ALERT_ENABLED 仅支持 true|false"
fi

if ! [[ "$RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD" =~ ^(0(\.[0-9]+)?|1(\.0+)?)$ ]]; then
  fail_and_exit "RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD 必须在 0~1 之间（例如 0.50）"
fi

if ! [[ "$RISK_TREND_WINDOW" =~ ^[0-9]+$ ]] || [[ "$RISK_TREND_WINDOW" -le 0 ]]; then
  fail_and_exit "RISK_TREND_WINDOW 必须为正整数"
fi

if ! [[ "$RISK_FAIL_RATE_THRESHOLD" =~ ^(0(\.[0-9]+)?|1(\.0+)?)$ ]]; then
  fail_and_exit "RISK_FAIL_RATE_THRESHOLD 必须在 0~1 之间（例如 0.30）"
fi

if [[ ! -x "scripts/run-notification-maintenance.sh" ]]; then
  fail_and_exit "未找到可执行脚本 scripts/run-notification-maintenance.sh"
fi

if [[ ! -x "scripts/run-api-access-log-retention.sh" ]]; then
  fail_and_exit "未找到可执行脚本 scripts/run-api-access-log-retention.sh"
fi

if [[ "$RISK_EVALUATION_ENABLED" == "true" ]]; then
  if [[ ! -x "scripts/show-api-access-retention-history.sh" ]]; then
    fail_and_exit "未找到可执行脚本 scripts/show-api-access-retention-history.sh"
  fi
  if [[ ! -x "scripts/show-nightly-maintenance-history.sh" ]]; then
    fail_and_exit "未找到可执行脚本 scripts/show-nightly-maintenance-history.sh"
  fi
fi

echo "========================================"
echo "夜间运维总控"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "通知维护: reminder_days=$REMINDER_DAYS cleanup_days=$CLEANUP_DAYS"
echo "审计保留: retention_days=$RETENTION_DAYS archive_dir=$ARCHIVE_DIR"
echo "重试策略: retry_count=$RETRY_COUNT retry_delay_seconds=$RETRY_DELAY_SECONDS"
echo "风险评估: enabled=$RISK_EVALUATION_ENABLED trend_window=$RISK_TREND_WINDOW fail_rate_threshold=$RISK_FAIL_RATE_THRESHOLD notify_on_increase_only=$RISK_NOTIFY_ON_INCREASE_ONLY cooldown_default=$RISK_NOTIFICATION_COOLDOWN_MINUTES cooldown_preview=$RISK_NOTIFICATION_COOLDOWN_PREVIEW_MINUTES cooldown_commit=$RISK_NOTIFICATION_COOLDOWN_COMMIT_MINUTES suppression_stats_window=$RISK_SUPPRESSION_STATS_WINDOW msgid_prefix=$RISK_NOTIFICATION_MESSAGE_ID_PREFIX suppression_ratio_alert_enabled=$RISK_SUPPRESSION_RATIO_ALERT_ENABLED suppression_ratio_alert_threshold=$RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD"
echo "日志文件: $LOG_FILE"
echo "报告文件: $REPORT_FILE"
echo "========================================"

run_with_retry "通知维护" ./scripts/run-notification-maintenance.sh "$MODE" "$REMINDER_DAYS" "$CLEANUP_DAYS"
NOTIFICATION_DURATION_SECONDS="$STAGE_LAST_DURATION_SECONDS"
SUB_NOTIFICATION_LOG="$(find_latest_file 'storage/logs/notification-maintenance_*.log')"
SUB_NOTIFICATION_REPORT="$(find_latest_file 'storage/logs/notification-maintenance_*.json')"

run_with_retry "API 审计日志保留" ./scripts/run-api-access-log-retention.sh "$MODE" "$RETENTION_DAYS" "$ARCHIVE_DIR"
RETENTION_DURATION_SECONDS="$STAGE_LAST_DURATION_SECONDS"
SUB_RETENTION_LOG="$(find_latest_file 'storage/logs/api-access-retention_*.log')"
SUB_RETENTION_REPORT="$(find_latest_file 'storage/logs/api-access-retention_*.json')"

CURRENT_STAGE="完成"
write_report "success" "" "0"
run_risk_evaluation
write_report "success" "" "0"

echo "夜间运维总控执行完成"
if [[ "$RISK_EVALUATION_ENABLED" == "true" ]]; then
  echo "风险评估结果: api_access_retention=$RISK_API_LEVEL nightly=$RISK_NIGHTLY_LEVEL overall=$RISK_OVERALL_LEVEL rank=$RISK_OVERALL_RANK"
fi
echo "日志文件: $LOG_FILE"
echo "报告文件: $REPORT_FILE"
