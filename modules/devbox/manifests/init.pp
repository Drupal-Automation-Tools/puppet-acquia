class devbox(
  $shell = 'zsh', # bash or zsh
) {
  include apt
  include stdlib

  $config = loadyaml('/vagrant/.tmp/config.yaml')

  class { 'motd':
    template => "${module_name}/motd.erb",
  }

  package { [ 'openssh-client', 'openssh-server' ]:
    ensure => 'latest',
  }

  service { 'ssh':
    ensure => 'running',
  }
  file { '/etc/ssh/sshd_config':
    source => 'puppet:///modules/devbox/etc/ssh/sshd_config',
    notify => Service['ssh'],
  }

  include sudo

  sudo::conf { 'env-ssh-auth-sock':
    priority => 10,
    content  => 'Defaults env_keep += SSH_AUTH_SOCK',
  }
  sudo::conf { 'sudo-vagrant':
    priority => 20,
    content => '%vagrant ALL=NOPASSWD:ALL',
  }

  file { '/etc/profile':
    source => 'puppet:///modules/devbox/etc/profile'
  }

  # make bash pretty
  include bashrc

  # but install oh-my-zsh
  class { 'ohmyzsh': }

  devbox::user { ['root', 'vagrant']: require => Class['ohmyzsh'] }
}

