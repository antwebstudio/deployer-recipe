<?php
namespace Deployer;

task('fusion:deploy', [
    // 'artisan:fusion:sync',
	'fusion:sync',
	'fusion:sync-models',
	// 'artisan:storage:link',
]);

task('fusion:addon:discover', function() {
    run('cd {{release_path}} && {{bin/php}} artisan addon:discover');
});

task('fusion:sync', function() {
    run('cd {{release_path}} && {{bin/php}} artisan fusion:sync');
});

task('fusion:sync-models', function() {
    run('cd {{release_path}} && {{bin/php}} artisan fusion:sync-models');
});