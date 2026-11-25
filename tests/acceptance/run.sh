#!/usr/bin/env bash
# Acceptance test of the hsrediscache module against one PrestaShop version.
#
#   tests/acceptance/run.sh <prestashop image tag>      e.g. 1.7, 8, 9.1.4
#
# KEEP=1 leaves the stack up afterwards for inspection.
# Exit code: 0 when every check passes, 1 otherwise.
set -uo pipefail

PS_TAG="${1:?usage: run.sh <prestashop image tag>}"
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PROJECT="hsredis-ps$(echo "$PS_TAG" | tr -c 'a-zA-Z0-9\n' '-')"
export PS_TAG

dc() { docker compose -p "$PROJECT" -f "$HERE/docker-compose.yml" "$@"; }
in_shop() { dc exec -T -u www-data shop "$@"; }
assert_step() { in_shop php /tmp/hsredis_assert.php "$1" 2>&1 | tail -3; return "${PIPESTATUS[0]}"; }
home_status() { dc exec -T shop curl -s -L -o /dev/null -w '%{http_code}' --max-time 60 http://localhost/; }
redis_dbsize() { dc exec -T redis redis-cli dbsize | tr -dc '0-9'; }

FAILED=0
check() { # check <label> <command...>
    local label="$1"; shift
    local out
    if out="$("$@" 2>&1)"; then
        printf 'PASS  %s\n' "$label"
    else
        printf 'FAIL  %s\n' "$label"
        [ -n "$out" ] && printf '%s\n' "$out" | tail -5 | sed 's/^/        /'
        FAILED=$((FAILED + 1))
    fi
}
is_200() { local code; code="$(home_status)"; [ "$code" = "200" ] || { echo "HTTP $code"; return 1; }; }
dbsize_positive() { local n; n="$(redis_dbsize)"; [ "${n:-0}" -gt 0 ] || { echo "redis dbsize=${n:-?}"; return 1; }; }

cleanup() { [ "${KEEP:-0}" = "1" ] || dc down -v --remove-orphans >/dev/null 2>&1; }
trap cleanup EXIT

echo "== PrestaShop image tag $PS_TAG (project $PROJECT)"
dc down -v --remove-orphans >/dev/null 2>&1
dc up -d >/dev/null 2>&1 || { echo "FAIL  stack does not start"; exit 1; }

echo "-- waiting for the PrestaShop auto-install (up to 20 min)"
READY=0
for _ in $(seq 1 240); do
    if [ -z "$(dc ps -q --status running shop 2>/dev/null)" ]; then break; fi
    if dc logs shop 2>/dev/null | grep -q 'Starting web server now' && [ "$(home_status)" = "200" ]; then READY=1; break; fi
    sleep 5
done
if [ "$READY" != "1" ]; then
    echo "FAIL  PrestaShop did not install (harness problem, not the module). Last log lines:"
    dc logs shop 2>&1 | grep -vE '^[^|]*\|\s*$' | tail -8 | sed 's/^/        /'
    exit 2
fi
in_shop php -r 'require "/var/www/html/config/config.inc.php"; echo "-- PrestaShop ", _PS_VERSION_, ", PHP ", PHP_VERSION, "\n";'

# 0. Harness sanity: the shop works before the module is there.
check "0  baseline: home page answers 200 without the module" is_200

# Copy the module (working tree, without .git and tests) into the shop.
dc exec -T shop mkdir -p /var/www/html/modules/hsrediscache
COPYFILE_DISABLE=1 tar -C "$ROOT" --no-xattrs --exclude .git --exclude tests -cf - . | dc exec -T shop tar -C /var/www/html/modules/hsrediscache -xf -
dc exec -T shop chown -R www-data:www-data /var/www/html/modules/hsrediscache
dc cp "$HERE/assert.php" shop:/tmp/hsredis_assert.php >/dev/null 2>&1

# 1. Install and switch on.
check "1  module installs with the PrestaShop CLI" in_shop php bin/console prestashop:module install hsrediscache
check "2  settings saved and cache switched on through the module API" assert_step enable
check "3  CacheRedis is the active caching system" assert_step active
check "4  home page answers 200 with the Redis cache on" is_200

# 2. The cache really goes through Redis.
check "5  a value is written" assert_step write
check "6  the value is in Redis (dbsize > 0)" dbsize_positive
check "7  another process reads it back" assert_step read
sleep 2
check "8  the ttl is honoured (1 s key gone after 2 s)" assert_step expired
check "9  flush() runs" assert_step flush
check "10 the value is gone after flush()" assert_step flushed
check "11 home page answers 200 after the flush" is_200
check "12 the front office filled Redis again (dbsize > 0)" dbsize_positive

# 3. Redis going down must not take the shop down.
dc stop redis >/dev/null 2>&1
check "13 home page answers 200 while Redis is down" is_200
dc start redis >/dev/null 2>&1
sleep 2
check "14 home page answers 200 once Redis is back" is_200

# 4. Switching off then on again never serves what was left in Redis meanwhile.
check "15 cache switched off through the module API" assert_step disable
check "16 CacheRedis is no longer active" assert_step inactive
check "17 (setup) a value is left in Redis while the cache is off" assert_step plant_stale
check "18 cache switched on again" assert_step enable
check "19 the value left in Redis is not served" assert_step stale_gone

# 5. Uninstalling leaves a working shop.
check "20 module uninstalls with the PrestaShop CLI" in_shop php bin/console prestashop:module uninstall hsrediscache
check "21 CacheRedis is no longer active" assert_step inactive
check "22 home page answers 200 after uninstall" is_200

echo "== $PS_TAG: $FAILED check(s) failed"
[ "$FAILED" -eq 0 ]
