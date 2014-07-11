# A stripped down provision for the base boxes
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
		motd => false,
	}
}
