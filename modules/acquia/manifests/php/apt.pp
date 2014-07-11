class acquia::php::apt {
  if defined('apt::source') {
    # Puppetlabs/apt module
    apt::ppa { 'ppa:skettler/php':
      notify => Exec['acquia::php::apt-get update']
    }
  }

  exec { 'acquia::php::apt-get update':
    command     => 'apt-get update',
    path        => '/usr/bin',
    refreshonly => true,
  }
}
