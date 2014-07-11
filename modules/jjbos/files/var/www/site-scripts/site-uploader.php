<?php

require '/var/www/site-scripts/site-info.php';

/**
 * Allow file uploads to an AH site directly from acquia.com. Usage,
 * via POST:
 *
 * t: time submission form was generated
 * files[u]: uploaded file
 * r: return URL when upload 
 * <stage>: HMAC-SHA256(t.r,secret)
 * y: type of file, optional; sar = Drupal site archive
 *
 * For chunked uploads additional parameters are accepted.
 *
 * file_size: Total file size in bytes. This upload script is detecting
 *   chunked upload by file_size param presence.
 * position: Position of file reader in bytes. Script is detecting
 *   final upload by calculating: position + size(uploaded_chunk) < file_size
 * hash: (optional) MD5 hash of whole file. After final chunk MD5 is
 *   calculated from assembled file and compared to expected value
 * nonce: (optional) When master don't want to reveal site secret
 *   derived secret can be used for singing requests. Derived secret
 *   is calculated is newsecret = HMAC-SHA256(nonce, secret). By sending
 *   nonce can upload script calculate newsecret.
 *
 * If r or <stage> is missing or the HMAC is wrong, exit with a terse
 * message. Otherwise, redirect to r with query parameters:
 *
 * err: An error message.
 * env: The site stage name to which the upload was written.
 * path: The full path to which the upload was written.
 * time: The timestamp at which the upload was processed, for replay detection.
 * sig: HMAC-SHA256(query parameters appended in alphabetical order,secret)
 *
 * Chunked uploads are returning by default HTTP 200 with JSON body response.
 * After last chunk returns HTTP 302 with location from request and params
 * described above.
 */

error_reporting(E_ALL);
header("Cache-Control: max-age=0");

// Validate the request.
if (empty($_POST['r']) || empty($_POST['t'])) {
  print "invalid request\n";
  exit;
}

$site_info = ah_site_info();
if (!$site_info) {
  print "cannot find site info\n";
  exit;
}
list($site_name, $site_group, $stage_name, $secret) = $site_info;

// If this request is coming from Acquia Connector, create new secret key
// which is not shared with Acquia Connector.
if ($_POST['nonce']) {
  $secret = hash_hmac('sha256', $_POST['nonce'], $secret);
}

// Verify the signature.
$hmac = hash_hmac('sha256', $_POST['t'].$_POST['r'], $secret);
if (empty($_POST[$stage_name]) || $hmac != $_POST[$stage_name]) {
  print "authorization denied\n";
  exit;
}

$resp = array('err' => '', 'path' => '', 'env' => '', 'time' => '');
$r = parse_url($_POST['r']);

// Verify the timestamp to prevent replay attacks. If we have no
// record of the previous import form time, the max age is ten
// minutes. If we do have a record of the previous import, the max age
// is "newer than the previous one."
$last_site_import = "/mnt/gfs/$site_name/import/.last-site-import";
$prev_time = @file_get_contents($last_site_import);
if (empty($prev_time)) {
  $prev_time = time() - 600;
}
if ($_POST['t'] <= $prev_time) {
  $resp['err'] = 'For security reasons, the Site Import form must be submitted soon after it is displayed. Please try again.';
}
// Handle the upload if present.
else if (!empty($_FILES['files']['name']['u'])) {
  $target = $original_target = "/mnt/gfs/$site_name/import/" . basename($_FILES['files']['name']['u']);
  // Upload is comming from connector which can upload in chunks
  if ($_POST['file_size']) {
    $chars = strlen((string) $_POST['file_size']);
    $target .= '.chunk.' . sprintf("%0{$chars}s", $_POST['position']);
  }

  if (move_uploaded_file($_FILES['files']['tmp_name']['u'], $target)) {
    // By default assume that upload is coming from network cloud UI
    $complete = TRUE;
    // File could be uploaded in chunks, so check if upload was finished
    if (isset($_POST['file_size']) && (($_POST['position'] + filesize($target)) < $_POST['file_size'])) {
      $complete = FALSE;
      $resp['json'] = TRUE;
    }
    // If upload is chunked and finished assemble file, remove chunks and verify MD5
    elseif (isset($_POST['file_size'])) {
      // Assemble file chunks into one file
      exec("cat {$original_target}.chunk.* > $original_target");
      // Optinally MD5 hash of file can be sent in URL for final verification
      if (isset($_POST['hash']) && $_POST['hash'] != md5_file($original_target)) {
        $resp['err'] = 'MD5 validation failed';
        // Don't try validating content of file since it wasn't assembled correctly
        $complete = FALSE;
      }
      // Remove chunks
      exec("rm {$original_target}.chunk.*");
      // Update name of target file
      $target = $original_target;
    }

    // Proceed only if file upload is complete
    if ($complete) {
      // Add details to response
      $resp['path'] = $target;
      $resp['env'] = $stage_name;
      file_put_contents($last_site_import, $_POST['t']);
      if (!empty($_POST['y']) && $_POST['y'] == 'sar') {
        // Test if archive contains all required files
        ah_site_uploader_test_archive($target, $resp);
      }
    }
  }
  else {
    // Better error handling might be nice.
    $resp['err'] = 'error occurred, try again';
  }
}
// Handle a missing upload.
else {
  $resp['err'] = 'Drupal site archive file field is required: XXX ' . serialize($_FILES);
}

