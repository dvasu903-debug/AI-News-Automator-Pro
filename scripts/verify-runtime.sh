#!/usr/bin/env bash
#
# AI News Automator Pro — Runtime Verification Pipeline
#
# Runs the full execution-first verification process this project has
# used since Module 7 and refined through Module 8 Milestones 2-3:
# static checks (php -l, PHPUnit, PHPCS) plus real-database runtime
# verification (real MariaDB, real WordPress core wpdb/dbDelta, the
# plugin's actual production boot path) — not a narrative description
# of what should work, but commands that actually run and assert
# against real state.
#
# This does NOT replace a Hostinger (or other real hosting) smoke test
# before freezing a milestone — it proves the logic is correct against
# an equivalent real database; the hosting environment itself (PHP
# build, MySQL version, hosting restrictions) is a separate, real risk
# this script cannot cover. See docs/verification/*-runtime-verification.md
# for how the two together have been used per milestone.
#
# Usage (from the plugin root, same directory as composer.json):
#   chmod +x scripts/verify-runtime.sh
#   ./scripts/verify-runtime.sh [checklist-name ... | full]
#
# With no arguments, runs the generic boot-check plus every checklist in
# scripts/runtime-harness/checklists/, continuing past any individual
# failure so the full report is visible in one run. Pass one or more
# checklist names (matching a filename in that directory, without .php)
# to run only those, e.g.:
#   ./scripts/verify-runtime.sh milestone3
#
# Pass the single literal argument "full" to run every milestone
# checklist registered in FULL_SEQUENCE below, IN ORDER, STOPPING at the
# first failure (rather than continuing on to later milestones) — the
# regression-suite mode for a growing project: once there are a dozen-
# plus milestone checklists, finding out #3 is broken shouldn't require
# waiting for #4 through #20 to also run.
#   ./scripts/verify-runtime.sh full
#
# Requires: PHP 8.2+, Composer, and either a MariaDB/MySQL server already
# reachable at ANA_HARNESS_DB_HOST (default "localhost", i.e. the local
# Unix socket — most fresh MariaDB installs allow passwordless root only
# that way, not over TCP) or root/sudo to install and start one locally.
# Network access to
# raw.githubusercontent.com is required once per WP_CORE_VERSION to fetch
# WordPress core's wpdb class and dbDelta() — see "Why fetched, not
# vendored" below.
#
# Exit code is non-zero if any step fails.

set -uo pipefail

WP_CORE_VERSION="${WP_CORE_VERSION:-6.8.3}"
ANA_HARNESS_DB_HOST="${ANA_HARNESS_DB_HOST:-localhost}"
ANA_HARNESS_DB_NAME="${ANA_HARNESS_DB_NAME:-ana_runtime_harness}"
ANA_HARNESS_DB_USER="${ANA_HARNESS_DB_USER:-root}"
ANA_HARNESS_DB_PASSWORD="${ANA_HARNESS_DB_PASSWORD:-}"
export ANA_HARNESS_DB_HOST ANA_HARNESS_DB_NAME ANA_HARNESS_DB_USER ANA_HARNESS_DB_PASSWORD

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
HARNESS_DIR="$SCRIPT_DIR/runtime-harness"
CACHE_DIR="${ANA_WP_CORE_CACHE:-$PLUGIN_ROOT/.runtime-cache}"
WP_CORE_DIR="$CACHE_DIR/wp-core-$WP_CORE_VERSION"

FAIL=0
section() { echo; echo "=================================================="; echo "  $1"; echo "=================================================="; }

cd "$PLUGIN_ROOT" || exit 1

section "0. Prerequisites"
php -v || { echo "FAIL: php not found"; exit 1; }
composer --version || { echo "FAIL: composer not found"; exit 1; }

section "1. Install dependencies"
composer install --no-interaction --prefer-dist
[ $? -eq 0 ] && echo "OK: composer install" || { echo "FAIL: composer install"; FAIL=1; }

section "2. PHP syntax validation (php -l) — every file in src/ and tests/"
SYNTAX_FAIL=0
while IFS= read -r -d '' f; do
    if ! php -l "$f" > /tmp/ana_lint_out.txt 2>&1; then
        echo "SYNTAX ERROR in $f:"
        cat /tmp/ana_lint_out.txt
        SYNTAX_FAIL=1
    fi
