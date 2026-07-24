#!/usr/bin/env bash
#
# Production build script for AI News Automator Pro.
#
# Produces a WordPress-installable ZIP that requires NO Composer on the
# target site: dependencies are vendored in, the autoloader is optimized
# to a classmap, and dev dependencies (PHPUnit, PHPCS) are excluded.
#
# Usage:  bin/build.sh [version]
# Output: dist/ai-news-automator-pro-<version>.zip
#
set -euo pipefail

SLUG="ai-news-automator-pro"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(grep -m1 "Version:" "$ROOT/$SLUG.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')}"
BUILD_DIR="$ROOT/build"
DIST_DIR="$ROOT/dist"
STAGE="$BUILD_DIR/$SLUG"

echo "==> Building $SLUG version $VERSION"

# 1. Clean any previous build artifacts.
rm -rf "$BUILD_DIR" "$DIST_DIR"
mkdir -p "$STAGE" "$DIST_DIR"

# 2. Copy only the files that belong in a distributed plugin. rsync with an
#    explicit include/exclude list is safer than copying everything and
#    deleting, because it can never accidentally ship a local .env or a
#    developer's vendor/ with dev packages still in it.
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='build' \
  --exclude='dist' \
  --exclude='tests' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='.gitignore' \
  --exclude='phpunit.xml.dist' \
  --exclude='phpunit.xml' \
  --exclude='.phpcs.xml.dist' \
  --exclude='.phpcs.xml' \
  --exclude='composer.lock' \
  --exclude='bin' \
  --exclude='*.md' \
  "$ROOT/" "$STAGE/"

# 3. Install production-only dependencies with an optimized classmap
#    autoloader, directly into the staging area's vendor/.
echo "==> Installing production dependencies (no-dev, optimized autoloader)"
composer install \
  --no-dev \
  --optimize-autoloader \
  --classmap-authoritative \
  --no-interaction \
  --working-dir="$STAGE" \
  --quiet

# 4. Remove Composer metadata not needed at runtime.
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

# 5. Zip from the build dir so the archive contains a single top-level
#    folder named after the slug — which is what WordPress expects when
#    installing a plugin from a ZIP.
echo "==> Creating ZIP"
( cd "$BUILD_DIR" && zip -r -q "$DIST_DIR/$SLUG-$VERSION.zip" "$SLUG" )

# 6. Report.
echo "==> Done: dist/$SLUG-$VERSION.zip"
ls -lh "$DIST_DIR/$SLUG-$VERSION.zip" | awk '{print "    Size: "$5}'
