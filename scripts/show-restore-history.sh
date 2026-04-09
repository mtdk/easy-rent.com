#!/usr/bin/env bash
set -euo pipefail

# 查看恢复与验证历史报告
#
# 用法:
#   ./scripts/show-restore-history.sh [limit] [--failed-only] [--status=<all|success|failed>] [--mode=<all|preview|commit>] [--show-paths] [--summary] [--csv=<path>] [--json] [--json-file=<path>]
#
# 示例:
#   ./scripts/show-restore-history.sh
#   ./scripts/show-restore-history.sh 20 --failed-only
#   ./scripts/show-restore-history.sh 30 --status=success --mode=preview
#   ./scripts/show-restore-history.sh 10 --show-paths
#   ./scripts/show-restore-history.sh 50 --summary --csv=storage/logs/restore-history.csv
#   ./scripts/show-restore-history.sh 50 --status=failed --mode=commit --json
#   ./scripts/show-restore-history.sh 50 --status=all --mode=all --json-file=storage/logs/restore-history.json

LIMIT="10"
FAILED_ONLY="0"
STATUS_FILTER="all"
MODE_FILTER="all"
SHOW_PATHS="0"
SHOW_SUMMARY="0"
CSV_OUTPUT=""
JSON_STDOUT="0"
JSON_FILE=""

for arg in "$@"; do
  case "$arg" in
    --failed-only)
      FAILED_ONLY="1"
      ;;
    --status=*)
      STATUS_FILTER="${arg#--status=}"
      ;;
    --mode=*)
      MODE_FILTER="${arg#--mode=}"
      ;;
    --show-paths)
      SHOW_PATHS="1"
      ;;
    --summary)
      SHOW_SUMMARY="1"
      ;;
    --csv=*)
      CSV_OUTPUT="${arg#--csv=}"
      ;;
    --json)
      JSON_STDOUT="1"
      ;;
    --json-file=*)
      JSON_FILE="${arg#--json-file=}"
      ;;
    *)
      if [[ "$arg" =~ ^[0-9]+$ ]]; then
        LIMIT="$arg"
      else
        echo "错误: 未知参数 $arg" >&2
        exit 1
      fi
      ;;
  esac
done

if ! [[ "$LIMIT" =~ ^[0-9]+$ ]] || [[ "$LIMIT" -le 0 ]]; then
  echo "错误: limit 必须是正整数" >&2
  exit 1
fi

if [[ "$FAILED_ONLY" == "1" ]]; then
  STATUS_FILTER="failed"
fi

if [[ "$STATUS_FILTER" != "all" && "$STATUS_FILTER" != "success" && "$STATUS_FILTER" != "failed" ]]; then
  echo "错误: --status 仅支持 all|success|failed" >&2
  exit 1
fi

if [[ "$MODE_FILTER" != "all" && "$MODE_FILTER" != "preview" && "$MODE_FILTER" != "commit" ]]; then
  echo "错误: --mode 仅支持 all|preview|commit" >&2
  exit 1
fi

if [[ -n "$CSV_OUTPUT" ]]; then
  csv_dir="$(dirname "$CSV_OUTPUT")"
  mkdir -p "$csv_dir"
fi

if [[ -n "$JSON_FILE" ]]; then
  json_dir="$(dirname "$JSON_FILE")"
  mkdir -p "$json_dir"
fi

EMIT_JSON="0"
if [[ "$JSON_STDOUT" == "1" || -n "$JSON_FILE" ]]; then
  EMIT_JSON="1"
fi

LOG_DIR="storage/logs"
if [[ ! -d "$LOG_DIR" ]]; then
  echo "未找到日志目录: $LOG_DIR"
  exit 0
fi

files=()
while IFS= read -r line; do
  files+=("$line")
done < <(ls -1t "$LOG_DIR"/restore-verify_*.json 2>/dev/null | head -n "$LIMIT" || true)

if [[ "${#files[@]}" -eq 0 ]]; then
  echo "未找到恢复历史报告（$LOG_DIR/restore-verify_*.json）"
  exit 0
fi

if [[ "$EMIT_JSON" != "1" ]]; then
  printf '%-24s %-8s %-8s %-8s %-16s %-28s\n' "时间" "模式" "状态" "退出码" "失败阶段" "备份文件"
  printf '%-24s %-8s %-8s %-8s %-16s %-28s\n' "------------------------" "--------" "--------" "--------" "----------------" "----------------------------"
fi

shown=0
success_count=0
failed_count=0
preview_count=0
commit_count=0

csv_lines=()
csv_lines+=("时间,模式,状态,退出码,失败阶段,备份文件,报告路径,日志路径")

json_rows=()

escape_csv_field() {
  local value="$1"
  value="${value//\"/\"\"}"
  printf '"%s"' "$value"
}

