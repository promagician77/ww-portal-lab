#!/usr/bin/env bash
# Rebuilds the whole lab from scratch: WordPress 7.1.4 on SQLite, the ww-portal plugin, fictional demo data.
# Needs: git, PHP 8.0+ with pdo_sqlite and sodium. No MySQL, no web installer.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -d wp ] || git clone -q --depth 1 --branch 7.1.4 https://github.com/WordPress/WordPress.git wp
[ -d .sqlite ] || git clone -q --depth 1 --branch v3.1.3 https://github.com/WordPress/sqlite-database-integration.git .sqlite
SRC=.sqlite; [ -d .sqlite/packages/plugin-sqlite-database-integration ] && SRC=.sqlite/packages/plugin-sqlite-database-integration
DEST=wp/wp-content/plugins/sqlite-database-integration
rm -rf "$DEST" && cp -rL "$SRC" "$DEST"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$(pwd)/$DEST#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$DEST/db.copy" > wp/wp-content/db.php
rm -rf wp/wp-content/plugins/ww-portal && cp -r plugin/ww-portal wp/wp-content/plugins/ww-portal
mkdir -p data shots
php tools/make-config.php
rm -f data/portal.sqlite
php tools/install.php
php tools/activate.php
cp data/portal.sqlite data/clean.sqlite
php tools/seed.php
echo "Ready. Run: npm run serve:wp  then open http://127.0.0.1:8090/portal/ (thandi / demo-pass-123)"
