#!/usr/bin/env bash
set -euo pipefail

# P2 基线总入口：性能压测 + 安全扫描
# 用法:
#   ./scripts/run-p2-baseline.sh [mode] [base_url]
# 参数:
#   mode: preview | strict (默认 preview)
#   base_url: 默认 http://127.0.0.1:8000

MODE="${1:-preview}"
BASE_URL="${2:-http://127.0.0.1:8000}"

if [[ "$MODE" != "preview" && "$MODE" != "strict" ]]; then
  echo "错误: mode 必须是 preview 或 strict" >&2
  exit 1
fi

if [[ ! -x "scripts/run-performance-baseline.sh" ]]; then
  echo "错误: 未找到可执行脚本 scripts/run-performance-baseline.sh" >&2
  exit 1
fi

if [[ ! -x "scripts/run-security-baseline.sh" ]]; then
  echo "错误: 未找到可执行脚本 scripts/run-security-baseline.sh" >&2
  exit 1
fi

echo "========================================"
echo "P2 基线自动化"
echo "mode: $MODE"
echo "base_url: $BASE_URL"
echo "time: $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

scripts/run-performance-baseline.sh "$MODE" "$BASE_URL"
scripts/run-security-baseline.sh "$MODE" "$BASE_URL"

echo "========================================"
echo "P2 基线完成"
echo "========================================"
