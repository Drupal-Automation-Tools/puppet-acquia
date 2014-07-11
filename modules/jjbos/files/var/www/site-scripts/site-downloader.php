<?php

/**
 * Allow file downloads from an AH site directly from acquia.com. Usage,
 * via GET:
 *
 * t: timestamp of the request
 * d: file to download
 * <stage>: HMAC-SHA256(t.d,secret)
 *
 * If <stage> is missing or the HMAC is wrong, exit with a terse
 * message. Otherwise, return the file content:
 *
 *
 */

require '/var/www/site-scripts/site-info.php';

error_reporting(E_ALL);
header("Cache-Control: max-age=0");

// Validate that the request parameters are available.
if (empty($_GET['d']) || empty($_GET['t'])) {
  header("HTTP/1.0 400 Bad Request", true, 400);
  print "invalid request\n";
  exit();
}

$filepath = $_GET['d'];
$filename = basename($filepath);

// Grab the data for the site we're dealing with in this request
$site_info = ah_site_info();
if (!$site_info) {
  print "cannot find site info\n";
  exit;
}
list($site_name, $site_group, $stage_name, $secret) = $site_info;

// Verify the signature.
$hmac = hash_hmac('sha256', $_GET['t'].$filepath, $secret);
if (empty($_GET[$stage_name]) || $hmac != $_GET[$stage_name]) {
  header("HTTP/1.0 401 Unauthorized", true, 401);
  print "authorization denied\n";
  exit();
}

// If the request is older than 10 minutes, we won't serve it anymore.
$time_limit = time() - 600;
if ($_GET['t'] <= $time_limit) {
  header("HTTP/1.0 403 Forbidden", true, 403);
  print "request is too old\n";
  exit();
}

// In some versions of PHP (notably the 5.3.10 on Precise), realpath is buggy
// and does not reliably resolve symlinks into real paths. This regex matches
// both /vol/ebs1/gfs/sitename/ and /vol/ebs1/gfs/sitename.env in order to
// accomodate such buggy versions of PHP.
$whitelisted_regexps = array(
  '@^/var/log/sites/' . $site_name . '(/|\.)@',
  '@^/mnt/log/sites/' . $site_name . '(/|\.)@',
  '@^/mnt/gfs/' . $site_name . '(/|\.)@',
  # On Dev Cloud, /mnt/gfs -> /vol/ebs1/gfs.
  '@^/vol/ebs1/gfs/' . $site_name . '(/|\.)@'
  );
$request_is_on_whitelist = false;

// if the file exists, we expand the path so users don't
// do strange symlink stuff or ../../etc/passwd
// otherwise we will just passed the unmodified path
// to the regexps
if (file_exists($filepath)){
  $filepath = realpath($filepath);
}

foreach ($whitelisted_regexps as $prefix) {
  // Use realpath() so nobody can get arround the restrictions
  // by using symlinks or paths including /../
  if (preg_match($prefix, $filepath)) {
    $request_is_on_whitelist = true;
  }
}

// Either the request isn't whitelisted OR the file doesn't even exist
// We don't want to give the user any clues about our filesystem
if (!$request_is_on_whitelist) {
  header("HTTP/1.0 403 Forbidden", true, 403);
  print "the requested file is not accessible\n";
  exit();
}

// Verify that requested file exists
if (!file_exists($filepath)) {
  header("HTTP/1.0 404 Not Found", true, 404);
  print "requested file does not exist\n";
  exit();
}

// Generate path to the .www_tmp dir, which is in the root of the sitedir on
// the givien file system.  Two are used, depending on the target file:
//   /mnt/gfs/{sitename}/.www_tmp
//   /var/log/sites/{sitename}/.www_tmp
$tmpdir = substr($filepath, 0, strpos($filepath, $site_name)) . "$site_name/.www_tmp";

// Generate a random filename to hide the file while it's hardlinked in an
// Apache readable path.
$randname = bin2hex(mcrypt_create_iv(64, MCRYPT_DEV_URANDOM));
$randpath = $tmpdir . "/" . $randname;

// Hardlink the file to an Apache readable location or fail.
if (!link($filepath, $randpath)) {
  header("HTTP/1.0 403 Forbidden", true, 403);
  print "the requested file is not accessible\n";
  exit();
}

// http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Content-Type: application/octet-stream");
header("Content-Length: ".filesize($filepath));
header('Content-Transfer-Encoding: binary');
header('Cache-Control: must-revalidate');
header("Pragma: no-cache");
header("Expires: 0");

// Transfer file using X-Sendfile so we don't worry about PHP execution time.
header("X-Sendfile-Temporary: $randpath");
exit();
