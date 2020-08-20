<?php
namespace Deployer;

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', true); 

// [Optional] If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');

set('bin/yii', '{{bin/php}} yii');
set('default_timeout', 0);

set('bin/mysqldump', 'mysqldump');
set('bin/mysql', 'mysql');
set('bin/gunzip', 'gunzip');
set('bin/gzip', 'gzip');

task('ssh-add', function() {
	runLocally('eval $(ssh-agent -s) && ssh-add ~/.ssh/id_rsa');
});

task('info:packages', function() {
	run('cd {{project_path}} && {{bin/composer}} show antweb/*');
});

task('composer:dumpautoload', function() {
	run('cd {{project_path}} && {{bin/composer}} dumpautoload');
});

task('composer:install', function() {
	run('cd {{project_path}} && {{bin/composer}} install --no-dev');
});

task('composer:update', function() {
	run('cd {{project_path}} && {{bin/composer}} update --no-dev');
});

task('deploy:install', function () {
	run('cd {{project_path}} && {{bin/composer}} setup -- --name="{{name}}" --theme={{theme}} --db={{db}} --dbUser={{dbUser}} --dbPassword={{dbPassword}} --dbPrefix={{dbPrefix}} --baseUrl={{baseUrl}} --useTranslateManager={{useTranslateManager}}');
	
	run('cd {{release_path}} && {{bin/yii}} setup --interactive=0');
	
	//run("cd {{project_path}}/web && {{bin/symlink}} {{project_path}}/backend/web admin");
	//run("cd {{project_path}}/web && {{bin/symlink}} {{project_path}}/storage/web storage");
});

task('deploy:file_permission', function() {
	run('cd {{release_path}} && find ./ -type d -exec chmod 755 {} \;');
	run('cd {{release_path}} && find ./ -type f -exec chmod 644 {} \;');
});

task('db:download', function() {
	$hostname = Task\Context::get()->getHost()->getHostname();
	
	if (askConfirmation('Are you sure to overwrite local database using live database ?')) {
		set('liveFile', $hostname.'_{{application}}_'.date('Y-m-d-His').'.sql.gz');
		set('liveFilePath', '{{project_path}}/{{liveFile}}');
		set('localFilePath', './storage/backup');
		
		// Backup live db
		run('{{bin/mysqldump}} -u {{liveDbUser}} -p"{{dbPassword}}" {{db}} | {{bin/gzip}} > {{liveFilePath}}');
		
		//runLocally('cd {{localFilePath}}');
		
		//runLocally('wget {{baseUrl}}/{{liveFile}}');
		
		download('{{liveFilePath}}', '{{localFilePath}}');
		
		runLocally('{{bin/gunzip}} < {{localFilePath}}/{{liveFile}} | mysql -h 127.0.0.1 -D {{localhostDb}} -u root -p"root" --default-character-set=utf8');
		
		run('unlink {{liveFilePath}}');
	}
});

task('db:update_beta', function() {
	$hostname = Task\Context::get()->getHost()->getHostname();
	
	// ============
	// WARNING: 
	// ============
	// $hostname == 'beta' condition is very important, removing it may cause disaster.
	
	if ($hostname == 'beta' && askConfirmation('Are you sure to clone database from '.get('liveDb').' to '.get('db').' ?')) {
		set('betaFile', 'beta_'.date('Y-m-d-His').'.sql.gz');
		set('liveFile', 'v2_'.date('Y-m-d-His').'.sql.gz');
		
		//run('LIVE_FILE=v2_$(date +%Y%m%d_%H%M%S).sql.gz');
		//run('BETA_FILE=beta_$(date +%Y%m%d_%H%M%S).sql.gz');
		
		// Backup beta db
		run('{{bin/mysqldump}} -u {{dbUser}} -p"{{dbPassword}}" {{db}} | {{bin/gzip}} > {{betaFile}}');
		
		// Backup live db
		run('{{bin/mysqldump}} -u {{liveDbUser}} -p"{{liveDbPassword}}" {{liveDb}} | {{bin/gzip}} > {{liveFile}}');
		
		run('{{bin/mysql}} -u {{dbUser}} -p"{{dbPassword}}" {{db}} -e "DROP DATABASE {{db}}; CREATE DATABASE {{db}}"');
		
		// Restore live db to beta db
		run('{{bin/gunzip}} < {{liveFile}} | mysql -D {{db}} -u {{dbUser}} -p"{{dbPassword}}" --default-character-set=utf8');
	}
});

desc('Deploy your project');
task('deploy-larvael', [
	'deploy-common',
    'deploy:unlock',
    'cleanup',
    'success'
]);

desc('Deploy your project');
task('deploy-common', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'deploy:symlink',
]);

desc('Deploy your project');
task('deploy-fusion-cms', [
	'deploy-common',
	'fusion:symlink',
    'deploy:unlock',
    'cleanup',
    'success'
]);

task('fusion:symlink', function() {
	run('cd {{release_path}}/public/vendor && ln -s ../../vendor/fusioncms/cms/public fusion');
});