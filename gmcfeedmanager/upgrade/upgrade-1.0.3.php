<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.3:
 *  - PrestaShop 9 compatibility. AdminController::l() and
 *    ModuleAdminController::l() were removed in PS 9, so the configuration
 *    screen fataled with "Attempted to call an undefined method named l()"
 *    the moment the controller was instantiated. Code change only, nothing
 *    to migrate here.
 *  - Registers the hooks added in this version (product add/delete,
 *    combination delete, specific price add/delete, category delete) on
 *    installs that were set up before they existed.
 */
function upgrade_module_1_0_3(Module $module): bool
{
    $success = true;

    foreach (Gmcfeedmanager::HOOKS as $hook) {
        if (!$module->isRegisteredInHook($hook)) {
            $success = $module->registerHook($hook) && $success;
        }
    }

    return $success;
}
