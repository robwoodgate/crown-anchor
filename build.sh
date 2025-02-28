#!/bin/sh
pkg="crown-anchor.zip" # plugin name

echo "Building packages..."
composer install --no-dev

echo "Creating zip file..."
rm ${pkg}
zip -rq ${pkg} . -x='.git/*' -x="build.sh" -x="*.DS_Store" -x=".gitignore"
echo "Done"
