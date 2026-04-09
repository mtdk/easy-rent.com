#!/usr/bin/env bash
set -euo pipefail

# 用法:
#   ./scripts/restore-easy-rent.sh [backup_file] [db_user] [db_password] [db_name] [db_host] [db_port] [--dry-run] [--force]
#
# 示例:
#   ./scripts/restore-easy-rent.sh storage/backups/easy_rent_backup_20260406_021044.sql xmtdk 12345678 easy_rent 127.0.0.1 3306 --force
#   ./scripts/restore-easy-rent.sh "" xmtdk 12345678 easy_rent 127.0.0.1 3306 --dry-run

MODE="commit"
FORCE="0"
POSITIONALS=()

for arg in "$@"; do
  case "$arg" in
    --dry-run)
      MODE="dry-run"
      ;;
    --force)
      FORCE="1"
      ;;
    *)
      POSITIONALS+=("$arg")
      ;;
  esac
done

BACKUP_FILE="${POSITIONALS[0]:-}"
DB_USER="${POSITIONALS[1]:-xmtdk}"
DB_PASSWORD="${POSITIONALS[2]:-}"
DB_NAME="${POSITIONALS[3]:-easy_rent}"
DB_HOST="${POSITIONALS[4]:-127.0.0.1}"
DB_PORT="${POSITIONALS[5]:-3306}"

if ! command -v mysql >/dev/null 2>&1; then
  echo "错误: 未找到 mysql 命令" >&2
  exit 1
fi

if [[ -z "$BACKUP_FILE" ]]; then
  LATEST_FILE="$(ls -1t storage/backups/*.sql 2>/dev/null | head -n 1 || true)"
  if [[ -z "$LATEST_FILE" ]]; then
    echo "错误: 未指定备份文件，且 storage/backups 下未找到可用 .sql 文件" >&2
    exit 1
  fi
  BACKUP_FILE="$LATEST_FILE"
fi

if [[ ! -f "$BACKUP_FILE" ]]; then
  echo "错误: 备份文件不存在: $BACKUP_FILE" >&2
  exit 1
fi

FILE_SIZE="$(wc -c < "$BACKUP_FILE" | tr -d ' ')"

echo "========================================"
echo "数据库恢复任务"
echo "模式: $MODE"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "数据库: $DB_NAME"
echo "备份文件: $BACKUP_FILE"
echo "备份大小(字节): $FILE_SIZE"
echo "========================================"

if [[ "$MODE" == "dry-run" ]]; then
  echo "[dry-run] 将执行恢复命令:"
  echo "mysql -h$DB_HOST -P$DB_PORT -u$DB_USER --password=*** $DB_NAME < $BACKUP_FILE"
  echo "[dry-run] 未写入任何数据"
  exit 0
fi

if [[ "$FORCE" != "1" ]]; then
  echo "错误: 恢复操作为高风险动作，必须显式传入 --force" >&2
  echo "提示: 可先使用 --dry-run 预览" >&2
  exit 1
fi

mysql \
  -h"$DB_HOST" \
  -P"$DB_PORT" \
  -u"$DB_USER" \
  --password="$DB_PASSWORD" \
  "$DB_NAME" < "$BACKUP_FILE"

echo "恢复完成"
echo "- 数据库: $DB_NAME"
echo "- 已导入文件: $BACKUP_FILE"
