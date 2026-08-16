<?php
/**
 * gmcfeedmanager - database uninstaller.
 *
 * `require`d by Gmcfeedmanager::uninstall(). Drops the tables created in
 * sql/install.php. Configuration keys are removed separately by the module
 * class via Configuration::deleteByName().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = [];

$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'gmc_category_mapping`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'gmc_product_rule`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'gmc_shipping_rate`;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