done < <(find src tests -name '*.php' -print0)
rm -f /tmp/ana_lint_out.txt
[ $SYNTAX_FAIL -eq 0 ] && echo "OK: php -l clean across all files" || { echo "FAIL: syntax errors found above"; FAIL=1; }

section "3. PHPUnit"
vendor/bin/phpunit
[ $? -eq 0 ] && echo "OK: PHPUnit passed" || { echo "FAIL: PHPUnit"; FAIL=1; }

section "4. PHPCS (project policy: exit 1 = reviewed baseline debt, not a blocker)"
composer lint
PHPCS_EXIT=$?
if [ $PHPCS_EXIT -eq 0 ]; then
    echo "OK: PHPCS clean"
elif [ $PHPCS_EXIT -eq 1 ]; then
    echo "PHPCS found findings (see above) — per phpcs.xml.dist's documented policy, review but not an automatic blocker"
else
    echo "FAIL: PHPCS itself errored (exit $PHPCS_EXIT) — check installation"
    FAIL=1
fi

section "5. Database: ensure MariaDB/MySQL is reachable"
if ! mysqladmin -h "$ANA_HARNESS_DB_HOST" -u "$ANA_HARNESS_DB_USER" ${ANA_HARNESS_DB_PASSWORD:+-p"$ANA_HARNESS_DB_PASSWORD"} status >/dev/null 2>&1; then
    echo "No database reachable at $ANA_HARNESS_DB_HOST — attempting to start a local MariaDB (requires it to be installed already)."
    mkdir -p /run/mysqld 2>/dev/null && chown mysql:mysql /run/mysqld 2>/dev/null
    mysqld_safe --skip-grant-tables=0 >/tmp/ana_mysqld.log 2>&1 &
    for i in 1 2 3 4 5 6 7 8; do
        sleep 2
        mysqladmin -h "$ANA_HARNESS_DB_HOST" -u "$ANA_HARNESS_DB_USER" ${ANA_HARNESS_DB_PASSWORD:+-p"$ANA_HARNESS_DB_PASSWORD"} status >/dev/null 2>&1 && break
    done
fi
if mysqladmin -h "$ANA_HARNESS_DB_HOST" -u "$ANA_HARNESS_DB_USER" ${ANA_HARNESS_DB_PASSWORD:+-p"$ANA_HARNESS_DB_PASSWORD"} status >/dev/null 2>&1; then
    echo "OK: database reachable at $ANA_HARNESS_DB_HOST"
else
    echo "FAIL: no database reachable and could not start one automatically."
    echo "      Install MariaDB/MySQL, or point ANA_HARNESS_DB_HOST/USER/PASSWORD at an existing server, then re-run."
    exit 1
fi

MYSQL="mysql -h $ANA_HARNESS_DB_HOST -u $ANA_HARNESS_DB_USER ${ANA_HARNESS_DB_PASSWORD:+-p$ANA_HARNESS_DB_PASSWORD}"
$MYSQL -e "DROP DATABASE IF EXISTS $ANA_HARNESS_DB_NAME; CREATE DATABASE $ANA_HARNESS_DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
[ $? -eq 0 ] && echo "OK: fresh $ANA_HARNESS_DB_NAME database created" || { echo "FAIL: could not create database"; exit 1; }

section "6. WordPress core wpdb/dbDelta (fetched fresh, not vendored)"
cat <<'EOF'
Why fetched, not vendored: wp-includes/class-wpdb.php and
wp-admin/includes/upgrade.php are GPL-licensed WordPress core files.
Committing them into this repository would mean carrying ~1000+ lines
of third-party source that goes stale the moment WordPress core
changes, for a purpose (test-time real-behavior verification) that
doesn't need a permanent copy. Fetching the pinned WP_CORE_VERSION on
demand keeps the repo free of vendored core source while still using
the REAL, unmodified core classes/functions for verification — the same
principle as not vendoring Composer dependencies into git.
EOF

