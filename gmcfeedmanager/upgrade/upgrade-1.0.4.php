<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.4: direct-to-cart checkout links, per-country shipping rates and the
 * return policy label.
 *
 * Creates ps_gmc_shipping_rate and seeds the new configuration keys. Both
 * new features are off/empty by default, so upgrading changes nothing in
 * the feed until they are configured.
 */
function upgrade_module_1_0_4(Module $module): bool
{
    $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';

    $created = Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gmc_shipping_rate` (
            `id_shipping_rate` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `iso_country` VARCHAR(2) NOT NULL,
            `region` VARCHAR(100) DEFAULT NULL,
            `service` VARCHAR(100) DEFAULT NULL,
            `price` DECIMAL(10,2) UNSIGNED NOT NULL,
            `currency_iso` VARCHAR(3) NOT NULL,
            PRIMARY KEY (`id_shipping_rate`),
            UNIQUE KEY `idx_shop_country` (`id_shop`, `iso_country`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
    );

    if (!$created) {
        return false;
    }

    // Only seed keys that do not exist yet, so re-running the upgrade
    // cannot wipe a merchant's existing choices.
    foreach ([
        Gmcfeedmanager::CONFIG_CHECKOUT_LINK_ENABLED => 0,
        Gmcfeedmanager::CONFIG_SHIPPING_ENABLED => 1,
        Gmcfeedmanager::CONFIG_RETURN_POLICY_LABEL => '',
    ] as $key => $default) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, $default);
        }
    }

    return true;
}
