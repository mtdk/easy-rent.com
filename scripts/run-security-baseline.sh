#!/usr/bin/env bash
set -euo pipefail

# 安全基线脚本
# 用法:
#   ./scripts/run-security-baseline.sh [mode] [base_url]
# 参数:
#   mode: preview | strict (默认 preview)
#   base_url: 默认 http://127.0.0.1:8000

MODE="${1:-preview}"
BASE_URL="${2:-http://127.0.0.1:8000}"
LOG_DIR="storage/logs"
mkdir -p "$LOG_DIR"
TS="$(date +%Y%m%d_%H%M%S)"
REPORT_FILE="$LOG_DIR/security-baseline_${MODE}_${TS}.json"
LOG_FILE="$LOG_DIR/security-baseline_${MODE}_${TS}.log"

if [[ "$MODE" != "preview" && "$MODE" != "strict" ]]; then
  echo "错误: mode 必须是 preview 或 strict" >&2
  exit 1
fi

strict_fail="false"
composer_status="skipped"
composer_vuln_count=0
composer_reason=""

{
  echo "安全基线"
  echo "- mode: $MODE"
  echo "- base_url: $BASE_URL"
} | tee "$LOG_FILE"

# 1) 依赖漏洞扫描（若 composer audit 可用）
if command -v composer >/dev/null 2>&1 && [[ -f composer.lock ]]; then
  if composer audit --help >/dev/null 2>&1; then
    audit_json_file="$(mktemp "${TMPDIR:-/tmp}/easy-rent-audit.XXXXXX")"
    set +e
    composer audit --locked --format=json > "$audit_json_file" 2>/dev/null
    ec=$?
    set -e

    composer_vuln_count="$(php -r '
$data=json_decode(file_get_contents($argv[1]), true);
if(!is_array($data)){echo 0; exit;}
$advisories=$data["advisories"] ?? [];
$count=0;
if(is_array($advisories)){
  foreach($advisories as $pkg=>$list){
    if(is_array($list)){$count += count($list);} }
}
echo $count;
' "$audit_json_file")"

    if [[ "$ec" -eq 0 && "$composer_vuln_count" -eq 0 ]]; then
      composer_status="passed"
    else
      composer_status="failed"
      strict_fail="true"
    fi
    rm -f "$audit_json_file"
  else
    composer_status="skipped"
    composer_reason="composer audit 不可用"
  fi
else
  composer_status="skipped"
  composer_reason="缺少 composer 或 composer.lock"
fi

echo "- dependency_audit: status=$composer_status vulnerabilities=$composer_vuln_count ${composer_reason}" | tee -a "$LOG_FILE"

# 2) 危险函数静态扫描（仅扫描业务代码）
danger_pattern='\\b(eval|exec|shell_exec|passthru|proc_open|popen)\\s*\\('
if command -v rg >/dev/null 2>&1; then
  scan_output="$(rg -n --pcre2 "$danger_pattern" app public 2>/dev/null || true)"
else
  scan_output="$(grep -R -n -E "$danger_pattern" app public 2>/dev/null || true)"
fi
if [[ -n "$scan_output" ]]; then
  dangerous_count="$(printf '%s\n' "$scan_output" | sed '/^$/d' | wc -l | tr -d ' ')"
  strict_fail="true"
else
  dangerous_count=0
fi

echo "- dangerous_function_hits: $dangerous_count" | tee -a "$LOG_FILE"

# 3) 基础安全响应头检查（可跳过）
headers_status="skipped"
missing_headers_json="[]"
missing_headers_count=0
header_required=("X-Frame-Options" "X-Content-Type-Options" "Referrer-Policy" "Content-Security-Policy")
header_endpoints=("/" "/login")

if command -v curl >/dev/null 2>&1; then
  headers_status="passed"
  missing_items=""
  for ep in "${header_endpoints[@]}"; do
    url="$BASE_URL$ep"
    resp="$(curl -sS -I -m 8 "$url" || true)"
    if [[ -z "$resp" ]]; then
      headers_status="failed"
      strict_fail="true"
      missing_items+="{\"endpoint\":\"$ep\",\"header\":\"<unreachable>\"},"
      missing_headers_count=$((missing_headers_count + 1))
      continue
    fi

    for h in "${header_required[@]}"; do
      if ! printf '%s\n' "$resp" | grep -iq "^$h:"; then
        headers_status="failed"
        strict_fail="true"
        missing_items+="{\"endpoint\":\"$ep\",\"header\":\"$h\"},"
        missing_headers_count=$((missing_headers_count + 1))
      fi
    done
  done

  if [[ -n "$missing_items" ]]; then
    missing_headers_json="[${missing_items%,}]"
  fi
fi

echo "- security_headers: status=$headers_status missing=$missing_headers_count" | tee -a "$LOG_FILE"

cat > "$REPORT_FILE" <<EOF
{
  "mode": "$MODE",
  "base_url": "$BASE_URL",
  "timestamp": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "dependency_audit": {
    "status": "$composer_status",
    "vulnerability_count": $composer_vuln_count,
    "reason": "${composer_reason}"
  },
  "dangerous_function_scan": {
    "hit_count": $dangerous_count,
    "pattern": "${danger_pattern}"
  },
  "security_headers": {
    "status": "$headers_status",
    "required": ["X-Frame-Options", "X-Content-Type-Options", "Referrer-Policy", "Content-Security-Policy"],
    "missing_count": $missing_headers_count,
    "missing": $missing_headers_json
  },
  "strict_fail": $strict_fail,
  "log_file": "$LOG_FILE"
}
EOF

echo "报告文件: $REPORT_FILE" | tee -a "$LOG_FILE"

if [[ "$MODE" == "strict" && "$strict_fail" == "true" ]]; then
  echo "安全基线未通过（strict）" >&2
  exit 2
fi

echo "安全基线完成" | tee -a "$LOG_FILE"
