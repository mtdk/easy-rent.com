#!/usr/bin/env bash
set -euo pipefail

# API 审计日志保留历史查看
#
# 用法:
#   ./scripts/show-api-access-retention-history.sh [limit] [--status=success|failed|all] [--mode=preview|commit|all] [--from=<datetime>] [--to=<datetime>] [--trend-window=<n>] [--fail-rate-threshold=<0~1>] [--emit-risk-alert] [--risk-alert-dir=<path>] [--risk-webhook-url=<url>] [--summary] [--csv=<path>] [--json] [--json-file=<path>]

LIMIT="${1:-20}"
STATUS_FILTER="all"
MODE_FILTER="all"
SHOW_SUMMARY="false"
CSV_OUTPUT=""
JSON_OUTPUT="false"
JSON_FILE=""
FROM_TS=""
TO_TS=""
TREND_WINDOW=10
FAIL_RATE_THRESHOLD="0.30"
EMIT_RISK_ALERT="false"
RISK_ALERT_DIR="storage/logs/alerts"
RISK_WEBHOOK_URL="${RISK_ALERT_WEBHOOK_URL:-}"
RISK_NOTIFICATION_MESSAGE_ID_PREFIX="${RISK_NOTIFICATION_MESSAGE_ID_PREFIX:-risk}"

if [[ "$LIMIT" =~ ^-- ]]; then
  LIMIT="20"
fi

for arg in "$@"; do
  case "$arg" in
    --status=*)
      STATUS_FILTER="${arg#*=}"
      ;;
    --mode=*)
      MODE_FILTER="${arg#*=}"
      ;;
    --summary)
      SHOW_SUMMARY="true"
      ;;
    --csv=*)
      CSV_OUTPUT="${arg#*=}"
      ;;
    --json)
      JSON_OUTPUT="true"
      ;;
    --json-file=*)
      JSON_FILE="${arg#*=}"
      ;;
    --from=*)
      FROM_TS="${arg#*=}"
      ;;
    --to=*)
      TO_TS="${arg#*=}"
      ;;
    --trend-window=*)
      TREND_WINDOW="${arg#*=}"
      ;;
    --fail-rate-threshold=*)
      FAIL_RATE_THRESHOLD="${arg#*=}"
      ;;
    --emit-risk-alert)
      EMIT_RISK_ALERT="true"
      ;;
    --risk-alert-dir=*)
      RISK_ALERT_DIR="${arg#*=}"
      ;;
    --risk-webhook-url=*)
      RISK_WEBHOOK_URL="${arg#*=}"
      ;;
  esac
done

if ! [[ "$LIMIT" =~ ^[0-9]+$ ]] || [[ "$LIMIT" -le 0 ]]; then
  echo "错误: limit 必须为正整数" >&2
  exit 1
fi

if [[ "$STATUS_FILTER" != "all" && "$STATUS_FILTER" != "success" && "$STATUS_FILTER" != "failed" ]]; then
  echo "错误: --status 仅支持 success|failed|all" >&2
  exit 1
fi

if [[ "$MODE_FILTER" != "all" && "$MODE_FILTER" != "preview" && "$MODE_FILTER" != "commit" ]]; then
  echo "错误: --mode 仅支持 preview|commit|all" >&2
  exit 1
fi

if ! [[ "$TREND_WINDOW" =~ ^[0-9]+$ ]] || [[ "$TREND_WINDOW" -le 0 ]]; then
  echo "错误: --trend-window 必须为正整数" >&2
  exit 1
fi

if ! [[ "$FAIL_RATE_THRESHOLD" =~ ^(0(\.[0-9]+)?|1(\.0+)?)$ ]]; then
  echo "错误: --fail-rate-threshold 必须在 0~1 之间（例如 0.3）" >&2
  exit 1
fi

if ! [[ "$RISK_NOTIFICATION_MESSAGE_ID_PREFIX" =~ ^[A-Za-z0-9._-]{1,32}$ ]]; then
  echo "错误: RISK_NOTIFICATION_MESSAGE_ID_PREFIX 仅支持 1-32 位字母数字._-" >&2
  exit 1
fi

if [[ -n "$CSV_OUTPUT" ]]; then
  csv_dir="$(dirname "$CSV_OUTPUT")"
  if [[ "$csv_dir" != "." ]]; then
    mkdir -p "$csv_dir"
  fi
fi

if [[ -n "$JSON_FILE" ]]; then
  json_dir="$(dirname "$JSON_FILE")"
  if [[ "$json_dir" != "." ]]; then
    mkdir -p "$json_dir"
  fi
fi

