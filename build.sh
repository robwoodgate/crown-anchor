#!/bin/sh
# Builds crown-anchor.zip with the plugin inside a top-level crown-anchor/
# folder, so a WordPress upload replaces the installed plugin.
set -e
name="crown-anchor" # plugin folder name
pkg="${name}.zip"
cd "$(dirname "$0")"

echo "Building packages..."
composer install --no-dev

echo "Creating zip file..."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/$name"
rsync -a --exclude='.git' --exclude='.gitignore' --exclude='build.sh' \
  --exclude='*.zip' --exclude='.DS_Store' ./ "$tmp/$name/"
rm -f "$pkg"
(cd "$tmp" && zip -rq "$OLDPWD/$pkg" "$name")
echo "Done: $pkg"
