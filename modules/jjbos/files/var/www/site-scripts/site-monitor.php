<?php

/**
 * Display details about the health of an Acquia Hosting site. Do not
 * assume Drupal is installed.
 */

error_reporting(E_ALL);

header("Content-Type: text/plain");
header("Cache-Control: max-age=0");

$time_start = microtime(TRUE);
$str256k = str_repeat('01234567', 1024*32);
$exceptions = array();
$connect_only = FALSE;

// Identify the site and db we're testing via CLI or $_GET.
if (!empty($_SERVER['argv'])) {
  while ($arg = array_shift($_SERVER['argv'])) {
    if ($arg == '--site') {
      $site = array_shift($_SERVER['argv']);
    }
    else if ($arg == '--db') {
      $dbname = array_shift($_SERVER['argv']);
    }
  }
}
if (!isset($site)) {
  if (isset($_GET['site']) && preg_match('@^[a-z0-9_]+$@', $_GET['site'])) {
    $site = $_GET['site'];
  }
}
if (!isset($site)) {
  if (preg_match('@/(?:var|mnt)/www/html/([^/]+)/docroot@', $_SERVER['DOCUMENT_ROOT'], $m)) {
    $site = $m[1];
  }
}
if (!isset($site)) {
  print "no site\n";
  return;
}
if (!is_dir("/var/www/html/$site")) {
  print "site $site is not on this server\n";
  return;
}

// Try to get dbname from settings file
if (!isset($dbname)) {
  if (file_exists("/var/www/site-php/{$site}/ah-site-stage") && file_exists("/var/www/site-php/{$site}/ah-site-group")) {
    $sitestage = file_get_contents("/var/www/site-php/{$site}/ah-site-stage");
    $sitegroup = file_get_contents("/var/www/site-php/{$site}/ah-site-group");
      if (file_exists("/var/www/site-php/{$site}/D6-{$sitestage}-{$sitegroup}-settings.inc")) {
      $conf['acquia_use_early_cache'] = TRUE;
        require("/var/www/site-php/{$site}/D6-{$sitestage}-{$sitegroup}-settings.inc");
        if (isset($conf['acquia_hosting_site_info']['db']['name'])) {
          $dbname = $conf['acquia_hosting_site_info']['db']['name'];
        }
    }
  }
}

if (!isset($dbname)) {
  if (isset($_GET['db']) && preg_match('@^[a-z0-9_]+$@', $_GET['db'])) {
    $dbname = $_GET['db'];
  }
}
if (!isset($dbname)) {
  $dbname = $site;
}
if (isset($_GET['connect'])) {
  $connect_only = TRUE;
}

print "site=$site\n";
print "db=$dbname\n";

try {
  // Test "bootstrap"
  if (! file_exists("/var/www/site-php/$site/D6-$dbname-settings.inc")) {
    throw new Exception("db $dbname not used from this server");
  }
  $time_bootstrap_start = microtime(TRUE);

  // Tell the settings include file not to load D5-D6-settings.inc
  // since it uses Drupal's variable_get() API, and this script isn't Drupal.
  global $conf;
  $conf['acquia_use_early_cache'] = TRUE;
  require "/var/www/site-php/$site/D6-$dbname-settings.inc";

  // Test the db url the site is actually using, and report the server.
  $db_info = $conf['acquia_hosting_site_info']['db'];

  require_once("/usr/share/php/Net/DNS2_wrapper.php");
  try {
    $resolver = new Net_DNS2_Resolver(array('nameservers' => array('127.0.0.1', 'dns-master')));
    $response = $resolver->query("cluster-{$db_info['db_cluster_id']}.mysql", 'CNAME');
    $server_id = $response->answer[0]->cname;
  }
  catch (Net_DNS2_Exception $e) {
    $server_id = "";
  }

  if (isset($db_info['db_url_ha'][$server_id])) {
    $db_url = $db_info['db_url_ha'][$server_id];
  }

  // $db_url is always set by include file, but just in case...
  if (!isset($db_url)) {
    $db_url = $db_info['db_url'];
  }
  // The include file set $db_url['default']; we need a string.
  elseif (is_array($db_url)) {
    $db_url = $db_url['default'];
  }
  // If we did not read a control file, assume the default server for the site.
  if (!isset($server_id)) {
    $server_id = reset($db_info['db_servers']);
  }

  // $server_id should always be a base hostname, but be paranoid.
  if (preg_match('@^.*-(\d+)@', $server_id, $m)) {
    printf("db_server=%d\n", $m[1]);
  }
  else {
    printf("db_server=%d\n", $server_id);
  }
  
  $db = db_connect($db_url);
  if (!isset($db)) {
    throw new Exception("db connection to $db_url failed");
  }
  
  $time_bootstrap_end = microtime(TRUE);
  printf("db_connect=%.4f\n", $time_bootstrap_end-$time_bootstrap_start);
  
  if($connect_only === FALSE)
  {
    // Test database
    $time_db_start = microtime(TRUE);
    $res = @mysqli_query($db, "SELECT 1 FROM __ACQUIA_MONITORING LIMIT 1");
    if (empty($res)) {
      mysqli_query($db, "CREATE TABLE IF NOT EXISTS __ACQUIA_MONITORING (
    s text NOT NULL
  ) ENGINE=InnoDB");
      $time_db_create = microtime(TRUE);
      printf("db_create=%.4f\n", $time_db_create-$time_db_start);
    }
    mysqli_query($db, "BEGIN");
    mysqli_query($db, "DELETE FROM __ACQUIA_MONITORING");
    mysqli_query($db, "INSERT INTO __ACQUIA_MONITORING (s) VALUES ('$str256k')");
    $res = mysqli_query($db, 'SELECT COUNT(*) FROM __ACQUIA_MONITORING');
    $row = mysqli_fetch_row($res);
    if ($row[0] != 1) {
      throw new Exception("expected 1 row, got {$row[0]}");
    }
    mysqli_query($db, "COMMIT");
    $time_db_end = microtime(TRUE);
    printf("db_query=%.4f\n", $time_db_end-$time_db_start);
  }
}
catch (Exception $e) {
  $exceptions[] = $e;
}

