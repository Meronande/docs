#!/usr/bin/env bash
# Starts the Clinic Management System (MariaDB + PHP built-in server).
# PORT is provided by the platform preview (defaults to 8080 locally).
set -e
cd "$(dirname "$0")"

# Make sure MariaDB is running
if ! mysqladmin ping >/dev/null 2>&1; then
  service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1 || true
  for i in $(seq 1 30); do
    if mysqladmin ping >/dev/null 2>&1; then break; fi
    sleep 1
  done
fi

# Auto-install schema if missing
if ! mysql -N -e "USE clinic_db; SELECT 1;" >/dev/null 2>&1; then
  bash ./setup.sh
fi

PORT="${PORT:-8080}"
echo "Clinic Management System running at http://0.0.0.0:${PORT} (login: admin / Admin@123)"
exec php -d display_errors=1 -d error_reporting=E_ALL -S 0.0.0.0:"${PORT}" router.php
