class acquia::php::apt {
  if defined('apt::source') {
    # Puppetlabs/apt module
	  # for Wheezy/Ubuntu 12
    #apt::ppa { 'ppa:skettler/php':
    #  notify => Exec['acquia::php::apt-get update']
    #}
	  # for Squeeze/Ubuntu 10
	  apt::ppa { 'ppa:fabianarias/php5':
		  notify => Exec['acquia::php::apt-get update']
	  }
  }

  exec { 'acquia::php::apt-get update':
    command     => 'apt-get update',
    path        => '/usr/bin',
    refreshonly => true,
  }
}
