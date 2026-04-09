#!/usr/bin/env bash
set -euo pipefail

# 夜间总控连续超阈值告警验收用例（preview/commit）
# - 在隔离日志目录构造历史样本
# - 验证 nightly 与 api-access 两类历史在 preview/commit 维度均触发 fail_rate_threshold_breached
#
# 用法:
#   ./scripts/verify-nightly-threshold-cases.sh

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if ! command -v php >/dev/null 2>&1; then
  echo "错误: 未找到 php 命令" >&2
  exit 1
fi

TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/easy-rent-nightly-threshold.XXXXXX")"
LOG_DIR="$TMP_DIR/logs"
mkdir -p "$LOG_DIR"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

write_nightly_case() {
  local mode="$1"
  local stamp="$2"
  local status="$3"
  cat > "$LOG_DIR/nightly-maintenance_${mode}_${stamp}.json" <<EOF
{
  "mode": "$mode",
  "status": "$status",
  "timestamp": "2026-04-07T01:00:00+0800",
  "duration_seconds": 12,
  "failed_stage": "",
  "exit_code": 0,
  "log_file": "storage/logs/nightly-maintenance_${mode}_${stamp}.log"
}
EOF
}

write_api_case() {
  local mode="$1"
  local stamp="$2"
  local status="$3"
  cat > "$LOG_DIR/api-access-retention_${mode}_${stamp}.json" <<EOF
{
  "mode": "$mode",
  "status": "$status",
  "timestamp": "2026-04-07T01:00:00+0800",
  "retention_days": 90,
  "failed_stage": "",
  "exit_code": 0,
  "log_file": "storage/logs/api-access-retention_${mode}_${stamp}.log"
}
EOF
}

# preview 模式: 4 条样本中 3 条失败（最近两条连续失败）
write_nightly_case preview 20260407_010100 failed
write_nightly_case preview 20260407_010000 failed
write_nightly_case preview 20260407_005900 failed
write_nightly_case preview 20260407_005800 success

write_api_case preview 20260407_010100 failed
write_api_case preview 20260407_010000 failed
write_api_case preview 20260407_005900 failed
write_api_case preview 20260407_005800 success

# commit 模式: 4 条样本中 3 条失败（最近两条连续失败）
write_nightly_case commit 20260407_020100 failed
write_nightly_case commit 20260407_020000 failed
write_nightly_case commit 20260407_015900 failed
write_nightly_case commit 20260407_015800 success

write_api_case commit 20260407_020100 failed
write_api_case commit 20260407_020000 failed
write_api_case commit 20260407_015900 failed
write_api_case commit 20260407_015800 success

# 按修改时间排序，确保“最近记录”顺序稳定
for f in "$LOG_DIR"/*.json; do
  touch "$f"
done

assert_history_json() {
  local json="$1"
  local label="$2"

  php -r '
$data = json_decode($argv[1], true);
if (!is_array($data)) {
    fwrite(STDERR, "JSON parse failed\n");
    exit(2);
}
$summary = $data["summary"] ?? null;
if (!is_array($summary)) {
    fwrite(STDERR, "summary missing\n");
    exit(3);
}
if (($summary["fail_rate_threshold_breached"] ?? false) !== true) {
    fwrite(STDERR, "threshold not breached\n");
    exit(4);
}
if (($summary["risk_level"] ?? "") !== "critical") {
    fwrite(STDERR, "risk level is not critical\n");
    exit(5);
}
if ((int)($summary["consecutive_failed_from_latest"] ?? 0) < 2) {
    fwrite(STDERR, "consecutive failures is less than 2\n");
    exit(6);
}
' "$json" || {
    echo "错误: $label 验收断言失败" >&2
    echo "$json" >&2
    exit 1
  }
}

run_case() {
  local mode="$1"

  local nightly_json
  nightly_json="$(LOG_DIR="$LOG_DIR" ./scripts/show-nightly-maintenance-history.sh 20 --mode="$mode" --trend-window=4 --fail-rate-threshold=0.50 --json | tail -n 1)"
  assert_history_json "$nightly_json" "nightly-$mode"

  local api_json
  api_json="$(LOG_DIR="$LOG_DIR" ./scripts/show-api-access-retention-history.sh 20 --mode="$mode" --trend-window=4 --fail-rate-threshold=0.50 --json | tail -n 1)"
  assert_history_json "$api_json" "api-access-$mode"

  echo "[PASS] mode=$mode 阈值告警验收通过"
}

run_case preview
run_case commit

echo "夜间总控连续超阈值告警验收用例完成（preview/commit）"
