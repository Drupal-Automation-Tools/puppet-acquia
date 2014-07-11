<?php
/**
 * Identify the AH sitename, stage, and secret for the current page request or
 * script, if possible.
 *
 * @return
 *   An array containing sitename, sitegroup, stage, and secret, or NULL if
 *   the site directory cannot be found.
 */
function ah_site_info() {
  // Start by finding a "site directory" which will identify the site.
  // If this is a Drupal page request, use AH environment vars.
  if (!empty($_SERVER['AH_SITE_GROUP']) && !empty($_SERVER['AH_SITE_ENVIRONMENT'])) {
    $site_dir = "/var/www/html/{$_SERVER['AH_SITE_GROUP']}.{$_SERVER['AH_SITE_ENVIRONMENT']}";
  }
  // This is probably redundant with the AH env vars, but is what we used to
  // use and can't hurt.
  else if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $site_dir = $_SERVER['DOCUMENT_ROOT'];
  }
  // If this is not a page request, no AH env vars or DOCUMENT_ROOT are 
  // available. Drush?
  else if (function_exists('drush_get_option')) {
    $site_dir = drush_get_option(array("r", "root"), $_SERVER['PWD']);
  }
  // Otherwise, perhaps we're a script within docroot but running on the
  // command line (e.g. scripts/run-test.sh).
  else {
    $site_dir = dirname(realpath($_SERVER['SCRIPT_FILENAME']));
  }

  // We have a variety of site dir symlinks. Currently, the canonical location
  // is always [sitename], not [site].[env]. Make sure we are pointing to the
  // canonical location.
  $site_dir = realpath($site_dir) . '/';

  // Verify the site directory is in an expected location, extract the
  // sitename from it, and read site info from /var/www/site-php/[sitename].
  if (isset($site_dir) && (
      preg_match('@^/(?:var|mnt)/www/html/([a-z0-9_]+)/@i', $site_dir, $m) ||
      preg_match('@^/mnt/gfs/([a-z0-9_]+)/@i', $site_dir, $m) ||
      preg_match('@^/vol/ebs1/gfs/([a-z0-9_]+)/@i', $site_dir, $m))) {
    $ah_site_name = $m[1];
    $ah_site_php_dir = "/var/www/site-php/{$ah_site_name}";
    $ah_site_group = file_get_contents("{$ah_site_php_dir}/ah-site-group");
    $ah_site_stage = file_get_contents("{$ah_site_php_dir}/ah-site-stage");
    $secret = base64_decode(file_get_contents("{$ah_site_php_dir}/.secret"));
    return array($ah_site_name, $ah_site_group, $ah_site_stage, $secret);
  }

  return NULL;
}
