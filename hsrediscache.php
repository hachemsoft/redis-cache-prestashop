<?php
/**
 * Hs Redis Cache: use Redis as the PrestaShop cache server (PrestaShop 1.7.8 to 9.x).
 *
 * @author    Hachem LATRACH
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Hsrediscache extends Module
{
    const CACHE_CLASS = 'CacheRedis';
    const DEFAULT_CACHE_CLASS = 'CacheMemcache';

    /** Read by CacheRedis before the shop is booted; same path as CacheRedis::SETTINGS_FILE. */
    const SETTINGS_FILE = 'app/config/hsrediscache.php';

    public function __construct()
    {
        $this->name = 'hsrediscache';
        $this->tab = 'administration';
        $this->version = '2.0.0';
        $this->author = 'Hachem LATRACH';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.8.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('Hs Redis Cache');
        $this->description = $this->l('Use Redis as cache server to give best performance to your shop');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateGlobalValue('SERVEUR_REDIS', '127.0.0.1')
            && Configuration::updateGlobalValue('PORT_REDIS', 6379);
    }

    public function uninstall()
    {
        // Switch the cache off first: the shop must never point at a class that is gone.
        if (!$this->setCacheEnabled(false)) {
            return false;
        }
        @unlink($this->getSettingsFile());
        Configuration::deleteByName('SERVEUR_REDIS');
        Configuration::deleteByName('PORT_REDIS');
        Configuration::deleteByName('HSREDIS_PREV_CACHING');
        Configuration::deleteByName('HSREDIS_PREV_ENABLE');

        return parent::uninstall();
    }

    /* ---------------------------------------------------------------------
     * Public API, used by the configuration form.
     * ------------------------------------------------------------------ */

    /**
     * Saves the address of the Redis server.
     *
     * @return bool false when the address is not valid or cannot be written
     */
    public function saveSettings($host, $port)
    {
        $host = trim((string) $host);
        $port = (int) $port;

        if (!preg_match('/^[A-Za-z0-9._:\-]+$/', $host)) {
            $this->_errors[] = $this->l('The Redis server must be a host name or an IP address.');

            return false;
        }
        if ($port < 1 || $port > 65535) {
            $this->_errors[] = $this->l('The Redis port must be between 1 and 65535.');

            return false;
        }

        $content = "<?php\n// Written by the hsrediscache module. Read by CacheRedis before the shop is booted.\n"
            . 'return ' . var_export(array('host' => $host, 'port' => $port), true) . ";\n";
        if (@file_put_contents($this->getSettingsFile(), $content, LOCK_EX) === false) {
            $this->_errors[] = sprintf($this->l('Cannot write %s.'), self::SETTINGS_FILE);

            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->getSettingsFile(), true);
        }

        return Configuration::updateGlobalValue('SERVEUR_REDIS', $host)
            && Configuration::updateGlobalValue('PORT_REDIS', $port);
    }

    /**
     * Makes Redis the cache of the shop, or gives the cache back to what it was before.
     *
     * @return bool false when nothing was changed; the reasons are in getErrors()
     */
    public function setCacheEnabled($enabled)
    {
        return $enabled ? $this->enableCache() : $this->disableCache();
    }

    public function isCacheEnabled()
    {
        $parameters = $this->getShopParameters()->getConfiguration();

        return !empty($parameters['parameters']['ps_cache_enable'])
            && isset($parameters['parameters']['ps_caching'])
            && $parameters['parameters']['ps_caching'] === self::CACHE_CLASS;
    }

    public function getErrors()
    {
        return $this->_errors;
    }

    /* ---------------------------------------------------------------------
     * Switching on and off.
     * ------------------------------------------------------------------ */

    protected function enableCache()
    {
        $host = (string) Configuration::getGlobalValue('SERVEUR_REDIS');
        $port = (int) Configuration::getGlobalValue('PORT_REDIS');

        if (!is_file($this->getSettingsFile()) && !$this->saveSettings($host, $port)) {
            return false;
        }

        $this->loadCacheClass();
        if (!CacheRedis::ping($host, $port)) {
            $this->_errors[] = sprintf($this->l('No Redis server answers at %s:%d. The cache was not switched on.'), $host, $port);

            return false;
        }

        // Start from an empty cache: whatever was left in Redis while the cache was off is stale.
        if (!$this->isCacheEnabled()) {
            $cache = new CacheRedis();
            $cache->flush();
        }

        // 1. The class goes where the core autoloader looks, 2. only then the shop points at it.
        if (!$this->installCacheClass()) {
            return false;
        }

        $parameters = $this->getShopParameters();
        $current = $parameters->getConfiguration();
        $currentCaching = isset($current['parameters']['ps_caching']) ? $current['parameters']['ps_caching'] : self::DEFAULT_CACHE_CLASS;
        if ($currentCaching !== self::CACHE_CLASS) {
            Configuration::updateGlobalValue('HSREDIS_PREV_CACHING', $currentCaching);
            Configuration::updateGlobalValue('HSREDIS_PREV_ENABLE', (int) !empty($current['parameters']['ps_cache_enable']));
        }

        $parameters->setProperty('parameters.ps_cache_enable', true);
        $parameters->setProperty('parameters.ps_caching', self::CACHE_CLASS);
        if ($parameters->saveConfiguration() === false) {
            $this->removeCacheClass();
            $this->_errors[] = $this->l('The settings file app/config/parameters.php cannot be overwritten.');

            return false;
        }
        $this->forgetCachedParameters();

        return true;
    }

    protected function disableCache()
    {
        if ($this->isRedisTheConfiguredCache()) {
            $previousCaching = Configuration::getGlobalValue('HSREDIS_PREV_CACHING');
            if (!$previousCaching || $previousCaching === self::CACHE_CLASS) {
                $previousCaching = self::DEFAULT_CACHE_CLASS;
            }

            // 1. The shop stops pointing at the class, 2. only then the class is removed.
            $parameters = $this->getShopParameters();
            $parameters->setProperty('parameters.ps_cache_enable', (bool) Configuration::getGlobalValue('HSREDIS_PREV_ENABLE'));
            $parameters->setProperty('parameters.ps_caching', $previousCaching);
            if ($parameters->saveConfiguration() === false) {
                $this->_errors[] = $this->l('The settings file app/config/parameters.php cannot be overwritten.');

                return false;
            }
            $this->forgetCachedParameters();

            // Leave nothing of this shop behind in Redis; unreachable Redis is not an error here.
            $this->loadCacheClass();
            $cache = new CacheRedis();
            $cache->flush();
        }

        return $this->removeCacheClass();
    }

    protected function isRedisTheConfiguredCache()
    {
        $parameters = $this->getShopParameters()->getConfiguration();

        return isset($parameters['parameters']['ps_caching'])
            && $parameters['parameters']['ps_caching'] === self::CACHE_CLASS;
    }

    /* ---------------------------------------------------------------------
     * The CacheRedis class file.
     *
     * The core reads its cache before any module is loaded, and it only
     * autoloads classes from classes/ and override/classes/. Module::install()
     * does not copy an override for a class the core does not have, so the
     * module puts its class there itself.
     * ------------------------------------------------------------------ */

    protected function getCacheClassSource()
    {
        return $this->getLocalPath() . 'classes/CacheRedis.php';
    }

    protected function getCacheClassTarget()
    {
        return _PS_ROOT_DIR_ . '/override/classes/cache/CacheRedis.php';
    }

    protected function getSettingsFile()
    {
        return _PS_ROOT_DIR_ . '/' . self::SETTINGS_FILE;
    }

    protected function loadCacheClass()
    {
        if (!class_exists(self::CACHE_CLASS)) {
            require_once $this->getCacheClassSource();
        }
    }

    protected function installCacheClass()
    {
        $target = $this->getCacheClassTarget();
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
            $this->_errors[] = $this->l('Cannot create the folder override/classes/cache.');

            return false;
        }
        if (!@copy($this->getCacheClassSource(), $target)) {
            $this->_errors[] = $this->l('Cannot write override/classes/cache/CacheRedis.php.');

            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }

        $autoloader = $this->getAutoloader();
        $autoloader->generateIndex();
        if (!$autoloader->getClassPath(self::CACHE_CLASS)) {
            @unlink($target);
            $autoloader->generateIndex();
            $this->_errors[] = $this->l('PrestaShop does not load the CacheRedis class: check that overrides are not disabled in Advanced parameters > Performance.');

            return false;
        }

        return true;
    }

    protected function removeCacheClass()
    {
        $target = $this->getCacheClassTarget();
        if (is_file($target)) {
            if (!@unlink($target)) {
                $this->_errors[] = $this->l('Cannot remove override/classes/cache/CacheRedis.php.');

                return false;
            }
            $this->getAutoloader()->generateIndex();
        }

        return true;
    }

    /**
     * The class autoloader of the core: a global class up to PrestaShop 8, a namespaced one from 9.
     */
    protected function getAutoloader()
    {
        if (class_exists('PrestaShop\Autoload\PrestashopAutoload')) {
            return PrestaShop\Autoload\PrestashopAutoload::getInstance();
        }

        return PrestaShopAutoload::getInstance();
    }

    protected function getShopParameters()
    {
        return new PrestaShop\PrestaShop\Adapter\Configuration\PhpParameters(_PS_ROOT_DIR_ . '/app/config/parameters.php');
    }

    /**
     * The core keeps a copy of parameters.php per environment and compares
     * modification times to the second: drop the copies so the change is seen at once.
     */
    protected function forgetCachedParameters()
    {
        foreach ((array) glob(_PS_ROOT_DIR_ . '/var/cache/*/appParameters.php') as $file) {
            @unlink($file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Configuration page.
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $saved = $this->saveSettings(Tools::getValue('SERVEUR_REDIS'), Tools::getValue('PORT_REDIS'))
                && $this->setCacheEnabled((bool) Tools::getValue('HSREDIS_ENABLED'));
            $output .= $saved
                ? $this->displayConfirmation($this->l('Your settings have been saved'))
                : $this->displayError(implode(' ', $this->_errors));
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $form = array(
            'form' => array(
                'legend' => array('title' => $this->l('Redis cache'), 'icon' => 'icon-cogs'),
                'description' => $this->l('Experimental: tested on PrestaShop 1.7.8, 8.2 and 9.1 in a lab, not on a shop under real traffic.'),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Use Redis as cache'),
                        'name' => 'HSREDIS_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Replaces the caching system chosen in Advanced parameters > Performance.'),
                        'values' => array(
                            array('id' => 'hsredis_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'hsredis_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Redis server'),
                        'name' => 'SERVEUR_REDIS',
                        'required' => true,
                        'class' => 'fixed-width-xxl',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Redis port'),
                        'name' => 'PORT_REDIS',
                        'required' => true,
                        'class' => 'fixed-width-sm',
                    ),
                ),
                'submit' => array('title' => $this->l('Save')),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->show_toolbar = false;
        $helper->submit_action = 'submit' . $this->name;
        $helper->fields_value = array(
            'HSREDIS_ENABLED' => (int) $this->isCacheEnabled(),
            'SERVEUR_REDIS' => Tools::getValue('SERVEUR_REDIS', Configuration::getGlobalValue('SERVEUR_REDIS')),
            'PORT_REDIS' => Tools::getValue('PORT_REDIS', Configuration::getGlobalValue('PORT_REDIS')),
        );

        return $helper->generateForm(array($form));
    }
}