mkdir -p "$WP_CORE_DIR/wp-includes" "$WP_CORE_DIR/wp-admin/includes" "$WP_CORE_DIR/wp-content"

if [ ! -f "$WP_CORE_DIR/wp-includes/class-wpdb.php" ]; then
    curl -sS -o "$WP_CORE_DIR/wp-includes/class-wpdb.php" \
        "https://raw.githubusercontent.com/WordPress/WordPress/${WP_CORE_VERSION}/wp-includes/class-wpdb.php"
    [ -s "$WP_CORE_DIR/wp-includes/class-wpdb.php" ] && echo "OK: fetched class-wpdb.php ($WP_CORE_VERSION)" || { echo "FAIL: could not fetch class-wpdb.php"; exit 1; }
else
    echo "OK: class-wpdb.php already cached for $WP_CORE_VERSION"
fi

if [ ! -f "$WP_CORE_DIR/wp-admin/includes/upgrade.php" ]; then
    TMP_UPGRADE=$(mktemp)
    curl -sS -o "$TMP_UPGRADE" \
        "https://raw.githubusercontent.com/WordPress/WordPress/${WP_CORE_VERSION}/wp-admin/includes/upgrade.php"
    [ -s "$TMP_UPGRADE" ] || { echo "FAIL: could not fetch upgrade.php"; exit 1; }
    php "$HARNESS_DIR/extract-dbdelta.php" "$TMP_UPGRADE" "$WP_CORE_DIR/wp-admin/includes/upgrade.php"
    [ $? -eq 0 ] || { echo "FAIL: could not extract dbDelta from upgrade.php"; exit 1; }
    rm -f "$TMP_UPGRADE"
else
    echo "OK: extracted dbDelta already cached for $WP_CORE_VERSION"
fi

section "7. Boot check + checklists (real MariaDB + real wpdb/dbDelta + production boot)"

run_checklist() {
    local name="$1"
    local path="$2"
    echo
    echo "--- checklist: $name ---"
    WP_CORE_DIR="$WP_CORE_DIR" ANA_PLUGIN_FILE="$PLUGIN_ROOT/ai-news-automator-pro.php" php "$path"
    local rc=$?
    [ $rc -ne 0 ] && FAIL=1
    return $rc
}

run_checklist "boot-check" "$HARNESS_DIR/boot-check.php"

# The ordered regression sequence for "full" mode. Append each new
# milestone's checklist name here as it's added — this list is the one
# place that defines "the full suite, in order" for fail-fast runs.
FULL_SEQUENCE=(milestone2 milestone3 milestone4 module9)

if [ "$#" -eq 1 ] && [ "$1" = "full" ]; then
    for name in "${FULL_SEQUENCE[@]}"; do
        path="$HARNESS_DIR/checklists/$name.php"
        if [ ! -f "$path" ]; then
            echo "FAIL: no checklist named '$name' at $path (FULL_SEQUENCE is out of date)"
            FAIL=1
            break
        fi
        run_checklist "$name" "$path"
        if [ $? -ne 0 ]; then
            echo
            echo "Stopping 'full' sequence: '$name' failed — fix it before checking later milestones."
            break
        fi
    done
elif [ "$#" -gt 0 ]; then
    for name in "$@"; do
        path="$HARNESS_DIR/checklists/$name.php"
        [ -f "$path" ] && run_checklist "$name" "$path" || { echo "FAIL: no checklist named '$name' at $path"; FAIL=1; }
    done
else
    if [ -d "$HARNESS_DIR/checklists" ]; then
        for path in "$HARNESS_DIR"/checklists/*.php; do
            [ -f "$path" ] || continue
            run_checklist "$(basename "$path" .php)" "$path"
        done
    fi
fi

section "SUMMARY"
if [ $FAIL -eq 0 ]; then
    echo "All automated checks passed, including real-database runtime verification."
    echo "Still recommended before freezing a milestone: a smoke test on the actual"
    echo "hosting target (see docs/verification/*-runtime-verification.md for the"
    echo "established pattern)."
else
    echo "One or more checks FAILED. Do not freeze. Fix, then re-run."
fi
exit $FAIL
