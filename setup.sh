#!/usr/bin/env bash
# =====================================================================
# Clinic Management System — one-shot setup
# Installs PHP/MariaDB if missing, starts MariaDB, creates the database
# and loads the schema. Safe to re-run.
# =====================================================================
set -e
cd "$(dirname "$0")"

echo "==> Checking PHP..."
if ! command -v php >/dev/null 2>&1; then
  echo "    Installing PHP 8 + extensions..."
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends \
    php-cli php-mysql php-mbstring php-gd php-curl php-xml
fi
php -v | head -1

echo "==> Checking MariaDB..."
if ! command -v mysqld >/dev/null 2>&1 && ! command -v mariadbd >/dev/null 2>&1; then
  echo "    Installing MariaDB..."
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends mariadb-server mariadb-client
fi

echo "==> Starting MariaDB..."
if ! mysqladmin ping >/dev/null 2>&1; then
  service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1
  for i in $(seq 1 30); do
    mysqladmin ping >/dev/null 2>&1 && break
    sleep 1
  done
fi
mysqladmin ping

echo "==> Creating app database user (idempotent)..."
mysql <<'SQL'
CREATE USER IF NOT EXISTS 'clinic'@'localhost' IDENTIFIED BY 'Clinic@2024';
CREATE USER IF NOT EXISTS 'clinic'@'127.0.0.1' IDENTIFIED BY 'Clinic@2024';
GRANT ALL PRIVILEGES ON clinic_db.* TO 'clinic'@'localhost';
GRANT ALL PRIVILEGES ON clinic_db.* TO 'clinic'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "==> Loading schema (idempotent)..."
mysql < database/schema.sql

echo "==> Ensuring upload directories exist..."
mkdir -p assets/uploads/patients assets/uploads/staff assets/uploads/misc
chmod -R 755 assets/uploads

echo
echo "=========================================================="
echo " Setup complete."
echo " Default login:  admin / Admin@123"
echo " Run ./start.sh to launch the app (PORT env sets the port)."
echo "=========================================================="
