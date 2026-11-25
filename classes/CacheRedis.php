<?php
/**
 * Redis backend for the PrestaShop cache (PrestaShop 1.7.8 to 9.x).
 *
 * This file is self-contained on purpose: the hsrediscache module copies it to
 * override/classes/cache/CacheRedis.php so that the core autoloader finds the
 * class before any module is loaded. It needs no PHP extension and no library:
 * it speaks the Redis protocol over a plain socket.
 *
 * Guarantees:
 * - every Redis failure (down, timeout, protocol error) degrades to a cache
 *   miss; it never throws and never stops the page;
 * - keys are namespaced per shop, and flush() only removes that namespace;
 * - a ttl is enforced by Redis itself.
 *
 * @author    Hachem LATRACH
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class CacheRedis extends Cache
{
    /** Settings file written by the module, relative to the shop root. */
    const SETTINGS_FILE = 'app/config/hsrediscache.php';

    const CONNECT_TIMEOUT = 1.0;
    const READ_TIMEOUT = 2;
    const SCAN_COUNT = 500;

    /** @var resource|null open socket, null when Redis is not reachable */
    protected $socket;

    /** @var string namespace put in front of every key */
    protected $prefix;

    public function __construct()
    {
        $this->prefix = 'hsredis:' . substr(md5(_DB_NAME_ . _DB_PREFIX_ . _COOKIE_KEY_), 0, 10) . ':';

        $settings = self::loadSettings();
        if ($settings) {
            $this->socket = self::open($settings['host'], $settings['port']);
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @return array|null array('host' => string, 'port' => int)
     */
    public static function loadSettings()
    {
        $file = _PS_ROOT_DIR_ . '/' . self::SETTINGS_FILE;
        if (!is_file($file)) {
            return null;
        }
        $settings = include $file;
        if (!is_array($settings) || empty($settings['host']) || empty($settings['port'])) {
            return null;
        }

        return array('host' => (string) $settings['host'], 'port' => (int) $settings['port']);
    }

    /**
     * Tells whether a Redis server answers at this address.
     */
    public static function ping($host, $port)
    {
        $socket = self::open($host, $port);
        if (!$socket) {
            return false;
        }
        $answer = false;
        if (@fwrite($socket, "*1\r\n$4\r\nPING\r\n") !== false) {
            $answer = @fgets($socket);
        }
        @fclose($socket);

        return is_string($answer) && strpos($answer, '+PONG') === 0;
    }

    public function isConnected()
    {
        return $this->socket !== null;
    }

    /* ---------------------------------------------------------------------
     * Public cache API.
     *
     * The core versions of set/get/exists/delete go through an in-memory key
     * index ($this->keys) that another PHP process does not see. Redis already
     * knows which keys exist, so these go straight to it.
     * ------------------------------------------------------------------ */

    public function set($key, $value, $ttl = 0)
    {
        return $this->_set($key, $value, $ttl);
    }

    public function get($key)
    {
        return $this->_get($key);
    }

    public function exists($key)
    {
        return $this->_exists($key);
    }

    public function delete($key)
    {
        if ($key == '*') {
            $this->flush();
        } elseif (strpos($key, '*') === false) {
            $this->_delete($key);
        } else {
            $this->deleteMatching($key);
        }

        return true;
    }

    /**
     * Removes every key of this shop. Other data in the same Redis database is left alone.
     */
    public function flush()
    {
        if (!$this->isConnected()) {
            return false;
        }

        return $this->deleteMatching('*');
    }

    /* ---------------------------------------------------------------------
     * Backend contract of Cache.
     * ------------------------------------------------------------------ */

    protected function _set($key, $value, $ttl = 0)
    {
        $arguments = array('SET', $this->prefix . $key, serialize($value));
        if ((int) $ttl > 0) {
            $arguments[] = 'EX';
            $arguments[] = (int) $ttl;
        }

        return $this->command($arguments) === 'OK';
    }

    protected function _get($key)
    {
        $data = $this->command(array('GET', $this->prefix . $key));
        if (!is_string($data)) {
            return false;
        }

        return @unserialize($data);
    }

    protected function _exists($key)
    {
        return $this->command(array('EXISTS', $this->prefix . $key)) === 1;
    }

    protected function _delete($key)
    {
        return $this->command(array('DEL', $this->prefix . $key)) !== null;
    }

    protected function _writeKeys()
    {
        return true;
    }

    /**
     * Deletes the keys of this shop matching a pattern where * stands for any text.
     */
    protected function deleteMatching($pattern)
    {
        // In a Redis pattern ? [ ] \ are special too: only * keeps its meaning here.
        $match = $this->prefix . preg_replace('/([?\[\]\\\\])/', '\\\\$1', $pattern);
        $cursor = '0';
        do {
            $reply = $this->command(array('SCAN', $cursor, 'MATCH', $match, 'COUNT', self::SCAN_COUNT));
            if (!is_array($reply) || count($reply) !== 2 || !is_array($reply[1])) {
                return false;
            }
            $cursor = (string) $reply[0];
            if ($reply[1]) {
                $this->command(array_merge(array('DEL'), $reply[1]));
            }
        } while ($cursor !== '0');

        return true;
    }

    /* ---------------------------------------------------------------------
     * Redis protocol (RESP) over a socket.
     * ------------------------------------------------------------------ */

    /**
     * @return resource|null
     */
    protected static function open($host, $port)
    {
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . (int) $port,
            $errorCode,
            $errorMessage,
            self::CONNECT_TIMEOUT
        );
        if (!$socket) {
            return null;
        }
        stream_set_timeout($socket, self::READ_TIMEOUT);

        return $socket;
    }

    protected function disconnect()
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Sends one command and returns its reply: string, int, array, or null
     * when there is no value or when anything went wrong. After a failure the
     * connection is dropped, so the rest of the request stops waiting on Redis.
     *
     * @return mixed
     */
    protected function command(array $arguments)
    {
        if (!$this->socket) {
            return null;
        }

        $payload = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $argument = (string) $argument;
            $payload .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        try {
            $this->write($payload);

            return $this->readReply();
        } catch (Exception $e) {
            $this->disconnect();

            return null;
        }
    }

    protected function write($payload)
    {
        $length = strlen($payload);
        $written = 0;
        while ($written < $length) {
            $bytes = @fwrite($this->socket, $written ? substr($payload, $written) : $payload);
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Redis write failed');
            }
            $written += $bytes;
        }
    }

    protected function readReply()
    {
        $line = @fgets($this->socket);
        if ($line === false || substr($line, -2) !== "\r\n") {
            throw new RuntimeException('Redis read failed');
        }
        $type = $line[0];
        $body = substr($line, 1, -2);

        switch ($type) {
            case '+':
                return $body;
            case ':':
                return (int) $body;
            case '-':
                // An error reply leaves the connection usable: report "no value".
                return null;
            case '$':
                $size = (int) $body;
                if ($size < 0) {
                    return null;
                }

                return substr($this->read($size + 2), 0, $size);
            case '*':
                $count = (int) $body;
                if ($count < 0) {
                    return null;
                }
                $items = array();
                for ($i = 0; $i < $count; ++$i) {
                    $items[] = $this->readReply();
                }

                return $items;
        }

        throw new RuntimeException('Unknown Redis reply');
    }

    protected function read($length)
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Redis read failed');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