for file in "${files[@]}"; do
  parsed="$(php -r '
    $f = $argv[1];
    $data = @json_decode((string)@file_get_contents($f), true);
    if (!is_array($data)) { echo "INVALID"; exit(0); }
    $ts = (string)($data["timestamp"] ?? "");
    $mode = (string)($data["mode"] ?? "");
    $status = (string)($data["status"] ?? "");
    $exit = (string)($data["exit_code"] ?? "");
    $stage = (string)($data["failed_stage"] ?? "");
    $backup = (string)($data["backup_file"] ?? "");
    $log = (string)($data["log_file"] ?? "");
    if ($backup === "") { $backup = "-"; }
    if ($stage === "") { $stage = "-"; }
    $backupDisplay = $backup;
    if (strlen($backupDisplay) > 28) { $backupDisplay = substr($backupDisplay, 0, 25) . "..."; }
    echo $ts . "\t" . $mode . "\t" . $status . "\t" . $exit . "\t" . $stage . "\t" . $backupDisplay . "\t" . $backup . "\t" . $f . "\t" . $log;
  ' "$file")"

  if [[ "$parsed" == "INVALID" ]]; then
    continue
  fi

  IFS=$'\t' read -r ts mode status exit_code stage backup_display backup_full report_path log_path <<< "$parsed"

  if [[ "$STATUS_FILTER" != "all" && "$status" != "$STATUS_FILTER" ]]; then
    continue
  fi

  if [[ "$MODE_FILTER" != "all" && "$mode" != "$MODE_FILTER" ]]; then
    continue
  fi

  if [[ "$EMIT_JSON" != "1" ]]; then
    printf '%-24s %-8s %-8s %-8s %-16s %-28s\n' "$ts" "$mode" "$status" "$exit_code" "$stage" "$backup_display"
    if [[ "$SHOW_PATHS" == "1" ]]; then
      echo "  report: $report_path"
      echo "  log:    ${log_path:--}"
    fi
  fi

  if [[ "$status" == "success" ]]; then
    success_count=$((success_count + 1))
  elif [[ "$status" == "failed" ]]; then
    failed_count=$((failed_count + 1))
  fi

  if [[ "$mode" == "preview" ]]; then
    preview_count=$((preview_count + 1))
  elif [[ "$mode" == "commit" ]]; then
    commit_count=$((commit_count + 1))
  fi

  if [[ -n "$CSV_OUTPUT" ]]; then
    csv_lines+=("$(escape_csv_field "$ts"),$(escape_csv_field "$mode"),$(escape_csv_field "$status"),$(escape_csv_field "$exit_code"),$(escape_csv_field "$stage"),$(escape_csv_field "$backup_full"),$(escape_csv_field "$report_path"),$(escape_csv_field "${log_path:--}")")
  fi

  if [[ "$EMIT_JSON" == "1" ]]; then
    json_rows+=("$ts"$'\t'"$mode"$'\t'"$status"$'\t'"$exit_code"$'\t'"$stage"$'\t'"$backup_full"$'\t'"$report_path"$'\t'"${log_path:--}")
  fi

  shown=$((shown + 1))
done

if [[ -n "$CSV_OUTPUT" ]]; then
  {
    for line in "${csv_lines[@]}"; do
      echo "$line"
    done
  } > "$CSV_OUTPUT"
fi

if [[ "$EMIT_JSON" == "1" ]]; then
  json_tmp="$(mktemp)"
  {
    for row in "${json_rows[@]}"; do
      echo "$row"
    done
  } > "$json_tmp"

  json_payload="$(php -r '
    $rowsFile = $argv[1];
    $limit = (int)$argv[2];
    $statusFilter = (string)$argv[3];
    $modeFilter = (string)$argv[4];
    $csvOutput = (string)$argv[5];
    $shown = (int)$argv[6];
    $success = (int)$argv[7];
    $failed = (int)$argv[8];
    $preview = (int)$argv[9];
    $commit = (int)$argv[10];

    $records = [];
    $lines = @file($rowsFile, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
      foreach ($lines as $line) {
        if ($line === "") {
          continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) < 8) {
          continue;
        }
        $records[] = [
          "timestamp" => (string)$parts[0],
          "mode" => (string)$parts[1],
          "status" => (string)$parts[2],
          "exit_code" => (string)$parts[3],
          "failed_stage" => (string)$parts[4],
          "backup_file" => (string)$parts[5],
          "report_file" => (string)$parts[6],
          "log_file" => (string)$parts[7],
        ];
      }
    }

    $result = [
      "filters" => [
        "limit" => $limit,
        "status" => $statusFilter,
        "mode" => $modeFilter,
      ],
      "summary" => [
        "shown" => $shown,
        "success" => $success,
        "failed" => $failed,
        "preview" => $preview,
        "commit" => $commit,
      ],
      "records" => $records,
    ];

    if ($csvOutput !== "") {
      $result["artifacts"] = ["csv_file" => $csvOutput];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  ' "$json_tmp" "$LIMIT" "$STATUS_FILTER" "$MODE_FILTER" "$CSV_OUTPUT" "$shown" "$success_count" "$failed_count" "$preview_count" "$commit_count")"

  if [[ "$JSON_STDOUT" == "1" ]]; then
    printf '%s\n' "$json_payload"
  fi

  if [[ -n "$JSON_FILE" ]]; then
    printf '%s\n' "$json_payload" > "$JSON_FILE"
    echo "JSON 已导出: $JSON_FILE" >&2
  fi

  rm -f "$json_tmp"
elif [[ "$shown" -eq 0 ]]; then
  if [[ "$FAILED_ONLY" == "1" ]]; then
    echo "未找到失败记录"
  else
    echo "无可展示记录"
  fi
else
  if [[ "$SHOW_SUMMARY" == "1" ]]; then
    echo "----------------------------------------"
    echo "汇总"
    echo "- 命中记录: $shown"
    echo "- 成功: $success_count"
    echo "- 失败: $failed_count"
    echo "- preview: $preview_count"
    echo "- commit: $commit_count"
  fi

  if [[ -n "$CSV_OUTPUT" ]]; then
    echo "CSV 已导出: $CSV_OUTPUT"
  fi
fi
