#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "ERROR: este instalador debe ejecutarse como root." >&2
  exit 1
fi

PACKAGE_DIR="$(cd "$(dirname "$0")" && pwd)"
SOURCE_DIR="$PACKAGE_DIR/app"
TARGET_DIR="/usr/local/cpanel/base/3rdparty/axerok-mail"
REGISTRATION="/var/cpanel/webmail/webmail_axerok_mail.yaml"

if [[ ! -f "$SOURCE_DIR/public/cpanel.php" || ! -f "$SOURCE_DIR/packaging/cpanel/app-config.php" ]]; then
  echo "ERROR: el paquete está incompleto." >&2
  exit 1
fi

PHP_BIN="/usr/local/cpanel/3rdparty/bin/php"
if [[ ! -x "$PHP_BIN" ]]; then
  echo "ERROR: no se encontró el PHP interno de cPanel." >&2
  exit 1
fi

if ! "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION,"8.1.0",">=") && extension_loaded("openssl") && extension_loaded("pdo_sqlite") && extension_loaded("sqlite3") ? 0 : 1);'; then
  echo "ERROR: AxerOK requiere PHP 8.1+, OpenSSL, PDO SQLite y SQLite3 en el PHP interno de cPanel." >&2
  exit 1
fi

BACKUP_DIR=""
if [[ -d "$TARGET_DIR" ]]; then
  BACKUP_DIR="/var/cpanel/backups/axerok-mail-$(date +%Y%m%d%H%M%S)"
  mkdir -p "$(dirname "$BACKUP_DIR")"
  cp -a "$TARGET_DIR" "$BACKUP_DIR"
  echo "Respaldo creado en $BACKUP_DIR"
fi

STAGING_DIR="${TARGET_DIR}.install.$$"
trap 'rm -rf "$STAGING_DIR"' EXIT
rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR"
cp -a "$SOURCE_DIR"/. "$STAGING_DIR"/
cp "$SOURCE_DIR/packaging/cpanel/app-config.php" "$STAGING_DIR/config/config.php"
SERVER_HOSTNAME="$(hostname -f)"
if [[ ! "$SERVER_HOSTNAME" =~ ^[A-Za-z0-9.-]+$ ]]; then
  echo "ERROR: el hostname del servidor no es válido para TLS." >&2
  exit 1
fi
sed -i "s/__CPANEL_HOSTNAME__/$SERVER_HOSTNAME/g" "$STAGING_DIR/config/config.php"
find "$STAGING_DIR" -type d -exec chmod 755 {} +
find "$STAGING_DIR" -type f -exec chmod 644 {} +
chown -R root:root "$STAGING_DIR"

rm -rf "$TARGET_DIR"
mv "$STAGING_DIR" "$TARGET_DIR"
chmod 755 "$TARGET_DIR/packaging/cpanel/webmail-registration.pl"
"$TARGET_DIR/packaging/cpanel/webmail-registration.pl"

if [[ ! -f "$REGISTRATION" ]]; then
  echo "ERROR: cPanel no creó el registro de Webmail." >&2
  exit 1
fi

echo "AxerOK Mail instalado correctamente."
echo "Abrilo desde cPanel > Email Accounts > Check Email > AxerOK Mail."
