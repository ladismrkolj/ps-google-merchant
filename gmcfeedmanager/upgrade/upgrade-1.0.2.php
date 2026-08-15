<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Fixes the back-office tab so "Configure" stops returning
 * "The controller AdminGmcFeedConfigurationController is missing or invalid."
 *
 * Root cause: the tab was registered with class_name set to the full PHP
 * class name (AdminGmcFeedConfigurationController). PrestaShop's
 * Dispatcher::getControllersInDirectory() derives its lookup keys by
 * stripping a trailing "Controller.php" from each filename, so the file
 * AdminGmcFeedConfigurationController.php is only ever reachable as
 * "AdminGmcFeedConfiguration". The suffixed name matched nothing and the
 * dispatcher fell through to AdminNotFoundController.
 *
 * Re-points the tab (and cleans up the unusable suffixed row from 1.0.0 /
 * 1.0.1) without touching ps_gmc_category_mapping or ps_gmc_product_rule.
 */
function upgrade_module_1_0_2(Module $module): bool
{
    // Drop the old, unreachable tab registered under the suffixed name.
    $oldIdTab = (int) Tab::getIdFromClassName('AdminGmcFeedConfigurationController');
    if ($oldIdTab > 0) {
        (new Tab($oldIdTab))->delete();
    }

    // Nothing to do if a correct tab somehow already exists.
    if ((int) Tab::getIdFromClassName('AdminGmcFeedConfiguration') > 0) {
        return true;
    }

    $tab = new Tab();
    $tab->class_name = 'AdminGmcFeedConfiguration';
    $tab->module = $module->name;
    $tab->icon = 'shopping_basket';
    $tab->id_parent = 0;

    foreach (['AdminParentModulesSf', 'AdminParentModules', 'AdminModules'] as $candidate) {
        $idParent = (int) Tab::getIdFromClassName($candidate);
        if ($idParent > 0) {
            $tab->id_parent = $idParent;
            break;
        }
    }

    foreach (Language::getLanguages(false) as $lang) {
        $tab->name[$lang['id_lang']] = 'Google Merchant Feed';
    }

    return $tab->add();
}
