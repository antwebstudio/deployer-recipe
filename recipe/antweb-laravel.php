<?php
namespace Deployer;

function tinker($executePhp) {
	$executePhp = str_replace('"', '\\"', $executePhp);
	run('cd {{release_path}} && {{ bin/php }} artisan tinker --execute "'.$executePhp.'"');
}

task('deploy:version',  function() {
	$execute = "Storage::put('version', \Tremby\LaravelGitVersion\GitVersionHelper::getVersion())";
	run('cd {{release_path}} && {{bin/php}} artisan tinker --execute="'.$execute.'"');
});