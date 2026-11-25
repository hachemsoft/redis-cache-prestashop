<?php
/**
 * Acceptance assertions, run inside the PrestaShop container, one step per
 * process (the cache constants are fixed at bootstrap, and a fresh process is
 * what proves that a value really went through Redis).
 *
 * Usage: php assert.php <step>
 */
require '/var/www/html/config/config.inc.php';

function ok($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$step = isset($argv[1]) ? $argv[1] : '';
$value = array('id' => 42, 'label' => 'acceptance');

switch ($step) {
    // The public API the back-office form uses: save the server, switch the cache on.
    case 'enable':
        $module = Module::getInstanceByName('hsrediscache');
        ok($module && Module::isInstalled('hsrediscache'), 'module hsrediscache is installed');
        ok($module->saveSettings('redis', 6379), 'saveSettings(host, port) succeeds');
        ok($module->setCacheEnabled(true), 'setCacheEnabled(true) succeeds');
        break;

    case 'active':
        ok(defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_, 'PrestaShop cache is enabled');
        ok(_PS_CACHING_SYSTEM_ === 'CacheRedis', 'caching system is CacheRedis, got ' . _PS_CACHING_SYSTEM_);
        ok(Cache::getInstance() instanceof CacheRedis, 'Cache::getInstance() is a CacheRedis');
        break;

    case 'write':
        $cache = Cache::getInstance();
        ok($cache->set('hsredis_persist', $value), 'set() without ttl succeeds');
        ok($cache->set('hsredis_ttl', 'short-lived', 1), 'set() with a 1 second ttl succeeds');
        ok($cache->get('hsredis_persist') === $value, 'get() returns the value in the same process');
        break;

    case 'read':
        ok(Cache::getInstance()->get('hsredis_persist') === $value, 'value is read back by another process');
        break;

    case 'expired':
        ok(Cache::getInstance()->get('hsredis_ttl') === false, 'a key with a 1 second ttl is gone after 2 seconds');
        break;

    case 'flush':
        Cache::getInstance()->flush();
        break;

    case 'flushed':
        ok(Cache::getInstance()->get('hsredis_persist') === false, 'value is gone after flush()');
        break;

    case 'disable':
        $module = Module::getInstanceByName('hsrediscache');
        ok($module->setCacheEnabled(false), 'setCacheEnabled(false) succeeds');
        break;

    // What a request still running while the cache is switched off leaves behind.
    case 'plant_stale':
        if (!class_exists('CacheRedis')) {
            require_once _PS_MODULE_DIR_ . 'hsrediscache/classes/CacheRedis.php';
        }
        $leftover = new CacheRedis();
        ok($leftover->set('hsredis_stale', 'written while the cache was off'), 'a value is written straight to Redis');
        break;

    case 'stale_gone':
        ok(Cache::getInstance() instanceof CacheRedis, 'Cache::getInstance() is a CacheRedis');
        ok(Cache::getInstance()->get('hsredis_stale') === false, 'a value left in Redis while the cache was off is not served');
        break;

    case 'inactive':
        $redisActive = defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_ && _PS_CACHING_SYSTEM_ === 'CacheRedis';
        ok(!$redisActive, 'CacheRedis is no longer the active caching system');
        break;

    default:
        ok(false, "unknown step '$step'");
}

echo "OK $step\n";
