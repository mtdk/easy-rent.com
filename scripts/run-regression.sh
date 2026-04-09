#!/usr/bin/env bash
set -euo pipefail

# 一键回归脚本（P1-C）
# 默认执行: lint + unit + integration
#
# 用法:
#   ./scripts/run-regression.sh

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if ! command -v php >/dev/null 2>&1; then
  echo "错误: 未找到 php 命令" >&2
  exit 1
fi

if [[ ! -x "vendor/bin/phpunit" ]]; then
  echo "错误: 未找到 vendor/bin/phpunit，请先执行 composer install" >&2
  exit 1
fi

find_phpunit_config() {
  local candidates=(
    "phpunit.xml"
    "phpunit.xml.dist"
    "tests/phpunit.xml"
    "tests/phpunit.xml.dist"
  )

  local cfg
  for cfg in "${candidates[@]}"; do
    if [[ -f "$cfg" ]]; then
      echo "$cfg"
      return 0
    fi
  done

  return 1
}

has_phpunit_suite() {
  local config_file="$1"
  local suite_name="$2"

  if [[ -z "$config_file" || ! -f "$config_file" ]]; then
    return 1
  fi

  if grep -Fq "name=\"${suite_name}\"" "$config_file"; then
    return 0
  fi

  return 1
}

lint_dir() {
  local dir="$1"
  while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
  done < <(find "$dir" -type f -name "*.php" -print0)
}

lint_root_php() {
  while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
  done < <(find . -maxdepth 1 -type f -name "*.php" -print0)
}

start_ts="$(date +%s)"

echo "========================================"
echo "EasyRent 一键回归"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "步骤: lint -> unit -> integration（按环境自动跳过不可用测试）"
echo "========================================"

echo "[1/3] PHP 语法检查"
lint_dir app
if [[ -d "tests" ]]; then
  lint_dir tests
else
  echo "- 提示: 未找到 tests 目录，跳过 tests 语法检查"
fi
lint_dir public
lint_root_php
echo "- lint 通过"

phpunit_config=""
if phpunit_config="$(find_phpunit_config)"; then
  :
else
  phpunit_config=""
fi

echo "[2/3] 单元测试"
if has_phpunit_suite "$phpunit_config" "EasyRent Unit Tests"; then
  ./vendor/bin/phpunit -c "$phpunit_config" --testsuite "EasyRent Unit Tests"
else
  echo "- 提示: 未找到单元测试套件（EasyRent Unit Tests），已跳过"
fi

echo "[3/3] 集成测试"
if has_phpunit_suite "$phpunit_config" "EasyRent Integration Tests"; then
  ./vendor/bin/phpunit -c "$phpunit_config" --testsuite "EasyRent Integration Tests"
else
  echo "- 提示: 未找到集成测试套件（EasyRent Integration Tests），已跳过"
fi

end_ts="$(date +%s)"
duration="$((end_ts - start_ts))"

echo "========================================"
echo "回归完成: 通过"
echo "耗时: ${duration}s"
echo "========================================"
