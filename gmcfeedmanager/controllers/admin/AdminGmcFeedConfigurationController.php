<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// Defensive: register the module's autoloader ourselves rather than assume
// gmcfeedmanager.php already ran in this request (require_once is a no-op
// if it did).
require_once __DIR__ . '/../../src/autoload.php';

use GmcFeedManager\Service\GoogleCategoryService;

/**
 * Back-office configuration screen for gmcfeedmanager: general settings,
 * category taxonomy mapping, apparel/attribute mapping and a pre-flight
 * diagnostics dashboard, all on one page (Bootstrap nav-tabs).
 *
 * This is a fully custom "view" screen rather than the usual
 * AdminController list/form pair: $this->table is only set to a harmless
 * real table so core bootstrapping has something to look at, and rendering
 * goes through renderView() (see AdminControllerCore::initContent(), which
 * dispatches to renderView() when $this->display === 'view') rather than
 * the default renderList()/renderForm().
 */
class AdminGmcFeedConfigurationController extends ModuleAdminController
{
    public function __construct()
    {
        // A harmless, real core table so AdminController's own bootstrapping
        // (which references $this->table before we get a say) has something
        // valid to look at; we never render a list/form off it.
        $this->table = 'configuration';
        $this->identifier = 'id_configuration';
        $this->bootstrap = true;

        parent::__construct();

        // Must be set *after* parent::__construct(), which otherwise
        // resets $this->display to its own default ('list').
        $this->display = 'view';
        $this->show_toolbar = false;
        $this->meta_title = $this->l('Google Merchant Feed Manager');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitGmcGeneral')) {
            $this->processGeneralSettings();
        } elseif (Tools::isSubmit('submitGmcApparel')) {
            $this->processApparelSettings();
        } elseif (Tools::isSubmit('submitGmcRegenerateToken')) {
            $this->processRegenerateToken();
        }

