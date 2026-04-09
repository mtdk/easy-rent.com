#!/usr/bin/env bash
set -euo pipefail

# 用法:
#   ./scripts/backup-easy-rent.sh [db_user] [db_password] [db_name] [db_host] [db_port] [output_dir]

DB_USER="${1:-xmtdk}"
DB_PASSWORD="${2:-}"
DB_NAME="${3:-easy_rent}"
DB_HOST="${4:-127.0.0.1}"
DB_PORT="${5:-3306}"
OUTPUT_DIR="${6:-storage/backups}"

if ! command -v mysqldump >/dev/null 2>&1; then
  echo "错误: 未找到 mysqldump 命令" >&2
  exit 1
fi

mkdir -p "$OUTPUT_DIR"

TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
OUT_FILE="$OUTPUT_DIR/${DB_NAME}_backup_${TIMESTAMP}.sql"

# 说明: 使用 --single-transaction 以尽量减少对业务写入的影响
mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USER" \
  --password="$DB_PASSWORD" \
  --default-character-set=utf8mb4 \
  --single-transaction \
  --routines \
  --events \
  --triggers \
  "$DB_NAME" > "$OUT_FILE"

FILE_SIZE="$(wc -c < "$OUT_FILE" | tr -d ' ')"

echo "备份完成"
echo "- 数据库: $DB_NAME"
echo "- 输出文件: $OUT_FILE"
echo "- 文件大小(字节): $FILE_SIZE"
