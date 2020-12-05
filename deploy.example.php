<?php
namespace Deployer;

require 'recipe/laravel.php';
require 'recipe/antweb.php';

inventory('hosts.yml');

// Project name
//set('project_path', '{{release_path}}');

// Shared files/dirs between deploys 
set('shared_files', [
	'.env',
]);
set('shared_dirs', [
	'storage',
	'themes',
]);

// Writable dirs by web server 
//set('writable_dirs', []);

/*
desc('Deploy your project');
task('deploy', [
    'deploy-fusion-cms',
]);
*/