//Print hostname
$hostname = php_uname('n');
printf("hostname=%s\n", $hostname);

// Print apc stats.
foreach (get_apc_stats() as $name => $value) {
  printf("%s=%.2f\n", $name, $value);
}

try {
  // Test glusterfs
  $time_gfs_start = microtime(TRUE);
  $tmpdir = "/mnt/gfs/$site";
  $tmpfile = tempnam($tmpdir, 'monitor');
  $site_re = preg_quote($site);
  if (preg_match("@^(?:/mnt|/vol/ebs1)/gfs/$site_re/monitor.+@", $tmpfile) && file_exists($tmpfile)) {
    if (file_put_contents($tmpfile, $str256k) == strlen($str256k)) {
      $time_gfs_write = microtime(TRUE);
      if (file_get_contents($tmpfile) == $str256k) {
        $time_gfs_read = microtime(TRUE);
        printf("gfs_write=%.4f\n", $time_gfs_write-$time_gfs_start);
        printf("gfs_read=%.4f\n", $time_gfs_read-$time_gfs_write);
      }
      else {
        throw new Exception("file_get_contents($tmpdir) failed");
      }
    }
    else {
      throw new Exception("file_put_contents($tmpdir) failed");
    }
    if (unlink($tmpfile) !== TRUE) {
      throw new Exception("unlink($tmpfile) failed");
    }
  }
  else {
    throw new Exception("tempnam failed: $tmpfile");
  }
}
catch (Exception $e) {
  $exceptions[] = $e;
}

$time_end = microtime(TRUE);
printf("total=%.4f\n", $time_end-$time_start);

if (!empty($exceptions)) {
  foreach ($exceptions as $e) {
    print "failure: ".$e->getMessage()."\n";
  }
}
else {
  print "success\n";
}

function get_apc_stats() {
  // Don't try to get APC stats if opcache is on.
  if (function_exists('apc_sma_info') && !function_exists('opcache_reset')) {
    $apc_info = apc_sma_info();
    $stats = array(
      'apc_free' => $apc_info['avail_mem']/1024/1024,
      'apc_total' => $apc_info['seg_size']/1024/1024,
    );
    $stats['apc_used'] = $stats['apc_total'] - $stats['apc_free'];
  }
  // TODO Handle opcache stats. It doesn't currently document a status function.
  else {
    $stats = array();
  }
  return $stats;
}

/**
 * Initialise a database connection. Copied from Drupal 6.
 */
function db_connect($url) {
  // Check if MySQLi support is present in PHP
  if (!function_exists('mysqli_init') && !extension_loaded('mysqli')) {
    throw new Exception('mysqli unavailable');
  }

  $url = parse_url($url);

  // Decode url-encoded information in the db connection string
  $url['user'] = urldecode($url['user']);
  // Test if database url has a password.
  $url['pass'] = isset($url['pass']) ? urldecode($url['pass']) : '';
  $url['host'] = urldecode($url['host']);
  $url['path'] = urldecode($url['path']);
  if (!isset($url['port'])) {
    $url['port'] = NULL;
  }

  $connection = mysqli_init();
  @mysqli_real_connect($connection, $url['host'], $url['user'], $url['pass'], substr($url['path'], 1), $url['port'], NULL, MYSQLI_CLIENT_FOUND_ROWS);

  if (mysqli_connect_errno() > 0) {
    throw new Exception("mysqli connect error: " . mysqli_connect_error());
  }

  return $connection;
}