        return parent::postProcess();
    }

    private function processGeneralSettings(): void
    {
        Configuration::updateValue(Gmcfeedmanager::CONFIG_ID_LANG, (int) Tools::getValue('id_lang'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_ID_CURRENCY, (int) Tools::getValue('id_currency'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_API_SYNC_ENABLED, (int) Tools::getValue('api_sync_enabled'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_MERCHANT_ID, (string) Tools::getValue('merchant_id'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_CONTENT_LANGUAGE, (string) Tools::getValue('content_language'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_TARGET_COUNTRY, (string) Tools::getValue('target_country'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_CONDITION, (string) Tools::getValue('default_condition'));

        $chunkSize = (int) Tools::getValue('chunk_size');
        Configuration::updateValue(Gmcfeedmanager::CONFIG_CHUNK_SIZE, $chunkSize > 0 ? $chunkSize : 250);

        $serviceAccountJson = (string) Tools::getValue('service_account_json');
        if ($serviceAccountJson !== '') {
            $decoded = json_decode($serviceAccountJson, true);
            if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
                $this->errors[] = $this->l('The Service Account JSON payload is not valid (missing client_email or private_key).');

                return;
            }
            Configuration::updateValue(Gmcfeedmanager::CONFIG_SERVICE_ACCOUNT_JSON, $serviceAccountJson);
        }

        Tools::redirectAdmin($this->context->link->getAdminLink(Gmcfeedmanager::ADMIN_CONTROLLER) . '&conf=4');
    }

    private function processApparelSettings(): void
    {
        Configuration::updateValue(Gmcfeedmanager::CONFIG_ATTR_GROUP_COLOR, (int) Tools::getValue('attr_group_color'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_ATTR_GROUP_SIZE, (int) Tools::getValue('attr_group_size'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_FEATURE_GENDER, (int) Tools::getValue('feature_gender'));
        Configuration::updateValue(Gmcfeedmanager::CONFIG_FEATURE_AGE_GROUP, (int) Tools::getValue('feature_age_group'));

        Tools::redirectAdmin($this->context->link->getAdminLink(Gmcfeedmanager::ADMIN_CONTROLLER) . '&conf=4#apparel');
    }

    private function processRegenerateToken(): void
    {
        Configuration::updateValue(Gmcfeedmanager::CONFIG_FEED_TOKEN, bin2hex(random_bytes(20)));

        Tools::redirectAdmin($this->context->link->getAdminLink(Gmcfeedmanager::ADMIN_CONTROLLER) . '&conf=4');
    }

    /**
     * GET .../ajax_process_search_google_category
     * Typeahead search over the Google Product Taxonomy cache.
     */
    public function ajaxProcessSearchGoogleCategory(): void
    {
        $term = (string) Tools::getValue('term', '');
        $service = new GoogleCategoryService();

        $this->ajaxJsonResponse($service->search($term, 20));
    }

    /**
     * POST .../ajax_process_save_category_mapping
     */
    public function ajaxProcessSaveCategoryMapping(): void
    {
        $idCategory = (int) Tools::getValue('id_category');
        $googleCategoryId = (int) Tools::getValue('google_category_id');
        $googleCategoryName = (string) Tools::getValue('google_category_name');
        $idShop = (int) $this->context->shop->id;

        if ($idCategory <= 0 || $googleCategoryId <= 0 || $googleCategoryName === '') {
            $this->ajaxJsonResponse(['success' => false, 'message' => $this->l('Invalid mapping payload.')]);

            return;
        }

        $service = new GoogleCategoryService();
        $success = $service->saveMapping($idCategory, $idShop, $googleCategoryId, $googleCategoryName);

        $this->ajaxJsonResponse(['success' => $success]);
    }

    /**
     * POST .../ajax_process_delete_category_mapping
     */
    public function ajaxProcessDeleteCategoryMapping(): void
    {
        $idCategory = (int) Tools::getValue('id_category');
        $idShop = (int) $this->context->shop->id;

        $service = new GoogleCategoryService();
        $success = $service->deleteMapping($idCategory, $idShop);

        $this->ajaxJsonResponse(['success' => $success]);
    }

    /**
     * GET .../ajax_process_run_diagnostics
     */
    public function ajaxProcessRunDiagnostics(): void
    {
        $this->ajaxJsonResponse($this->buildDiagnosticsReport());
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function ajaxJsonResponse(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($data));
    }

    public function renderView()
    {
        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;

        $this->context->smarty->assign([
            'gmc_feed_url' => $this->buildFeedUrl(),
            'gmc_feed_token' => Configuration::get(Gmcfeedmanager::CONFIG_FEED_TOKEN),
            'gmc_id_lang' => (int) Configuration::get(Gmcfeedmanager::CONFIG_ID_LANG),
            'gmc_id_currency' => (int) Configuration::get(Gmcfeedmanager::CONFIG_ID_CURRENCY),
            'gmc_api_sync_enabled' => (bool) Configuration::get(Gmcfeedmanager::CONFIG_API_SYNC_ENABLED),
            'gmc_merchant_id' => Configuration::get(Gmcfeedmanager::CONFIG_MERCHANT_ID),
            'gmc_service_account_json' => Configuration::get(Gmcfeedmanager::CONFIG_SERVICE_ACCOUNT_JSON),
            'gmc_content_language' => Configuration::get(Gmcfeedmanager::CONFIG_CONTENT_LANGUAGE),
            'gmc_target_country' => Configuration::get(Gmcfeedmanager::CONFIG_TARGET_COUNTRY),
            'gmc_default_condition' => Configuration::get(Gmcfeedmanager::CONFIG_CONDITION),
            'gmc_chunk_size' => (int) Configuration::get(Gmcfeedmanager::CONFIG_CHUNK_SIZE),
            'gmc_languages' => Language::getLanguages(false),
            'gmc_currencies' => Currency::getCurrencies(false, true),
            'gmc_conditions' => ['new', 'refurbished', 'used'],
            'gmc_categories' => $this->getCategoryTree($idLang, $idShop),
            'gmc_attribute_groups' => AttributeGroup::getAttributesGroups($idLang),
            'gmc_features' => Feature::getFeatures($idLang),
            'gmc_attr_group_color' => (int) Configuration::get(Gmcfeedmanager::CONFIG_ATTR_GROUP_COLOR),
            'gmc_attr_group_size' => (int) Configuration::get(Gmcfeedmanager::CONFIG_ATTR_GROUP_SIZE),
            'gmc_feature_gender' => (int) Configuration::get(Gmcfeedmanager::CONFIG_FEATURE_GENDER),
            'gmc_feature_age_group' => (int) Configuration::get(Gmcfeedmanager::CONFIG_FEATURE_AGE_GROUP),
            'gmc_ajax_url' => $this->context->link->getAdminLink(Gmcfeedmanager::ADMIN_CONTROLLER),
            'current_index' => self::$currentIndex,
            'token' => $this->token,
        ]);

        return $this->context->smarty->fetch($this->getModuleTemplatePath('configuration.tpl'));
    }

    private function getModuleTemplatePath(string $template): string
    {
        return _PS_MODULE_DIR_ . 'gmcfeedmanager/views/templates/admin/' . $template;
    }

    private function buildFeedUrl(): string
    {
        $params = [
            'token' => Configuration::get(Gmcfeedmanager::CONFIG_FEED_TOKEN),
            'id_lang' => (int) Configuration::get(Gmcfeedmanager::CONFIG_ID_LANG),
            'id_currency' => (int) Configuration::get(Gmcfeedmanager::CONFIG_ID_CURRENCY),
        ];

        return $this->context->link->getModuleLink('gmcfeedmanager', 'feed', $params);
    }

    /**
     * Category rows for the mapping tab, each already carrying its Google
     * taxonomy mapping (empty strings when unmapped). Resolving this here
     * rather than indexing a lookup array in Smarty keeps the template
     * from reading array keys that may not exist, which PHP 8 warns about
     * (and _PS_MODE_DEV_ escalates into a 500).
     *
     * @return array<int, array{id_category: int, name: string, level_depth: int, google_category_id: string, google_category_name: string}>
     */
    private function getCategoryTree(int $idLang, int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_category`, cl.`name`, c.`level_depth`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . (int) $idLang . '
             WHERE c.`level_depth` > 0
             ORDER BY c.`nleft` ASC'
        );

        $mappings = (new GoogleCategoryService())->getAllMappings($idShop);

        $categories = [];
        foreach (($rows ?: []) as $row) {
            $idCategory = (int) $row['id_category'];
            $mapping = $mappings[$idCategory] ?? null;

            $categories[] = [
                'id_category' => $idCategory,
                'name' => (string) $row['name'],
                'level_depth' => (int) $row['level_depth'],
                'google_category_id' => $mapping ? (string) $mapping['id'] : '',
                'google_category_name' => $mapping ? $mapping['name'] : '',
            ];
        }

        return $categories;
    }

    /**
     * @return array<string, int>
     */
    private function buildDiagnosticsReport(): array
    {
        $idShop = (int) $this->context->shop->id;

        $missingIdentifiers = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             WHERE ps.`active` = 1
               AND (p.`ean13` IS NULL OR p.`ean13` = "")
               AND (p.`upc` IS NULL OR p.`upc` = "")
               AND (p.`reference` IS NULL OR p.`reference` = "")'
        );

        $totalCategories = (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT p.`id_category_default`)
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             WHERE ps.`active` = 1'
        );

        $mappedCategories = (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT gcm.`id_category`)
             FROM `' . _DB_PREFIX_ . 'gmc_category_mapping` gcm
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_category_default` = gcm.`id_category`
             WHERE gcm.`id_shop` = ' . $idShop
        );

        $missingCoverImage = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'image` i
                ON i.`id_product` = p.`id_product` AND i.`cover` = 1
             WHERE ps.`active` = 1 AND i.`id_image` IS NULL'
        );

        $outOfStockMissingAvailability = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             INNER JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = 0
                   AND (sa.`id_shop` = ' . $idShop . ' OR sa.`id_shop` = 0)
             WHERE ps.`active` = 1 AND sa.`quantity` <= 0 AND p.`out_of_stock` = 2'
        );

        return [
            'missing_identifiers' => $missingIdentifiers,
            'unmapped_categories' => max(0, $totalCategories - $mappedCategories),
            'missing_cover_image' => $missingCoverImage,
            'out_of_stock_missing_availability' => $outOfStockMissingAvailability,
        ];
    }
}
