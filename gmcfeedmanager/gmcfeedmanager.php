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

    /**
     * Tab class_name / the "controller" URL parameter.
     *
     * NOT the PHP class name: Dispatcher::getControllersInDirectory()
     * builds its lookup keys by stripping a trailing "Controller.php" off
     * each filename, so AdminGmcFeedConfigurationController.php registers
     * as "admingmcfeedconfiguration". Passing the full class name here
     * never matches and the dispatcher falls through to
     * AdminNotFoundController ("The controller ... is missing or invalid").
     */
    public const ADMIN_CONTROLLER = 'AdminGmcFeedConfiguration';

    /**
     * Hooks the module registers.
     *
     * The actionObject*After hooks are not declared anywhere in core: they
     * are fired dynamically by ObjectModel::add()/update()/delete() as
     * "actionObject<ClassName><Event>", so registering them is enough.
     *
     * Why each one is here:
     *  - Product Add/Update  : push the offer when a product is created or edited.
     *  - Product Delete      : retire the offer. Without this, a product deleted
     *                          in PrestaShop keeps being served by Merchant Center
     *                          until it expires, which means ads pointing at a
     *                          dead product page.
     *  - Combination Delete  : same, per variant. Product::deleteProductAttributes()
     *                          deletes combinations one by one, so this also covers
     *                          the variants of a deleted parent product.
     *  - SpecificPrice Add/Delete : starting or ending a sale never touches the
     *                          product row, so ProductUpdateAfter does not fire and
     *                          g:sale_price would otherwise stay stale until the
     *                          next scheduled feed fetch.
     *  - Category Delete     : drop that category's taxonomy mapping instead of
     *                          leaving an orphan row behind.
     *  - actionUpdateQuantity: stock moves change g:availability.
     */
    public const HOOKS = [
        'actionObjectProductAddAfter',
        'actionObjectProductUpdateAfter',
        'actionObjectProductDeleteAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionObjectCategoryDeleteAfter',
        'actionUpdateQuantity',
        'displayBackOfficeHeader',
    ];

    public function __construct()
    {
        $this->name = 'gmcfeedmanager';
        $this->tab = 'smart_shopping';
        $this->version = '1.0.3';
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

        foreach (self::HOOKS as $hook) {
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
        $this->syncProductFromHook($params['object'] ?? null);
    }

    /**
     * Real-time sync: a newly created product is not covered by the update
     * hook, so it would otherwise wait for the next scheduled feed fetch.
     */
    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->syncProductFromHook($params['object'] ?? null);
    }

    /**
     * Retires every offer belonging to a deleted product. Without this the
     * offer keeps being served by Merchant Center until it expires, so ads
     * can point at a product page that no longer exists.
     */
    public function hookActionObjectProductDeleteAfter(array $params): void
    {
        $object = $params['object'] ?? null;
        if (!$object instanceof Product || !$object->id) {
            return;
        }

        $this->deleteOffers([(string) $object->id]);
    }

    /**
     * Retires a single variant's offer. Product::deleteProductAttributes()
     * deletes combinations one at a time, so this also covers the variants
     * of a deleted parent product.
     */
    public function hookActionObjectCombinationDeleteAfter(array $params): void
    {
        $object = $params['object'] ?? null;
        if (!$object instanceof Combination || !$object->id) {
            return;
        }

        $this->deleteOffers([$object->id_product . '_' . $object->id]);
    }

    /**
     * Starting or ending a sale writes to specific_price, never to the
     * product row, so the update hook does not fire and g:sale_price would
     * stay stale until the next scheduled fetch.
     */
    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        $this->syncFromSpecificPrice($params['object'] ?? null);
    }

    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        $this->syncFromSpecificPrice($params['object'] ?? null);
    }

    /**
     * Housekeeping: drop the taxonomy mapping of a deleted category rather
     * than leaving an orphan row that shadows a future category reusing
     * that id.
     */
    public function hookActionObjectCategoryDeleteAfter(array $params): void
    {
        $object = $params['object'] ?? null;
        if (!$object instanceof Category || !$object->id) {
            return;
        }

        Db::getInstance()->delete('gmc_category_mapping', 'id_category = ' . (int) $object->id);
    }

    private function syncFromSpecificPrice($specificPrice): void
    {
        if (!is_object($specificPrice) || empty($specificPrice->id_product)) {
            return;
        }

        $product = new Product(
            (int) $specificPrice->id_product,
            false,
            (int) Configuration::get(self::CONFIG_ID_LANG)
        );

        if (Validate::isLoadedObject($product)) {
            $this->pushProductToContentApi($product);
        }
    }

    private function syncProductFromHook($object): void
    {
        if (!$object instanceof Product || !$object->id) {
            return;
        }

        // Reload in the feed's configured language: the hook fires in
        // whatever language the back-office employee is currently using,
        // which would otherwise leak into the title/description we push.
        $product = new Product((int) $object->id, false, (int) Configuration::get(self::CONFIG_ID_LANG));

        $this->pushProductToContentApi($product);
    }

    /**
     * @param array<int, string> $offerIds
     */
    private function deleteOffers(array $offerIds): void
    {
        if (!$this->isApiSyncConfigured()) {
            return;
        }

        try {
            $api = new GoogleContentApiService(
                (string) Configuration::get(self::CONFIG_MERCHANT_ID),
                (string) Configuration::get(self::CONFIG_SERVICE_ACCOUNT_JSON)
            );

            foreach ($offerIds as $offerId) {
                $api->deleteProduct($offerId);
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'gmcfeedmanager: real-time API delete failed - ' . $e->getMessage(),
                3
            );
        }
    }

    private function isApiSyncConfigured(): bool
    {
        return (bool) Configuration::get(self::CONFIG_API_SYNC_ENABLED)
            && (string) Configuration::get(self::CONFIG_MERCHANT_ID) !== ''
            && (string) Configuration::get(self::CONFIG_SERVICE_ACCOUNT_JSON) !== '';
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

    /**
     * Pushes a product to the Content API.
     *
     * With $idProductAttribute = 0 on a product that has combinations,
     * every combination is pushed: each variant is its own Merchant Center
     * offer, so syncing only the parent id would leave every variant offer
     * stale (and create a "parent" offer that the feed itself never emits).
     */
    private function pushProductToContentApi(?Product $product, int $idProductAttribute = 0): void
    {
        if (!$product instanceof Product || !Validate::isLoadedObject($product)) {
            return;
        }

        if (!$this->isApiSyncConfigured()) {
            return;
        }

        try {
            $transformer = new \GmcFeedManager\Service\ProductDataTransformer();
            $idLang = (int) Configuration::get(self::CONFIG_ID_LANG);
            $idCurrency = (int) Configuration::get(self::CONFIG_ID_CURRENCY);

            $api = new GoogleContentApiService(
                (string) Configuration::get(self::CONFIG_MERCHANT_ID),
                (string) Configuration::get(self::CONFIG_SERVICE_ACCOUNT_JSON)
            );

            foreach ($this->resolveCombinationIds($product, $idProductAttribute) as $idAttribute) {
                $combination = null;
                if ($idAttribute > 0) {
                    $combination = new Combination($idAttribute);
                    if (!Validate::isLoadedObject($combination)) {
                        continue;
                    }
                }

                $data = $transformer->transform($product, $idLang, $idCurrency, $combination);
                $api->patchProduct($data);
            }
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

    /**
     * The combination ids to push for this product: the explicit one when
     * the caller named it, otherwise all of the product's combinations, or
     * [0] for a product without any.
     *
     * @return array<int, int>
     */
    private function resolveCombinationIds(Product $product, int $idProductAttribute): array
    {
        if ($idProductAttribute > 0) {
            return [$idProductAttribute];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `id_product_attribute`
             FROM `' . _DB_PREFIX_ . 'product_attribute`
             WHERE `id_product` = ' . (int) $product->id
        );

        if (!$rows) {
            return [0];
        }

        return array_map(static fn (array $row): int => (int) $row['id_product_attribute'], $rows);
    }
}
