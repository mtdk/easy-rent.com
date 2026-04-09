#!/usr/bin/env bash
set -euo pipefail

# 历史导入总控脚本
#
# 用法:
#   ./scripts/run-import-pipeline.sh [mode] [xlsx_path] [db_user] [db_password] [db_name] [db_host] [db_port]
#
# 参数:
#   mode: preview | commit (默认 preview)

MODE="${1:-preview}"
XLSX_PATH="${2:-docs/A.xlsx}"
DB_USER="${3:-xmtdk}"
DB_PASSWORD="${4:-}"
DB_NAME="${5:-easy_rent}"
DB_HOST="${6:-127.0.0.1}"
DB_PORT="${7:-3306}"

if [[ "$MODE" != "preview" && "$MODE" != "commit" ]]; then
  echo "错误: mode 必须是 preview 或 commit" >&2
  exit 1
fi

if [[ ! -f "$XLSX_PATH" ]]; then
  echo "错误: xlsx 文件不存在: $XLSX_PATH" >&2
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "错误: 未找到 php 命令" >&2
  exit 1
fi

if ! command -v mysql >/dev/null 2>&1; then
  echo "错误: 未找到 mysql 命令" >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "错误: 未找到 jq 命令（脚本依赖 jq 读取 JSON）" >&2
  exit 1
fi

PREVIEW_JSON="storage/import-preview/a-xlsx-preview.json"
DRY_RUN_JSON="storage/import-preview/a-xlsx-dry-run-report.json"
BOOTSTRAP_RESULT_JSON="storage/import-preview/a-xlsx-bootstrap-commit-result.json"
AMBIGUOUS_RESOLVED_JSON="storage/import-preview/a-xlsx-ambiguous-resolved-report.json"
PAYMENTS_RESULT_JSON="storage/import-preview/a-xlsx-payments-commit-result.json"
PIPELINE_SUMMARY_JSON="storage/import-preview/a-xlsx-pipeline-summary.json"

COMMIT_FLAG=""
if [[ "$MODE" == "commit" ]]; then
  COMMIT_FLAG="--commit"
fi

echo "========================================"
echo "A.xlsx 导入总控"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

echo "[1/8] 生成预览 JSON"
php scripts/preview-rent-xlsx.php "$XLSX_PATH" "$PREVIEW_JSON"

echo "[2/8] 生成 dry-run 报告"
php scripts/dry-run-import-rent-xlsx.php "$PREVIEW_JSON" "$DRY_RUN_JSON" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT"

if [[ "$MODE" == "commit" ]]; then
  echo "[3/8] 提交前自动备份数据库"
  ./scripts/backup-easy-rent.sh "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT" storage/backups
else
  echo "[3/8] 预览模式跳过数据库备份"
fi

bootstrap_properties=$(jq -r '.summary.bootstrap_property_candidate_count // 0' "$DRY_RUN_JSON")
bootstrap_contracts=$(jq -r '.summary.bootstrap_contract_candidate_count // 0' "$DRY_RUN_JSON")

echo "[4/8] bootstrap 阶段"
if [[ "$bootstrap_properties" -gt 0 || "$bootstrap_contracts" -gt 0 ]]; then
  php scripts/commit-bootstrap-from-dry-run.php "$DRY_RUN_JSON" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT" $COMMIT_FLAG

  # 创建了房源/合同后，重跑 dry-run 获取最新可导入账单列表
  php scripts/dry-run-import-rent-xlsx.php "$PREVIEW_JSON" "$DRY_RUN_JSON" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT"
else
  echo "- 无需 bootstrap"
fi

importable_count=$(jq -r '.summary.importable_record_count // 0' "$DRY_RUN_JSON")
ambiguous_count=$(jq -r '.summary.ambiguous_record_count // 0' "$DRY_RUN_JSON")

echo "[5/8] 导入可匹配账单"
if [[ "$importable_count" -gt 0 ]]; then
  php scripts/commit-payments-from-dry-run.php "$DRY_RUN_JSON" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT" $COMMIT_FLAG
else
  echo "- 无可匹配账单可导入"
fi

echo "[6/8] 解析并处理歧义账单"
if [[ "$ambiguous_count" -gt 0 ]]; then
  php scripts/resolve-ambiguous-from-dry-run.php "$DRY_RUN_JSON" "$AMBIGUOUS_RESOLVED_JSON" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT"

  resolved_count=$(jq -r '.summary.resolved_count // 0' "$AMBIGUOUS_RESOLVED_JSON")
  if [[ "$resolved_count" -gt 0 ]]; then
    php scripts/commit-payments-from-dry-run.php "$AMBIGUOUS_RESOLVED_JSON" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_HOST" "$DB_PORT" $COMMIT_FLAG
  else
    echo "- 无可自动决策歧义记录"
  fi
else
  echo "- 无歧义记录"
fi

echo "[7/8] 生成验收快照"
payments_cnt=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" -D "$DB_NAME" -Nse "SELECT COUNT(*) FROM rent_payments;")
financial_cnt=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" -D "$DB_NAME" -Nse "SELECT COUNT(*) FROM financial_records;")
non_paid_cnt=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" -D "$DB_NAME" -Nse "SELECT COUNT(*) FROM rent_payments WHERE payment_status <> 'paid';")
duplicate_period_cnt=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" -D "$DB_NAME" -Nse "SELECT COUNT(*) FROM (SELECT contract_id, payment_period, COUNT(*) c FROM rent_payments GROUP BY contract_id, payment_period HAVING c > 1) t;")

cat > "$PIPELINE_SUMMARY_JSON" <<EOF
{
  "mode": "$MODE",
  "generated_at": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "inputs": {
    "xlsx_path": "$XLSX_PATH",
    "database": "$DB_NAME",
    "host": "$DB_HOST",
    "port": "$DB_PORT"
  },
  "stages": {
    "bootstrap_property_candidate_count": $bootstrap_properties,
    "bootstrap_contract_candidate_count": $bootstrap_contracts,
    "importable_record_count_after_dry_run": $importable_count,
    "ambiguous_record_count_after_dry_run": $ambiguous_count
  },
  "acceptance_snapshot": {
    "payments_count": $payments_cnt,
    "financial_records_count": $financial_cnt,
    "non_paid_status_count": $non_paid_cnt,
    "duplicate_contract_period_count": $duplicate_period_cnt
  },
  "artifacts": {
    "preview": "$PREVIEW_JSON",
    "dry_run": "$DRY_RUN_JSON",
    "bootstrap_result": "$BOOTSTRAP_RESULT_JSON",
    "ambiguous_resolved": "$AMBIGUOUS_RESOLVED_JSON",
    "payments_result": "$PAYMENTS_RESULT_JSON",
    "pipeline_summary": "$PIPELINE_SUMMARY_JSON"
  }
}
EOF

echo "[8/8] 完成"
echo "- 导入总控执行结束"
echo "- 汇总文件: $PIPELINE_SUMMARY_JSON"
