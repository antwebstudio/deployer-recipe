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

task('fusion:restore-schema', function() {
    if (get('restoreSchema')) {
        within('{{release_path}}', function() use(&$installed) {
            $restoreSchemaLockFile = '{{deploy_path}}/shared/fusion_schema_restored.lock';
            if (!serverFileExist($restoreSchemaLockFile)) {
                run('{{ bin/php }} artisan fusion:restore-schema --file={{restoreSchema}}');
                run('touch '.$restoreSchemaLockFile);
            }
        });
    }
});

task('fusion:install', function() {
    if (!checkFusionInstallation()) {
        run('cd {{release_path}} && {{ bin/php }} artisan fusion:install --silent --host=localhost --database={{db}} --username={{dbUser}} --password={{dbPassword}} --charset=utf8mb4 --collation=utf8mb4_general_ci --production');
    }
});

task('fusion:activate-theme', function() {
    if (has('theme')) {
        if (test('[ -d {{release_path}}/themes/{{theme}} ]')) {
            run('cd {{release_path}}/themes/{{theme}} && git pull');
        } else {
            run('cd {{release_path}}/themes && git clone {{theme_repo}} {{theme}}');
        }
        run('cd {{release_path}} && {{ bin/php }} artisan tinker --execute "setting([\'system.theme\' => \'{{ theme }}\'])"');
        run('cd {{release_path}} && {{ bin/php }} artisan fusion:flush');
        run('unlink {{release_path}}/public/theme');
    }
});

task('deploy:install', [
    'fusion:install',
    'fusion:no-update',
    'fusion:restore-schema',
    'artisan:migrate',
    'artisan:db:seed',
    'fusion:sync',
    'fusion:activate-theme',
]);

function checkFusionInstallation()
{
	$installed = null;
	within('{{release_path}}', function() use(&$installed) {
		$installed = serverFileNotEmpty('{{deploy_path}}/shared/.env');
		if ($installed) {
			writeln('installed');
		} else {
			writeln('not yet installed');
		}
	});
	return $installed;
}