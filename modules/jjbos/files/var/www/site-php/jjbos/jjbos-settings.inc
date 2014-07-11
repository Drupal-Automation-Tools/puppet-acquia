<?php
/**
 * This is a stage- and version-agnostic AH settings include file. For
 * a group of sites (like foo, foostg, and foodev), the site group
 * name is the name of the production AH site, and this file ALWAYS
 * lives at
 *
 *   /var/www/site-php/<sitegroup>/<dbrole>-settings.inc
 *
 * It identifies the actual AH site name and Drupal version for this
 * request and includes the stage- and version-specific AH settings file
 *
 *   /var/www/site-php/<sitename>/D<ver>-<stage>-<dbrole>-settings.inc
 *
 * Since this file is executing in the context of Drupal settings.php,
 * on failure all it can do is print a message and exit, terminating
 * the request. Such errors should "never" happen, however.
 */

require_once '/var/www/site-scripts/site-info.php';

function ah_unknown_drupal_core_version() {
    print "This site is not using a recognized version of Drupal core. Please contact Acquia Support for assistance.";
    exit;
}

// Return the Drupal core version as a single-digit string, or exit
// with an error message.
function ah_drupal_core_version() {
    if (defined('DRUPAL_CORE_COMPATIBILITY')) {
        // Only 7.x+ ever actually exists at this point.
        switch (DRUPAL_CORE_COMPATIBILITY) {
            case '8.x':
                return '8';
            case '7.x':
                return '7';
            case '6.x':
                return '6';
            case '5.x':
                return '5';
            default:
                ah_unknown_drupal_core_version();
                break;
        }
    }
    // in D8, the old var got removed:
    // https://drupal.org/node/2067017
    else if (class_exists('Drupal') && defined('Drupal::CORE_COMPATIBILITY')) {
        switch (Drupal::CORE_COMPATIBILITY) {
            case '8.x':
                return '8';
            case '7.x':
                return '7';
            case '6.x':
                return '6';
            case '5.x':
                return '5';
            default:
                ah_unknown_drupal_core_version();
                break;
        }
    }
    else if (function_exists('drupal_init_language')) {
        return '6';
    }
    else if (function_exists('_drupal_cache_init')) {
        return '5';
    }
    ah_unknown_drupal_core_version();
}

function ah_settings_include() {
    list($ah_site_name, $ah_site_group, $ah_site_stage, $secret) = ah_site_info();

    // Set up AH_* environment variables expected from Apache if we are not
    // running under Apache.
    if (!isset($_ENV['AH_SITE_NAME'])) {
        $_ENV['AH_SITE_NAME'] = $ah_site_name;
    }
    if (!isset($_ENV['AH_SITE_GROUP'])) {
        $_ENV['AH_SITE_GROUP'] = $ah_site_group;
    }
    if (!isset($_ENV['AH_SITE_ENVIRONMENT'])) {
        $_ENV['AH_SITE_ENVIRONMENT'] = $ah_site_stage;
    }
    $prod_envvar = ($ah_site_stage == 'prod' ? 'AH_PRODUCTION' : 'AH_NON_PRODUCTION');
    if (!isset($_ENV[$prod_envvar])) {
        $_ENV[$prod_envvar] = 1;
    }

    $ah_site_php_dir = "/var/www/site-php/{$ah_site_name}";
    $ah_drupal_version = ah_drupal_core_version();
    return "{$ah_site_php_dir}/D{$ah_drupal_version}-{$ah_site_stage}-" . basename(__FILE__);
}

// The settings include file must be included in the global context.
require ah_settings_include();