// For chunk uploads print json
if ($resp['json']) {
  $resp['time'] = time();
  foreach ($resp as $k => $v) {
    $sig .= $v;
  }
  $resp['sig'] = hash_hmac('sha256', $sig, $secret);
  // Send correct MIME type
  header("Content-Type: application/json");
  print json_encode($resp);
  exit();
}

// For non-json responses set response to text/plain
header("Content-Type: text/plain");

// If upload is comming form connector add nonce to URL which can be calculated later on network
if ($_POST['nonce']) {
  $resp['nonce'] = $_POST['nonce'];
}

// Compute the reply signature.
$sig = '';
$resp['time'] = time();
ksort($resp);
foreach ($resp as $k => &$v) {
  $sig .= $v;
  $v = "$k=".rawurlencode($v);
}
$resp['sig'] = 'sig='.hash_hmac('sha256', $sig, $secret);

// Redirect to the reply URL.
$r_host = !empty($r['port']) ? "{$r['host']}:{$r['port']}" : $r['host'];
$url = "{$r['scheme']}://$r_host${r['path']}?".implode('&', $resp);
header('Location: '. $url, TRUE, 200);

exit();

/**
 * Test uploaded archive if its in proper format and contains all required
 * files. If not erros are stored in $resp array
 *
 * @param $target
 *   Uploaded file
 * @param $resp
 *   Response that will be sent to browser
 */
function ah_site_uploader_test_archive($target, &$resp) {
  exec("tar tzf $target", $output, $ret);
  $output = implode("\n", $output);

  // Find index.php, and determine this tarball's docroot dir, which
  // might be empty (index.php), or root (./index.php), or a docroot
  // (./some_dir/index.php). Arrange for $docroot[1] to be either empty or
  // end with a /.
  $has_indexphp = preg_match('@^(?:((?:\./)?(?:[^/\n]+))/)?index.php$@m', $output, $docroot);
  if (!empty($docroot[1])) {
    $docroot[1] .= '/';
  }

  // Count the number of sites dirs with settings.php files and files
  // directories.
  $count_settingsphp = preg_match_all('@^'.preg_quote($docroot[1]).'sites/[^/\n]+/settings.php$@m', $output, $settings_phps);
  $count_filesdirs = preg_match_all('@^'.preg_quote($docroot[1]).'sites/[^/\n]+/files/$@m', $output, $filesdirs);

  // Count the number of sql dumps in the root, plus in the docroot but
  // only if the docroot is a sub-dir (not empty or ./). Record all SQL
  // dumps into $sqls[0].
  $count_sqls = preg_match_all('@^'.preg_quote($docroot[1]).'[^/\n.][^/\n]*\.sql$@m', $output, $sqls);
  if (strlen($docroot[1]) > 2) {
    $count_sqls += preg_match_all('@^(?:\./)?[^/\n.][^/\n]*\.sql$@m', $output, $docroot_sqls);
    $sqls[0] = array_merge($sqls[0], $docroot_sqls[0]);
  }

  // Validate.
  if ($ret != 0) {
    $resp['err'] = 'A Drupal site archive file must be a gzip-compressed tar file.';
  }
  else if (!$has_indexphp) {
    $resp['err'] = "The uploaded file is not in Drupal site archive format: no index.php found in tar root or top-level directory.";
  }
  else if ($count_settingsphp > 1) {
    $resp['err'] = "The uploaded file is not in Drupal site archive format: it must have at most one sites directory containing settings.php, but it has $count_settingsphp: ".implode(', ', $settings_phps[0]);
  }
  else if ($count_settingsphp == 0 && $count_filesdirs > 1) {
    $resp['err'] = "The uploaded file is not in Drupal site archive format: with no settings.php file, it must have at most one sites directory containing a files directory, but it has $count_filesdirs: ".implode(', ', $filesdirs[0]);
  }
  else if ($count_sqls > 1) {
    $resp['err'] = "The uploaded file is not in Drupal site archive format: it must only contain a single SQL dump file, but contains $count_sqls: ".implode(', ', $sqls[0]);
  }
}

