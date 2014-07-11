define known_hosts::ensure(
  $user = 'root',
  $group = $username,
) {
  file{ '/root/.ssh' :
    ensure => directory,
    group => $group,
    owner => $username,
    mode => 0600,
  }

  file{ '/root/.ssh/known_hosts' :
    ensure => file,
    group => $group,
    owner => $username,
    mode => 0600,
    require => File[ '/root/.ssh' ],
  }

  file{ '/tmp/known_hosts.sh' :
    ensure => present,
    mode => '0700',
    source => 'puppet:///modules/known_hosts/known_hosts.sh',
  }

  exec{ 'add_known_hosts' :
    command => "/tmp/known_hosts.sh $name",
    path => "/sbin:/usr/bin:/usr/local/bin/:/bin/",
    user => 'root',
    require => File[ '/root/.ssh/known_hosts', '/tmp/known_hosts.sh' ],
    unless => "ssh-keygen -H -F $name | grep -q 'Host $name found'",
    loglevel => notice,
  }
}