LOG_DIR="${LOG_DIR:-storage/logs}"

if [[ ! -d "$LOG_DIR" ]]; then
  echo "未找到日志目录: $LOG_DIR"
  exit 0
fi

FILES=( $(ls -1t "$LOG_DIR"/api-access-retention_*.json 2>/dev/null | head -n "$LIMIT" || true) )

if [[ ${#FILES[@]} -eq 0 ]]; then
  echo "未找到 API 审计日志保留历史报告"
  exit 0
fi

json_value() {
  local file="$1"
  local key="$2"
  local value
  value="$(grep -m1 '"'"$key"'"' "$file" | sed -E 's/^.*"'"$key"'"[[:space:]]*:[[:space:]]*//; s/[",]$//; s/^"//; s/"$//')"
  printf '%s' "$value"
}

pass_status() {
  local status="$1"
  if [[ "$STATUS_FILTER" == "all" || "$STATUS_FILTER" == "$status" ]]; then
    return 0
  fi
  return 1
}

pass_mode() {
  local mode="$1"
  if [[ "$MODE_FILTER" == "all" || "$MODE_FILTER" == "$mode" ]]; then
    return 0
  fi
  return 1
}

summary_total=0
summary_success=0
summary_failed=0
summary_preview=0
summary_commit=0
trend_sample_size=0
trend_failed=0
recent_failure_rate="0.0000"
fail_rate_threshold_breached="false"
risk_level="none"
risk_alert_file=""
risk_webhook_pushed="false"
risk_notification_message_id=""
records_json=""
FROM_EPOCH=""
TO_EPOCH=""
latest_failed_ts=""
latest_failed_report=""
consecutive_failed_from_latest=0
streak_closed="false"

json_escape() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="${s//$'\n'/\\n}"
  s="${s//$'\r'/\\r}"
  s="${s//$'\t'/\\t}"
  printf '%s' "$s"
}

to_epoch() {
  local input="$1"
  local bound="${2:-point}"
  local out

  if [[ -z "$input" ]]; then
    echo ""
    return 0
  fi

  local normalized="$input"
  if [[ "$input" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
    if [[ "$bound" == "end" ]]; then
      normalized="$input 23:59:59"
    else
      normalized="$input 00:00:00"
    fi
  fi

  out="$(date -j -f '%Y-%m-%dT%H:%M:%S%z' "$normalized" +%s 2>/dev/null || true)"
  if [[ -z "$out" ]]; then
    out="$(date -j -f '%Y-%m-%d %H:%M:%S' "$normalized" +%s 2>/dev/null || true)"
  fi
  if [[ -z "$out" ]]; then
    out="$(date -d "$normalized" +%s 2>/dev/null || true)"
  fi

  echo "$out"
}

pass_time_range() {
  local ts="$1"
  local ts_epoch
  ts_epoch="$(to_epoch "$ts")"

  if [[ -z "$ts_epoch" ]]; then
    return 0
  fi

  if [[ -n "$FROM_EPOCH" && "$ts_epoch" -lt "$FROM_EPOCH" ]]; then
    return 1
  fi

  if [[ -n "$TO_EPOCH" && "$ts_epoch" -gt "$TO_EPOCH" ]]; then
    return 1
  fi

  return 0
}

FROM_EPOCH="$(to_epoch "$FROM_TS" "start")"
TO_EPOCH="$(to_epoch "$TO_TS" "end")"

if [[ -n "$FROM_TS" && -z "$FROM_EPOCH" ]]; then
  echo "错误: --from 时间格式无法解析: $FROM_TS" >&2
  exit 1
fi

if [[ -n "$TO_TS" && -z "$TO_EPOCH" ]]; then
  echo "错误: --to 时间格式无法解析: $TO_TS" >&2
  exit 1
fi

append_json_record() {
  local ts="$1"
  local mode="$2"
  local status="$3"
  local days="$4"
  local exit_code="$5"
  local report="$6"

  local item
  item="{\"timestamp\":\"$(json_escape "$ts")\",\"mode\":\"$(json_escape "$mode")\",\"status\":\"$(json_escape "$status")\",\"retention_days\":\"$(json_escape "$days")\",\"exit_code\":\"$(json_escape "$exit_code")\",\"report\":\"$(json_escape "$report")\"}"

  if [[ -z "$records_json" ]]; then
    records_json="$item"
  else
    records_json="$records_json,$item"
  fi
}

emit_risk_alert() {
  local now_ts
  local now_compact
  local notification_message_id
  now_ts="$(date '+%Y-%m-%dT%H:%M:%S%z')"
  now_compact="$(date +%Y%m%d_%H%M%S)"
  notification_message_id="${RISK_NOTIFICATION_MESSAGE_ID_PREFIX}-api-access-retention-history-${risk_level}-${now_compact}"
  risk_notification_message_id="$notification_message_id"

  mkdir -p "$RISK_ALERT_DIR"
  risk_alert_file="$RISK_ALERT_DIR/api-access-retention-history-risk-alert_${risk_level}_${now_compact}.json"

  cat > "$risk_alert_file" <<EOF
{
  "task": "api-access-retention-history",
  "notification_message_id": "$(json_escape "$notification_message_id")",
  "timestamp": "$(json_escape "$now_ts")",
  "risk_level": "$(json_escape "$risk_level")",
  "trend_window": $TREND_WINDOW,
  "trend_sample_size": $trend_sample_size,
  "trend_failed": $trend_failed,
  "recent_failure_rate": $recent_failure_rate,
  "fail_rate_threshold": $FAIL_RATE_THRESHOLD,
  "fail_rate_threshold_breached": $fail_rate_threshold_breached,
  "consecutive_failed_from_latest": $consecutive_failed_from_latest,
  "latest_failed_timestamp": "$(json_escape "$latest_failed_ts")",
  "latest_failed_report": "$(json_escape "$latest_failed_report")"
}
EOF

if [[ -n "$RISK_WEBHOOK_URL" ]]; then
    if command -v curl >/dev/null 2>&1; then
      if curl -fsS --max-time 10 -H "Content-Type: application/json" --data-binary "@$risk_alert_file" "$RISK_WEBHOOK_URL" >/dev/null; then
        risk_webhook_pushed="true"
      else
        echo "警告: 风险 webhook 推送失败: $RISK_WEBHOOK_URL" >&2
      fi
    else
      echo "警告: 未找到 curl，跳过风险 webhook 推送" >&2
    fi
  fi
}

if [[ -n "$CSV_OUTPUT" ]]; then
  echo "timestamp,mode,status,retention_days,exit_code,report" > "$CSV_OUTPUT"
fi

echo "API 审计日志保留历史"
echo "- limit: $LIMIT"
echo "- status filter: $STATUS_FILTER"
echo "- mode filter: $MODE_FILTER"
echo "- from: ${FROM_TS:-all}"
echo "- to: ${TO_TS:-all}"
echo "- trend window: $TREND_WINDOW"
echo "- fail rate threshold: $FAIL_RATE_THRESHOLD"
echo

printf "%-22s %-8s %-8s %-8s %-8s %-32s\n" "timestamp" "mode" "status" "days" "exit" "report"
printf "%-22s %-8s %-8s %-8s %-8s %-32s\n" "----------------------" "--------" "--------" "--------" "--------" "--------------------------------"

for file in "${FILES[@]}"; do
  status="$(json_value "$file" "status")"
  mode="$(json_value "$file" "mode")"

  if ! pass_status "$status"; then
    continue
  fi
  if ! pass_mode "$mode"; then
    continue
  fi

  ts="$(json_value "$file" "timestamp")"
  days="$(json_value "$file" "retention_days")"
  exit_code="$(json_value "$file" "exit_code")"

  if ! pass_time_range "$ts"; then
    continue
  fi

  base_name="${file##*/}"
  printf "%-22s %-8s %-8s %-8s %-8s %-32s\n" "$ts" "$mode" "$status" "$days" "$exit_code" "$base_name"

  if [[ -n "$CSV_OUTPUT" ]]; then
    printf '"%s","%s","%s","%s","%s","%s"\n' "$ts" "$mode" "$status" "$days" "$exit_code" "$base_name" >> "$CSV_OUTPUT"
  fi

  append_json_record "$ts" "$mode" "$status" "$days" "$exit_code" "$base_name"

  if [[ "$status" == "failed" && -z "$latest_failed_ts" ]]; then
    latest_failed_ts="$ts"
    latest_failed_report="$base_name"
  fi

  if [[ "$streak_closed" == "false" ]]; then
    if [[ "$status" == "failed" ]]; then
      consecutive_failed_from_latest=$((consecutive_failed_from_latest + 1))
    else
      streak_closed="true"
    fi
  fi

  summary_total=$((summary_total + 1))
  if [[ "$status" == "success" ]]; then
    summary_success=$((summary_success + 1))
  elif [[ "$status" == "failed" ]]; then
    summary_failed=$((summary_failed + 1))
  fi

  if [[ "$mode" == "preview" ]]; then
    summary_preview=$((summary_preview + 1))
  elif [[ "$mode" == "commit" ]]; then
    summary_commit=$((summary_commit + 1))
  fi

  if [[ "$trend_sample_size" -lt "$TREND_WINDOW" ]]; then
    trend_sample_size=$((trend_sample_size + 1))
    if [[ "$status" == "failed" ]]; then
      trend_failed=$((trend_failed + 1))
    fi
  fi
done

if [[ "$trend_sample_size" -gt 0 ]]; then
  recent_failure_rate="$(awk -v failed="$trend_failed" -v total="$trend_sample_size" 'BEGIN { printf "%.4f", failed/total }')"
  fail_rate_threshold_breached="$(awk -v rate="$recent_failure_rate" -v threshold="$FAIL_RATE_THRESHOLD" 'BEGIN { if (rate >= threshold) print "true"; else print "false" }')"
fi

if [[ "$fail_rate_threshold_breached" == "true" ]]; then
  if [[ "$consecutive_failed_from_latest" -ge 3 ]] || awk -v rate="$recent_failure_rate" 'BEGIN { exit !(rate >= 0.5) }'; then
    risk_level="critical"
  else
    risk_level="warning"
  fi

  if [[ "$EMIT_RISK_ALERT" == "true" ]]; then
    emit_risk_alert
  fi
fi

if [[ "$SHOW_SUMMARY" == "true" ]]; then
  echo
  echo "汇总"
  echo "- total: $summary_total"
  echo "- success: $summary_success"
  echo "- failed: $summary_failed"
  echo "- preview: $summary_preview"
  echo "- commit: $summary_commit"
  echo "- consecutive_failed_from_latest: $consecutive_failed_from_latest"
  echo "- latest_failed_timestamp: ${latest_failed_ts:--}"
  echo "- latest_failed_report: ${latest_failed_report:--}"
  echo "- trend_window: $TREND_WINDOW"
  echo "- trend_sample_size: $trend_sample_size"
  echo "- trend_failed: $trend_failed"
  echo "- recent_failure_rate: $recent_failure_rate"
  echo "- fail_rate_threshold: $FAIL_RATE_THRESHOLD"
  echo "- fail_rate_threshold_breached: $fail_rate_threshold_breached"
  echo "- risk_level: $risk_level"
  echo "- risk_alert_file: ${risk_alert_file:--}"
  echo "- risk_notification_message_id: ${risk_notification_message_id:--}"
  echo "- risk_webhook_pushed: $risk_webhook_pushed"
fi

if [[ -n "$CSV_OUTPUT" ]]; then
  echo
  echo "CSV 已导出: $CSV_OUTPUT" >&2
fi

if [[ "$JSON_OUTPUT" == "true" || -n "$JSON_FILE" ]]; then
  json_payload="{\"filters\":{\"limit\":$LIMIT,\"status\":\"$(json_escape "$STATUS_FILTER")\",\"mode\":\"$(json_escape "$MODE_FILTER")\",\"from\":\"$(json_escape "$FROM_TS")\",\"to\":\"$(json_escape "$TO_TS")\",\"trend_window\":$TREND_WINDOW,\"fail_rate_threshold\":$FAIL_RATE_THRESHOLD,\"emit_risk_alert\":$EMIT_RISK_ALERT,\"risk_alert_dir\":\"$(json_escape "$RISK_ALERT_DIR")\"},\"summary\":{\"total\":$summary_total,\"success\":$summary_success,\"failed\":$summary_failed,\"preview\":$summary_preview,\"commit\":$summary_commit,\"consecutive_failed_from_latest\":$consecutive_failed_from_latest,\"latest_failed_timestamp\":\"$(json_escape "$latest_failed_ts")\",\"latest_failed_report\":\"$(json_escape "$latest_failed_report")\",\"trend_sample_size\":$trend_sample_size,\"trend_failed\":$trend_failed,\"recent_failure_rate\":$recent_failure_rate,\"fail_rate_threshold\":$FAIL_RATE_THRESHOLD,\"fail_rate_threshold_breached\":$fail_rate_threshold_breached,\"risk_level\":\"$(json_escape "$risk_level")\",\"risk_alert_file\":\"$(json_escape "$risk_alert_file")\",\"risk_notification_message_id\":\"$(json_escape "$risk_notification_message_id")\",\"risk_webhook_pushed\":$risk_webhook_pushed},\"records\":[${records_json}]}"

  if [[ "$JSON_OUTPUT" == "true" ]]; then
    echo
    echo "$json_payload"
  fi

  if [[ -n "$JSON_FILE" ]]; then
    printf '%s\n' "$json_payload" > "$JSON_FILE"
    echo "JSON 已导出: $JSON_FILE" >&2
  fi
fi
