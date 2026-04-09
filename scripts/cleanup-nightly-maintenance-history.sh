#!/usr/bin/env bash
set -eo pipefail

# 清理夜间运维历史日志与报告
#
# 用法:
#   ./scripts/cleanup-nightly-maintenance-history.sh [mode] [keep_days] [keep_count]
#
# 参数:
#   mode: preview | commit (默认 preview)
#   keep_days: 保留天数（默认 30）
#   keep_count: 至少保留最近报告条数（默认 50）

MODE="${1:-preview}"
KEEP_DAYS="${2:-30}"
KEEP_COUNT="${3:-50}"
LOG_DIR="storage/logs"

if [[ "$MODE" != "preview" && "$MODE" != "commit" ]]; then
  echo "错误: mode 必须是 preview 或 commit" >&2
  exit 1
fi

if ! [[ "$KEEP_DAYS" =~ ^[0-9]+$ ]] || [[ "$KEEP_DAYS" -lt 0 ]]; then
  echo "错误: keep_days 必须是非负整数" >&2
  exit 1
fi

if ! [[ "$KEEP_COUNT" =~ ^[0-9]+$ ]] || [[ "$KEEP_COUNT" -lt 0 ]]; then
  echo "错误: keep_count 必须是非负整数" >&2
  exit 1
fi

if [[ ! -d "$LOG_DIR" ]]; then
  echo "日志目录不存在: $LOG_DIR"
  exit 0
fi

threshold_epoch="$(date -v-"${KEEP_DAYS}"d +%s 2>/dev/null || true)"
if [[ -z "$threshold_epoch" ]]; then
  threshold_epoch="$(date -d "-${KEEP_DAYS} days" +%s)"
fi

echo "========================================"
echo "夜间运维历史清理任务"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "日志目录: $LOG_DIR"
echo "保留天数: $KEEP_DAYS"
echo "最少保留条数: $KEEP_COUNT"
echo "========================================"

reports=()
while IFS= read -r f; do
  reports+=("$f")
done < <(ls -1t "$LOG_DIR"/nightly-maintenance_*.json 2>/dev/null || true)

if [[ "${#reports[@]}" -eq 0 ]]; then
  echo "未发现可清理夜间总控报告文件"
  exit 0
fi

delete_reports=()
delete_logs=()

index=0
for report in "${reports[@]}"; do
  if [[ "$index" -lt "$KEEP_COUNT" ]]; then
    index=$((index + 1))
    continue
  fi

  file_epoch="$(stat -f %m "$report" 2>/dev/null || true)"
  if [[ -z "$file_epoch" ]]; then
    file_epoch="$(stat -c %Y "$report" 2>/dev/null || true)"
  fi

  if [[ -z "$file_epoch" ]]; then
    index=$((index + 1))
    continue
  fi

  if [[ "$file_epoch" -le "$threshold_epoch" ]]; then
    delete_reports+=("$report")
    base="${report%.json}"
    log_file="${base}.log"
    if [[ -f "$log_file" ]]; then
      delete_logs+=("$log_file")
    fi
  fi

  index=$((index + 1))
done

while IFS= read -r logf; do
  base="${logf%.log}"
  jsonf="${base}.json"
  if [[ -f "$jsonf" ]]; then
    continue
  fi

  file_epoch="$(stat -f %m "$logf" 2>/dev/null || true)"
  if [[ -z "$file_epoch" ]]; then
    file_epoch="$(stat -c %Y "$logf" 2>/dev/null || true)"
  fi
  if [[ -n "$file_epoch" && "$file_epoch" -le "$threshold_epoch" ]]; then
    delete_logs+=("$logf")
  fi
done < <(ls -1 "$LOG_DIR"/nightly-maintenance_*.log 2>/dev/null || true)

uniq_logs=()
for l in "${delete_logs[@]}"; do
  exists=0
  for e in "${uniq_logs[@]:-}"; do
    if [[ "$e" == "$l" ]]; then
      exists=1
      break
    fi
  done
  if [[ "$exists" -eq 0 ]]; then
    uniq_logs+=("$l")
  fi
done

echo "待清理报告数: ${#delete_reports[@]}"
echo "待清理日志数: ${#uniq_logs[@]}"

if [[ "${#delete_reports[@]}" -eq 0 && "${#uniq_logs[@]}" -eq 0 ]]; then
  echo "无需清理"
  exit 0
fi

if [[ "$MODE" == "preview" ]]; then
  echo "[preview] 计划删除文件:"
  for r in "${delete_reports[@]}"; do
    echo "- $r"
  done
  for l in "${uniq_logs[@]}"; do
    echo "- $l"
  done
  exit 0
fi

for r in "${delete_reports[@]}"; do
  rm -f "$r"
done
for l in "${uniq_logs[@]}"; do
  rm -f "$l"
done

echo "清理完成"
echo "- 已删除报告: ${#delete_reports[@]}"
echo "- 已删除日志: ${#uniq_logs[@]}"
