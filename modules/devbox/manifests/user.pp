define devbox::user {
	if $name == 'root' { $home = '/root' } else { $home = "/home/${name}" }
  case $devbox::shell {
    'bash': {
      user { "change-$name-to-bash" :
        name => $name,
        shell   => '/bin/bash',
      }
    }
    'zsh': {
	    ohmyzsh::install { $name: }
	    ohmyzsh::theme { $name: theme => 'vagranter', require => File["vagranter-$name"] }
	    ohmyzsh::plugins { $name: plugins => 'git github' }
#      ohmyzsh::upgrade { $name: }
	    file { "vagranter-$name":
	      name => "$home/.oh-my-zsh/themes/vagranter.zsh-theme",
		    source => 'puppet:///modules/devbox/oh-my-zsh/themes/vagranter.zsh-theme',
		    require => Exec["ohmyzsh::git clone ${name}"],
	    }
    }
  }
}
