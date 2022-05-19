<?php
namespace Deployer;

$path = __DIR__.'/vendor/antweb/deployer-recipe/';

require 'recipe/laravel.php';
require $path.'recipe/antweb.php';
// require $path.'recipe/fusioncms.php';

import('hosts.yml');

// Project name
//set('project_path', '{{release_path}}');

// Shared files/dirs between deploys 
set('shared_files', [
	'.env',
]);
set('shared_dirs', [
	'storage',
	// 'themes',
]);

// Writable dirs by web server 
set('writable_dirs', []);

/*
desc('Deploy your project');
task('deploy', [
    'deploy-fusion-cms',
]);
*/

before('deploy:symlink', 'artisan:migrate');