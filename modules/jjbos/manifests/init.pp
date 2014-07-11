class jjbos {
  include known_hosts

  vcsrepo { '/var/www/html/jjbos':
    ensure   => present,
    provider => git,
    source   => 'git@github.com:acquia-pso/JandJ.git',
    revision => 'release',
    require => Known_hosts::Ensure['github.com'],
  }

  # Ensure that github.com is in /root/.ssh/known_hosts before attempting to connect with git.
  known_hosts::ensure { 'github.com':
    user => 'root',
    before => Vcsrepo['/var/www/html/jjbos'],
  }

	file { '/var/www/site-scripts':
		ensure => directory,
		source => 'puppet:///modules/jjbos/var/www/site-scripts',
		recurse => true,
	}
	file { '/var/www/site-php':
		ensure => directory,
		source => 'puppet:///modules/jjbos/var/www/site-php',
		recurse => true,
	}

	# Creating a symlink for "jnjbos", as there is a naming discrepancy within the code pointing to the same.
	file { '/var/www/site-php/jnjbos':
		ensure => link,
		target => '/var/www/site-php/jjbos',
		require => File['/var/www/site-php']
	}
	file { '/var/www/site-php/jjbos/jnjbos-settings.inc':
		ensure => link,
		target => '/var/www/site-php/jjbos/jjbos-settings.inc',
		require => File['/var/www/site-php'],
	}
	file { '/var/www/site-php/jjbos/D7-vagrant-jnjbos-settings.inc':
		ensure => link,
		target => '/var/www/site-php/jjbos/D7-vagrant-jjbos-settings.inc',
		require => File['/var/www/site-php'],
	}

	apache::vhost::fastcgi_php { "www-data.${::fqdn}":
		docroot         => "/var/www/html/jjbos/docroot",
		port			=> 8080,
		serveraliases   => [ 'localhost', 'jnj-devbox.local', "site1.jnj-devbox.local", "site2.jnj-devbox.local" ],
		fastcgi_dir     => '/var/www',
		template        => "jjbos/apache2/jjbos-vhost-fastcgi-php.conf.erb",
		socket          => "/var/run/php-fpm.sock",
		allow_override  => 'All',
		require         => File['/var/www/site-php'],
	}

#	include composer
#	composer::project { 'jjbos-platform':
#		project_name   => 'jjconsumer/jjbos-platform',
#		target_dir     => '/vagrant/assembler',
#		version        => '0.1.x-dev',
#		prefer_source  => true,
#		stability      => 'dev', # Minimum stability setting
#		keep_vcs       => true, # Keep the VCS information
#		dev            => true, # Install dev dependencies
#		repository_url => 'https://raw.githubusercontent.com/reubenavery/jjbos-platform/master', # Custom repository URL
#		user           => undef, # Set the user to run as
#	}

}
