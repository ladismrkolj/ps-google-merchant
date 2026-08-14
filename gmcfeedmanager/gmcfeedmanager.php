<?php
/**
 * gmcfeedmanager - Google Merchant Center Feed Manager for PrestaShop.
 *
 * @author    Vladimir Smrkolj
 * @copyright 2026
 * @license   Commercial / proprietary
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

use GmcFeedManager\Service\GoogleContentApiService;

class Gmcfeedmanager extends Module
{
    /** Configuration keys, centralised so controllers/services stay in sync. */
    public const CONFIG_FEED_TOKEN = 'GMCFEEDMANAGER_FEED_TOKEN';
    public const CONFIG_API_SYNC_ENABLED = 'GMCFEEDMANAGER_API_SYNC_ENABLED';
    public const CONFIG_ID_LANG = 'GMCFEEDMANAGER_ID_LANG';
    public const CONFIG_ID_CURRENCY = 'GMCFEEDMANAGER_ID_CURRENCY';
    public const CONFIG_CHUNK_SIZE = 'GMCFEEDMANAGER_CHUNK_SIZE';
    public const CONFIG_CONDITION = 'GMCFEEDMANAGER_CONDITION';
    public const CONFIG_MERCHANT_ID = 'GMCFEEDMANAGER_MERCHANT_ID';
    public const CONFIG_SERVICE_ACCOUNT_JSON = 'GMCFEEDMANAGER_SERVICE_ACCOUNT_JSON';
    public const CONFIG_CONTENT_LANGUAGE = 'GMCFEEDMANAGER_CONTENT_LANGUAGE';
    public const CONFIG_TARGET_COUNTRY = 'GMCFEEDMANAGER_TARGET_COUNTRY';
    public const CONFIG_ATTR_GROUP_COLOR = 'GMCFEEDMANAGER_ATTR_GROUP_COLOR';
    public const CONFIG_ATTR_GROUP_SIZE = 'GMCFEEDMANAGER_ATTR_GROUP_SIZE';
    public const CONFIG_FEATURE_GENDER = 'GMCFEEDMANAGER_FEATURE_GENDER';
    public const CONFIG_FEATURE_AGE_GROUP = 'GMCFEEDMANAGER_FEATURE_AGE_GROUP';

    public const ADMIN_CONTROLLER = 'AdminGmcFeedConfigurationController';

    public function __construct()
    {
        $this->name = 'gmcfeedmanager';
        $this->tab = 'smart_shopping';
        $this->version = '1.0.1';
        $this->author = 'Vladimir Smrkolj';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = '';

        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];

        parent::__construct();

        $this->displayName = $this->l('Google Merchant Center Feed Manager');
        $this->description = $this->l('High-performance XML feed generation and real-time Content API sync for Google Merchant Center.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall? Category mappings and product rules will be permanently deleted.');
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->installSql()) {
            return false;
        }

        $hooks = [
            'actionObjectProductUpdateAfter',
            'actionUpdateQuantity',
            'displayBackOfficeHeader',
        ];

        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        if (!$this->installTab()) {
            return false;
        }

        $this->installDefaultConfiguration();

        return true;
    }

    public function uninstall(): bool
    {
        if (!$this->uninstallSql()) {
            return false;
        }

        $this->uninstallTab();
        $this->uninstallConfiguration();

        return parent::uninstall();
    }

    /**
     * Runs sql/install.php and reports whether every statement succeeded.
     */
    private function installSql(): bool
    {
        $file = $this->getLocalPath() . 'sql/install.php';

        if (!file_exists($file)) {
            return false;
        }

        $result = require $file;

        return $result !== false;
    }

    private function uninstallSql(): bool
    {
        $file = $this->getLocalPath() . 'sql/uninstall.php';

        if (!file_exists($file)) {
            return true;
        }

        $result = require $file;

        return $result !== false;
    }

    private function installTab(): bool
    {
        $tab = new Tab();
        $tab->class_name = self::ADMIN_CONTROLLER;
        $tab->module = $this->name;
        $tab->id_parent = $this->resolveParentTabId();
        $tab->icon = 'shopping_basket';

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'Google Merchant Feed';
        }

        return $tab->add();
    }

    /**
     * Finds a sensible, always-present parent menu to nest our tab under
     * (the "Modules" section). Falls back to 0 (a standalone top-level
     * entry) rather than letting a missing/renamed core tab abort the
     * whole install -- an unnested tab is still reachable, an install
     * that silently fails is not.
     */
    private function resolveParentTabId(): int
    {
        foreach (['AdminParentModulesSf', 'AdminParentModules', 'AdminModules'] as $candidate) {
            $idParent = (int) Tab::getIdFromClassName($candidate);
            if ($idParent > 0) {
                return $idParent;
            }
        }

        return 0;
    }

    private function uninstallTab(): void
    {
        $idTab = (int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER);
        if ($idTab) {
            $tab = new Tab($idTab);
            $tab->delete();
        }
    }

    private function installDefaultConfiguration(): void
    {
        $defaults = $this->getXmlDefaults();

        Configuration::updateValue(self::CONFIG_FEED_TOKEN, bin2hex(random_bytes(20)));
        Configuration::updateValue(self::CONFIG_API_SYNC_ENABLED, (int) ($defaults['api_sync_enabled'] ?? 0));
        Configuration::updateValue(self::CONFIG_ID_LANG, (int) ($defaults['default_id_lang'] ?? Configuration::get('PS_LANG_DEFAULT')));
        Configuration::updateValue(self::CONFIG_ID_CURRENCY, (int) ($defaults['default_id_currency'] ?? Configuration::get('PS_CURRENCY_DEFAULT')));
        Configuration::updateValue(self::CONFIG_CHUNK_SIZE, (int) ($defaults['feed_chunk_size'] ?? 250));
        Configuration::updateValue(self::CONFIG_CONDITION, (string) ($defaults['default_condition'] ?? 'new'));
        Configuration::updateValue(self::CONFIG_MERCHANT_ID, '');
        Configuration::updateValue(self::CONFIG_SERVICE_ACCOUNT_JSON, '');
        Configuration::updateValue(self::CONFIG_CONTENT_LANGUAGE, 'en');
        Configuration::updateValue(self::CONFIG_TARGET_COUNTRY, 'US');
        Configuration::updateValue(self::CONFIG_ATTR_GROUP_COLOR, 0);
        Configuration::updateValue(self::CONFIG_ATTR_GROUP_SIZE, 0);
        Configuration::updateValue(self::CONFIG_FEATURE_GENDER, 0);
        Configuration::updateValue(self::CONFIG_FEATURE_AGE_GROUP, 0);
    }

    private function uninstallConfiguration(): void
    {
        $keys = [
            self::CONFIG_FEED_TOKEN,
            self::CONFIG_API_SYNC_ENABLED,
            self::CONFIG_ID_LANG,
            self::CONFIG_ID_CURRENCY,
            self::CONFIG_CHUNK_SIZE,
            self::CONFIG_CONDITION,
            self::CONFIG_MERCHANT_ID,
            self::CONFIG_SERVICE_ACCOUNT_JSON,
            self::CONFIG_CONTENT_LANGUAGE,
            self::CONFIG_TARGET_COUNTRY,
            self::CONFIG_ATTR_GROUP_COLOR,
            self::CONFIG_ATTR_GROUP_SIZE,
            self::CONFIG_FEATURE_GENDER,
            self::CONFIG_FEATURE_AGE_GROUP,
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }
    }

    /**
     * Reads config/config.xml <defaults> into an associative array.
     *
     * @return array<string, string>
     */
    public function getXmlDefaults(): array
    {
        $path = $this->getLocalPath() . 'config/config.xml';
        $defaults = [];

        if (!file_exists($path)) {
            return $defaults;
        }

        $xml = @simplexml_load_file($path);
        if ($xml === false || !isset($xml->defaults)) {
            return $defaults;
        }

        foreach ($xml->defaults->children() as $child) {
            $defaults[$child->getName()] = (string) $child;
        }

        return $defaults;
    }

    /**
     * Module configuration page: this class delegates entirely to the
     * dedicated admin controller rather than rendering inline.
     */
    public function getContent(): string
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink(self::ADMIN_CONTROLLER)
        );

        return '';
    }

    /**
     * Loads admin.css/admin.js only on our own configuration screen.
     */
    public function hookDisplayBackOfficeHeader(array $params): void
    {
        if (!$this->context->controller instanceof AdminController) {
            return;
        }

        if (Tools::getValue('controller') !== self::ADMIN_CONTROLLER) {
            return;
        }

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
    }

    /**
     * Real-time sync: push the updated product to Google when a product is
     * saved, provided API sync is turned on in the settings.
     */
    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $object = $params['object'] ?? null;
        if (!$object instanceof Product) {
            return;
        }

        // Reload in the feed's configured language: the hook fires in
        // whatever language the back-office employee is currently using,
        // which would otherwise leak into the title/description we push.
        $product = new Product((int) $object->id, false, (int) Configuration::get(self::CONFIG_ID_LANG));

        $this->pushProductToContentApi($product);
    }

    /**
     * Real-time sync: push updated availability/stock to Google whenever
     * quantity changes (the hook fires with id_product/id_product_attribute
     * rather than a full object).
     */
    public function hookActionUpdateQuantity(array $params): void
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        if ($idProduct <= 0) {
            return;
        }

        $product = new Product($idProduct, false, (int) Configuration::get(self::CONFIG_ID_LANG));
        if (!Validate::isLoadedObject($product)) {
            return;
        }

        $this->pushProductToContentApi($product, (int) ($params['id_product_attribute'] ?? 0));
    }

    private function pushProductToContentApi(?Product $product, int $idProductAttribute = 0): void
    {
        if (!$product instanceof Product || !Validate::isLoadedObject($product)) {
            return;
        }

        if (!(bool) Configuration::get(self::CONFIG_API_SYNC_ENABLED)) {
            return;
        }

        $merchantId = (string) Configuration::get(self::CONFIG_MERCHANT_ID);
        $serviceAccountJson = (string) Configuration::get(self::CONFIG_SERVICE_ACCOUNT_JSON);

        if ($merchantId === '' || $serviceAccountJson === '') {
            return;
        }

        try {
            $transformer = new \GmcFeedManager\Service\ProductDataTransformer();
            $idLang = (int) Configuration::get(self::CONFIG_ID_LANG);
            $idCurrency = (int) Configuration::get(self::CONFIG_ID_CURRENCY);

            $combination = null;
            if ($idProductAttribute > 0) {
                $combination = new Combination($idProductAttribute);
                if (!Validate::isLoadedObject($combination)) {
                    $combination = null;
                }
            }

            $data = $transformer->transform($product, $idLang, $idCurrency, $combination);

            $api = new GoogleContentApiService($merchantId, $serviceAccountJson);
            $api->patchProduct($data);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'gmcfeedmanager: real-time API sync failed - ' . $e->getMessage(),
                3,
                null,
                'Product',
                (int) $product->id,
                true
            );
        }
    }
}
