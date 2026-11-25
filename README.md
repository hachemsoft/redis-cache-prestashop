# Hs Redis Cache — PrestaShop module

Use Redis as the PrestaShop cache server, next to the Memcached and APC backends shipped with the core.

> **Experimental.** Version 2 is tested in a Docker lab on PrestaShop 1.7.8, 8.2 and 9.1.
> It has not run on a shop under real traffic. Try it on a staging shop first.

## Versions

| Module | PrestaShop | PHP |
|---|---|---|
| 2.x (this branch) | 1.7.8 → 9.1 | 7.4 → 8.5 |
| 1.0 (commit `7d1e878`, unmaintained) | 1.5 → 1.6.0.9 | 5.x |

## Install

1. Have a Redis server the shop can reach (https://redis.io).
2. Copy this repository to `modules/hsrediscache/` and install the module from the back office, or run
   `php bin/console prestashop:module install hsrediscache`.
3. Open the module configuration, fill in the Redis server and port, set **Use Redis as cache** to *Yes*, save.

The module refuses to switch the cache on when no Redis server answers at the address given.
Setting the switch back to *No*, or uninstalling, restores the caching system the shop had before.

No PHP extension and no library are needed: the module speaks the Redis protocol over a plain socket.

## How it works

- `classes/CacheRedis.php` implements the `Cache` contract of the core.
- The core reads its cache before any module is loaded and only autoloads classes from `classes/` and
  `override/classes/`. When the cache is switched on, the module copies `CacheRedis.php` to
  `override/classes/cache/` and sets `ps_cache_enable` and `ps_caching` in `app/config/parameters.php`.
- The Redis address is written to `app/config/hsrediscache.php`, read by `CacheRedis` at boot.
- Keys are namespaced per shop (`hsredis:<hash>:`); flushing only removes that namespace.
- A Redis failure (down, timeout) counts as a cache miss: the page is served from the database.

## Limits

- Saving the core page *Advanced parameters > Performance* can select another caching system; switch Redis
  back on from the module page afterwards.
- When Redis is unreachable but does not refuse the connection (packets dropped), each page waits up to one
  second for it. Switch the cache off until Redis is back.
- No password, TLS, database number, Sentinel or Cluster support.
- Cached values are PHP-serialized: only use a Redis server on a network you trust.
- Overrides must be enabled in the shop (they are by default).

## Tests

```
tests/acceptance/run.sh 1.7
tests/acceptance/run.sh 8
tests/acceptance/run.sh 9.1.4
```

Each run starts a throwaway PrestaShop + MySQL + Redis stack with Docker, installs the module and checks:
values go through Redis, ttl, flush, the shop staying up while Redis is down, and a clean uninstall.

## License

Open Software License 3.0 (OSL-3.0), the license of the PrestaShop core code this module derives from.

## Author

**Hachem LATRACH** — twitter: @hachemsoft
