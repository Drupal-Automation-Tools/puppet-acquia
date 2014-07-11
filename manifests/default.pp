node default {
  include apt

	if ($osfamily == 'Debian' and $lsbmajdistrelease == 10) {
		apt::ppa { 'ppa:git-core/ppa': }
	}

	include stdlib
	include git

  package { ['nfs-common', 'vim' ]:
    ensure => 'latest',
  }

  class { 'devbox':

  }

  class { 'acquia':
  }

  class { 'jjbos':
    require => Class['devbox'],
  }

  Class['devbox']->Class['acquia']->Class['jjbos']

}
