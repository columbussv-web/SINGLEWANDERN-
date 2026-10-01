#!/usr/bin/env bash
# Baut das Upload-Paket für den Webspace: dist/singlewandern-werbung-JJJJMMTT.zip
set -euo pipefail
cd "$(dirname "$0")/.."

for f in api/*.php admin/*.php banner/*.php mediadaten/*.php; do php -l "$f" >/dev/null; done
php -r 'json_decode(file_get_contents("assets/pricing.json"), true, 32, JSON_THROW_ON_ERROR);'

name="singlewandern-werbung-$(date +%Y%m%d)"
rm -rf "dist/$name" "dist/$name.zip"
mkdir -p "dist/$name/storage"
cp -r index.html .htaccess assets api admin banner partner mediadaten DEPLOY.md "dist/$name/"
cp storage/.htaccess "dist/$name/storage/"
rm -f "dist/$name/api/config.local.php"
(cd dist && zip -qr "$name.zip" "$name")
rm -rf "dist/$name"
echo "dist/$name.zip"
