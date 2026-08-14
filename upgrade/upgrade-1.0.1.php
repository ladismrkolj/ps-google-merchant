<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.0 registered the back-office tab under a parent tab class
 * ("CONFIGURE") that does not exist in PrestaShop. Tab::add() still
 * succeeded (id_parent has no DB-level foreign key), but the resulting
 * entry was effectively unreachable -- opening "Configure" produced a
 * "page not found" instead of the settings screen.
 *
 * This upgrade removes that broken row (if present) and re-creates it
 * correctly, nested under the Modules menu. Running it via the normal
 * module upgrade flow (instead of uninstall/install) also preserves the
 * existing ps_gmc_category_mapping / ps_gmc_product_rule data.
 */
function upgrade_module_1_0_1(Module $module): bool
{
    $idTab = (int) Tab::getIdFromClassName('AdminGmcFeedConfigurationController');
    if ($idTab > 0) {
        (new Tab($idTab))->delete();
    }

    $tab = new Tab();
    $tab->class_name = 'AdminGmcFeedConfigurationController';
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
