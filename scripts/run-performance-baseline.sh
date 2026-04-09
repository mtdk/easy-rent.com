#!/usr/bin/env bash
set -euo pipefail

# 性能基线脚本
# 用法:
#   ./scripts/run-performance-baseline.sh [mode] [base_url] [requests] [concurrency]
# 参数:
#   mode: preview | strict (默认 preview)
#   base_url: 默认 http://127.0.0.1:8000
#   requests: 每个端点请求数，默认 30
#   concurrency: 并发数，默认 5

MODE="${1:-preview}"
BASE_URL="${2:-http://127.0.0.1:8000}"
REQUESTS="${3:-30}"
CONCURRENCY="${4:-5}"
TIMEOUT_SECONDS="${PERF_TIMEOUT_SECONDS:-8}"
AVG_THRESHOLD_SECONDS="${PERF_AVG_THRESHOLD_SECONDS:-1.20}"
P95_THRESHOLD_SECONDS="${PERF_P95_THRESHOLD_SECONDS:-2.00}"
SUCCESS_RATE_THRESHOLD="${PERF_SUCCESS_RATE_THRESHOLD:-0.95}"

if [[ "$MODE" != "preview" && "$MODE" != "strict" ]]; then
  echo "错误: mode 必须是 preview 或 strict" >&2
  exit 1
fi

if ! [[ "$REQUESTS" =~ ^[0-9]+$ ]] || [[ "$REQUESTS" -le 0 ]]; then
  echo "错误: requests 必须为正整数" >&2
  exit 1
fi

if ! [[ "$CONCURRENCY" =~ ^[0-9]+$ ]] || [[ "$CONCURRENCY" -le 0 ]]; then
  echo "错误: concurrency 必须为正整数" >&2
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "错误: 未找到 curl 命令" >&2
  exit 1
fi

if ! command -v awk >/dev/null 2>&1; then
  echo "错误: 未找到 awk 命令" >&2
  exit 1
fi

LOG_DIR="storage/logs"
mkdir -p "$LOG_DIR"
TS="$(date +%Y%m%d_%H%M%S)"
REPORT_FILE="$LOG_DIR/performance-baseline_${MODE}_${TS}.json"
LOG_FILE="$LOG_DIR/performance-baseline_${MODE}_${TS}.log"

ENDPOINTS_RAW="${PERF_ENDPOINTS:-/,/login,/dashboard,/payments,/reports/financial}"
IFS=',' read -r -a ENDPOINTS <<< "$ENDPOINTS_RAW"

run_endpoint() {
  local endpoint="$1"
  local url="$BASE_URL$endpoint"
  local tmp
  tmp="$(mktemp "${TMPDIR:-/tmp}/easy-rent-perf.XXXXXX")"

  seq "$REQUESTS" | xargs -I{} -P "$CONCURRENCY" sh -c '
    curl -sS -o /dev/null -m "$0" -w "%{time_total} %{http_code}\n" "$1" 2>/dev/null
  ' "$TIMEOUT_SECONDS" "$url" > "$tmp"

  local total
  local ok
  local avg
  local p95
  local max
  local min

  total="$(wc -l < "$tmp" | tr -d ' ')"
  ok="$(awk '$2 ~ /^2|3/ {c++} END {print c+0}' "$tmp")"
  avg="$(awk '{if($1 != "timeout") {sum+=$1; c++}} END {if(c==0) print "999.0000"; else printf "%.4f", sum/c}' "$tmp")"
  min="$(awk 'BEGIN{m=999999} {if($1 != "timeout" && $1<m) m=$1} END {if(m==999999) print "999.0000"; else printf "%.4f", m}' "$tmp")"
  max="$(awk 'BEGIN{m=0} {if($1 != "timeout" && $1>m) m=$1} END {printf "%.4f", m}' "$tmp")"
  p95="$(awk '$1 != "timeout" {print $1}' "$tmp" | sort -n | awk '{a[NR]=$1} END {if(NR==0){print "999.0000"} else {idx=int((NR*95+99)/100); if(idx<1)idx=1; if(idx>NR)idx=NR; printf "%.4f", a[idx]}}')"

  local success_rate
  success_rate="$(awk -v ok="$ok" -v total="$total" 'BEGIN {if(total==0) print "0.0000"; else printf "%.4f", ok/total}')"

  rm -f "$tmp"

  echo "$endpoint|$total|$ok|$success_rate|$min|$avg|$p95|$max"
}

strict_fail="false"
results_json=""

{
  echo "性能基线"
  echo "- mode: $MODE"
  echo "- base_url: $BASE_URL"
  echo "- requests_per_endpoint: $REQUESTS"
  echo "- concurrency: $CONCURRENCY"
  echo "- thresholds(avg/p95/success_rate): $AVG_THRESHOLD_SECONDS / $P95_THRESHOLD_SECONDS / $SUCCESS_RATE_THRESHOLD"
} | tee "$LOG_FILE"

for endpoint in "${ENDPOINTS[@]}"; do
  endpoint="$(echo "$endpoint" | xargs)"
  [[ -z "$endpoint" ]] && continue

  line="$(run_endpoint "$endpoint")"
  IFS='|' read -r e total ok success min avg p95 max <<< "$line"

  endpoint_fail="false"
  if awk -v v="$avg" -v t="$AVG_THRESHOLD_SECONDS" 'BEGIN{exit !(v>t)}'; then endpoint_fail="true"; fi
  if awk -v v="$p95" -v t="$P95_THRESHOLD_SECONDS" 'BEGIN{exit !(v>t)}'; then endpoint_fail="true"; fi
  if awk -v v="$success" -v t="$SUCCESS_RATE_THRESHOLD" 'BEGIN{exit !(v<t)}'; then endpoint_fail="true"; fi

  if [[ "$endpoint_fail" == "true" ]]; then
    strict_fail="true"
  fi

  printf -- "- endpoint=%s total=%s ok=%s success_rate=%s min=%ss avg=%ss p95=%ss max=%ss\n" "$e" "$total" "$ok" "$success" "$min" "$avg" "$p95" "$max" | tee -a "$LOG_FILE"

  item="{\"endpoint\":\"$e\",\"total\":$total,\"ok\":$ok,\"success_rate\":$success,\"min_seconds\":$min,\"avg_seconds\":$avg,\"p95_seconds\":$p95,\"max_seconds\":$max,\"threshold_breached\":$endpoint_fail}"
  if [[ -z "$results_json" ]]; then
    results_json="$item"
  else
    results_json="$results_json,$item"
  fi
done

cat > "$REPORT_FILE" <<EOF
{
  "mode": "$MODE",
  "base_url": "$BASE_URL",
  "timestamp": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "requests_per_endpoint": $REQUESTS,
  "concurrency": $CONCURRENCY,
  "thresholds": {
    "avg_seconds": $AVG_THRESHOLD_SECONDS,
    "p95_seconds": $P95_THRESHOLD_SECONDS,
    "success_rate": $SUCCESS_RATE_THRESHOLD
  },
  "strict_fail": $strict_fail,
  "log_file": "$LOG_FILE",
  "results": [${results_json}]
}
EOF

echo "报告文件: $REPORT_FILE" | tee -a "$LOG_FILE"

if [[ "$MODE" == "strict" && "$strict_fail" == "true" ]]; then
  echo "性能基线未通过（strict）" >&2
  exit 2
fi

echo "性能基线完成" | tee -a "$LOG_FILE"
