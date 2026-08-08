#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "ERROR: este desinstalador debe ejecutarse como root." >&2
  exit 1
fi

TARGET_DIR="/usr/local/cpanel/base/3rdparty/axerok-mail"
REGISTRATION="/var/cpanel/webmail/webmail_axerok_mail.yaml"

rm -f "$REGISTRATION"
rm -rf "$TARGET_DIR"

echo "AxerOK Mail fue retirado de Webmail."
echo "Los datos privados de cada cuenta en /home/USUARIO/.axerok-mail se conservaron."
