<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * An example of module upgrade file
 *
 * @param MoneyBadger $module
 *
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    // Warning when multiple upgrade available on a shop, all upgrade files will be included and called
    // Keep in mind if you call a custom function here it must have a unique name to avoid a fatal error "Cannot redeclare function"
    // When this will be called, you will have in parameter a module instance of previous version before new files loaded, so you cannot call a function introduced in your new version

    return true;
}
