#!/usr/bin/env sh
# Builds the package installable from OpenMES Admin → Modules → Upload.
# Uses `git archive`, so only committed files go in; tests and scripts are
# excluded through .gitattributes (export-ignore).
set -e
cd "$(dirname "$0")/.."

VERSION=$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' module.json)
mkdir -p dist
git archive --format=zip --prefix=JkControl/ -o "dist/JkControl-$VERSION.zip" HEAD

echo "dist/JkControl-$VERSION.zip"
