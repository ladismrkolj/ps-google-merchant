<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.5: the carrier import can be restricted to selected carriers.
 *
 * Nothing to migrate -- an empty GMCFEEDMANAGER_IMPORT_CARRIERS means
 * "every active carrier is ticked", which is how the import already
 * behaved, so existing installs keep their current behaviour until the
 * merchant unticks something.
 */
function upgrade_module_1_0_5(Module $module): bool
{
    if (Configuration::get(Gmcfeedmanager::CONFIG_IMPORT_CARRIERS) === false) {
        Configuration::updateValue(Gmcfeedmanager::CONFIG_IMPORT_CARRIERS, '');
    }

    return true;
}
