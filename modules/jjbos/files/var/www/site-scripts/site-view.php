<?php
/**
 * The 'view' link for each site environment on the Workflow page points to
 * this script. Redirect to /install.php when there is code but no database,
 * otherwise redirect to /.
 */

function main() {
  error_reporting(E_ALL);
  header("Cache-Control: max-age=0");
  
  $site = $_ENV['AH_SITE_GROUP'];
  $env = $_ENV['AH_SITE_ENVIRONMENT'];
  $sitename = "$site.$env";
  $domain = $_SERVER['HTTP_HOST'];
  $docroot = "/var/www/html/$sitename/docroot";
  $index_php = "$docroot/index.php";
  $index_html = "$docroot/index.html";
  $welcome_files = array(
    "$docroot/acquialogo.gif",
    "$docroot/files",
    $index_html,
  );
  $install_php = "$docroot/install.php";
  $index_url = "http://$domain/";
  $welcome_url = "http://$domain/AH_WELCOME";
  $install_url = "http://$domain/install.php";
  // With no $_ENV['HOME'], we cannot use the @$sitename drush alias.
  // With no $_ENV['USER'], drush6 uses /tmp/drush-/ (instead of drush-user)
  // for all sites, resulting in permission problems, and a PHP segfault (!).
  $pw = posix_getpwuid(posix_geteuid());
  $mysql_command = trim(shell_exec("USER={$pw['name']} /usr/local/bin/drush4 --site=$site --env=$env ah-sql-connect"));
  $tables = trim(shell_exec("echo SHOW TABLES | $mysql_command --skip-column-names | grep -v '^__ACQUIA_MONITOR'"));

  // If there is Drupal code but no database, go to install.php.
  if (file_exists($index_php) && file_exists($install_php) && empty($tables)) {
    header("Location: $install_url", TRUE, 302);
  }
  // If the docroot contains the old tags/WELCOME that contained a welcome
  // page, redirect to the new welcome page
  else if ($welcome_files == glob("$docroot/*")) {
    header("Content-Type: text/html");
    header("Location: $welcome_url", TRUE, 302);
  }
  else {
    // Otherwise, go to /.
    header("Location: $index_url", TRUE, 302);
  }
}

main();
