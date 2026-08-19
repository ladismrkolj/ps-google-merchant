<?php
/**
 * gmcfeedmanager - database installer.
 *
 * This file is `require`d by Gmcfeedmanager::install(). It must return
 * `false` (or nothing at all, since PHP implicitly returns 1 for a file
 * that runs to completion) so the caller can detect a failed migration.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';

$sql = [];

/*
 * Category taxonomy mapping: links a PrestaShop category (per shop, for
 * multistore installs) to a Google Product Taxonomy node.
 */
$sql[] = '
CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gmc_category_mapping` (
    `id_category_mapping` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_category` INT UNSIGNED NOT NULL,
    `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
    `google_category_id` INT UNSIGNED NOT NULL,
    `google_category_name` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id_category_mapping`),
    KEY `idx_id_category` (`id_category`),
    UNIQUE KEY `idx_cat_shop` (`id_category`, `id_shop`)
) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
';

/*
 * Per product (or per combination) feed overrides: exclusion flag, custom
 * title/GTIN and up to five custom labels.
 */
$sql[] = '
CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gmc_product_rule` (
    `id_rule` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_product` INT UNSIGNED NOT NULL,
    `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_excluded` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `custom_title` VARCHAR(255) DEFAULT NULL,
    `custom_gtin` VARCHAR(50) DEFAULT NULL,
    `custom_label_0` VARCHAR(100) DEFAULT NULL,
    `custom_label_1` VARCHAR(100) DEFAULT NULL,
    `custom_label_2` VARCHAR(100) DEFAULT NULL,
    `custom_label_3` VARCHAR(100) DEFAULT NULL,
    `custom_label_4` VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (`id_rule`),
    KEY `idx_id_product` (`id_product`),
    UNIQUE KEY `idx_product_combination` (`id_product`, `id_product_attribute`)
) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
';

/*
 * Flat per-destination-country shipping rates applied to every feed item
 * (g:shipping) and every Content API offer (shipping[]). One row per
 * country per shop; region/service are optional descriptive sub-attributes,
 * not part of the key, since almost every merchant only needs one rate per
 * country.
 */
$sql[] = '
CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gmc_shipping_rate` (
    `id_shipping_rate` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
    `iso_country` VARCHAR(2) NOT NULL,
    `region` VARCHAR(100) DEFAULT NULL,
    `service` VARCHAR(100) DEFAULT NULL,
    `price` DECIMAL(10,2) UNSIGNED NOT NULL,
    `currency_iso` VARCHAR(3) NOT NULL,
    PRIMARY KEY (`id_shipping_rate`),
    UNIQUE KEY `idx_shop_country` (`id_shop`, `iso_country`)
) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
