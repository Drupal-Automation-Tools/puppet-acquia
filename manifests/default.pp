node default {
  include apt
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
