#!/bin/sh
# Crea dist/lu3g-hub.zip (cartella lu3g-hub/ all'interno), il pacchetto che WordPress installa e aggiorna.
# Esclude i file che servono solo allo sviluppo (git, script, automazioni).
set -e
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/lu3g-hub
cp -R lu3g-hub.php readme.txt includes assets dist/lu3g-hub/
(cd dist && zip -rq lu3g-hub.zip lu3g-hub -x '*.DS_Store')
rm -rf dist/lu3g-hub
echo "dist/lu3g-hub.zip"
