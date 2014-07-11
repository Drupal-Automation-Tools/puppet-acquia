class acquia::ubuntu (
  $owner ='vagrant', 
  $group = 'vagrant', 
  $shared_folder='/vagrant'
) {
	# resources
	if $owner == 'root' {
		$uhome = "/${owner}"
	}
	else {
		$uhome = "/home/${owner}"
	}
	file {"${uhome}/.bashrc":
		ensure  => present,
		owner   => $owner,
		group   => $group,
		mode    => '0644',
		# content or source or target
		content => template('acquia/bashrc.erb'),
	}
}