<?php

/*
 * 2007-2014 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author PrestaShop SA <contact@prestashop.com>
 *  @copyright  2007-2014 PrestaShop SA
 *  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

/**
 * This class require Redis server Installed 
 *
 */
require _PS_MODULE_DIR_ . "/hsrediscache/predis/Autoloader.php";
Predis\Autoloader::register();

class CacheRedis extends Cache {

    /**
     * @var RedisClient
     */
    protected $redis;

    /**
     * @var RedisParams
     */
    protected $_params = array();

    /**
     * @var bool Connection status
     */
    protected $is_connected = false;

    public function __construct() {

        $this->connect();

        if ($this->is_connected) {
            $this->redis->get(_COOKIE_IV_);
            $this->keys = @unserialize($this->redis->get(_COOKIE_IV_));
            if (!is_array($this->keys))
                $this->keys = array();
        }
    }

    public function __destruct() {
        $this->close();
    }

    /**
     * Connect to redis server
     */
    public function connect() {



        $servers = self::getRedisServer();

        if (!$servers) {
            return;
        } else {
            $this->redis = new Predis\Client([
                'scheme' => 'tcp',
                'host' => $servers['SERVEUR_REDIS'],
                'port' => $servers['PORT_REDIS'],
            ]);


            $this->is_connected = true;
        }
    }

    /**
     * @see Cache::_set()
     */
    protected function _set($key, $value, $ttl = 0) {

        if (!$this->is_connected)
            return false;


        return $this->redis->set($key, serialize($value));
    }

    /**
     * @see Cache::_get()
     */
    protected function _get($key) {
        if (!$this->is_connected)
            return false;
        return unserialize($this->redis->get($key));
    }

    /**
     * @see Cache::_exists()
     */
    protected function _exists($key) {
        if (!$this->is_connected)
            return false;
        return isset($this->keys[$key]);
    }

    /**
     * @see Cache::_delete()
     */
    protected function _delete($key) {
        if (!$this->is_connected)
            return false;
        return $this->redis->del($key);
    }

    /**
     * @see Cache::_writeKeys()
     */
    protected function _writeKeys() {
        if (!$this->is_connected)
            return false;
        $this->redis->set(_COOKIE_IV_, serialize($this->keys));
    }

    /**
     * @see Cache::flush()
     */
    public function flush() {
        if (!$this->is_connected)
            return false;
        return $this->redis->flushdb();
    }

    /**
     * Close connection to redis server
     *
     * @return bool
     */
    protected function close() {
        if (!$this->is_connected)
            return false;
        return $this->redis->disconnect();
    }

    /**
     * Get list of redis server information
     *
     * @return array
     */
    public static function getRedisServer() {

        if (version_compare(_PS_VERSION_, 1.6, '>')) {
            return array('SERVEUR_REDIS' => Configuration::get('SERVEUR_REDIS'), 'PORT_REDIS' => Configuration::get('PORT_REDIS'));
        } else {
            // bypass the memory fatal error caused functions nesting on PS 1.5
            $params = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'configuration WHERE name = "SERVEUR_REDIS" OR name="PORT_REDIS"', true, false);
            foreach ($params as $key => $val)
                $server[$val['name']] = $val['value'];

            return $server;
        }
    }

}
