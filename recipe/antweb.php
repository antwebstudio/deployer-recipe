<?php
namespace Deployer;

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', true); 

// [Optional] If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');

// Backup db before deploy
before('deploy:prepare', 'db:backup');

set('bin/yii', '{{bin/php}} yii');
set('default_timeout', 0);

set('bin/mysqldump', 'mysqldump');
set('bin/mysql', 'mysql');
set('bin/gunzip', 'gunzip');
set('bin/gzip', 'gzip');
set('localDbFilePath', './storage/backup');
		
task('ssh-add', function() {
	runLocally('eval $(ssh-agent -s) && ssh-add ~/.ssh/id_rsa');
});

task('ssh-key-copy', function() {
	runLocally('ssh-copy-id -i ~/.ssh/id_rsa.pub {{user}}@{{hostname}} -p {{port}}');
});

task('ssh-local', function() {
	write(runLocally('eval $(ssh-agent -s) && ssh-add ~/.ssh/id_rsa && ssh-add -L'));
})->once();

task('ssh-key', function() {
	write(run('eval $(ssh-agent -s) && ssh-add ~/.ssh/id_rsa && ssh-add -L'));
});

task('ssh-generate', function() {
	run('ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa');
});

task('boot', function() {
	runLocally('cp ./vendor/antweb/deployer-recipe/hosts.example.yml hosts.yml');
});

task('info:packages', function() {
	run('cd {{current_path}} && {{bin/composer}} show antweb/*');
});

task('info:version', function() {
	$hostname = Task\Context::get()->getHost()->getHostname();
	
	write($hostname.': '.run('cd {{current_path}} && {{bin/git}} log --pretty="%H - %cd" -n 1')."\n");
});

task('composer:dumpautoload', function() {
	run('cd {{current_path}} && {{bin/composer}} dumpautoload');
});

task('composer:install', function() {
	run('cd {{current_path}} && {{bin/composer}} install --no-dev');
});

task('composer:update', function() {
	run('cd {{current_path}} && {{bin/composer}} update --no-dev');
});

task('deploy:install', function () {
	run('cd {{current_path}} && {{bin/composer}} setup -- --name="{{name}}" --theme={{theme}} --db={{db}} --dbUser={{dbUser}} --dbPassword={{dbPassword}} --dbPrefix={{dbPrefix}} --baseUrl={{baseUrl}} --useTranslateManager={{useTranslateManager}}');
	
	run('cd {{release_path}} && {{bin/yii}} setup --interactive=0');
	
	//run("cd {{current_path}}/web && {{bin/symlink}} {{current_path}}/backend/web admin");
	//run("cd {{current_path}}/web && {{bin/symlink}} {{current_path}}/storage/web storage");
});

task('deploy:file_permission', function() {
	run('cd {{release_path}} && find ./ -type d -exec chmod 755 {} \;');
	run('cd {{release_path}} && find ./ -type f -exec chmod 644 {} \;');
});

task('db:backup', function() {
	$hostname = Task\Context::get()->getHost()->getHostname();
	list($filename, $liveFilePath) = backupServerDb($hostname, '{{deploy_path}}/current');
	write('Backup: '.$liveFilePath);
});

task('db:download', function() {
	$hostname = Task\Context::get()->getHost()->getHostname();
	
	if (askConfirmation('Are you sure to overwrite local database using live database ?')) {
		list($filename, $liveFilePath) = backupServerDb($hostname);
		
		runLocally('mkdir -p {{localDbFilePath}}');
		
		if (canUseRsync()) {
			download($liveFilePath, '{{localDbFilePath}}/'.$filename);
		} else {
			runLocally('cd {{localDbFilePath}} && wget {{baseUrl}}/'.$filename);
		}
		
		setLocalDbHost();
		
		run('unlink '.$liveFilePath);
		
		runLocally('{{bin/gunzip}} < {{localDbFilePath}}/'.$filename.' | mysql -h {{localDbHost}} -D {{localhostDb}} -u root -p"root" --default-character-set=utf8');
		
		// $hostIp = runLocally('cat /etc/resolv.conf | grep nameserver | sed \'s/nameserver\s*//\'');
		// set('localDbHost', $hostIp);
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
		
		restoreDb('{{liveFile}}');
	}
});

task('db:restore_local', function() {
	$files = [];
	$path = get('localDbFilePath');
	
	if ($handle = opendir($path)) {
		while (false !== ($entry = readdir($handle))) {
			if ($entry != "." && $entry != ".." && (strpos($entry, '.sql') === strlen($entry) - 4 || strpos($entry, '.sql.gz') === strlen($entry) - 7)) {
				$files[] = $entry;
			}
		}
		closedir($handle);
	}
	
	$file = askChoice('Please choose a file (ctrl+z to cancel)', $files);

	restoreDb('{{localDbFilePath}}/'.$file, ['{{localhostDb}}', 'root', 'root'], true, true);
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
	'artisan:storage:link',
    'deploy:unlock',
    'cleanup',
    'success'
]);

task('fusion:symlink', function() {
	run('cd {{release_path}}/public/vendor && ln -s ../../vendor/fusioncms/cms/public fusion');
});

function canUseRsync() {
	return test('[ -x "$(command -v rsync)" ]');
}

function backupServerDb($hostname, $backupPath = '{{current_path}}') {
	$filename = $hostname.'_{{application}}_'.date('Y-m-d-His').'.sql.gz';
	$liveFilePath = $backupPath.'/'.$filename;
	
	if (!canUseRsync()) {
		if ( test('[ -d '.$backupPath.'/public ]') ) {
			$liveFilePath = $backupPath.'/public/'.$filename;
		} else {
			$liveFilePath = $backupPath.'/web/'.$filename;
		}
	}
	
	// Backup live db
	run('if [ -f _dump.sql ]; then unlink _dump.sql; fi');
	run('if [ -f _dump.sql.gz ]; then unlink _dump.sql.gz; fi');
	run('{{bin/mysqldump}} --no-tablespaces  -u {{dbUser}} -p"{{dbPassword}}" {{db}} > _dump.sql && {{bin/gzip}} _dump.sql && mv _dump.sql.gz '.$liveFilePath);

	return [$filename, $liveFilePath];
}

function setLocalDbHost() {
	$hostIp = runLocally('cat /etc/resolv.conf | grep nameserver | sed \'s/nameserver\s*//\'');
	set('localDbHost', $hostIp);
}

function restoreDb($file, $db = ['{{db}}', '{{dbUser}}', '{{dbPassword}}'], $useGunzip = true, $runLocally = false) {
	
	setLocalDbHost();
		
	$host = '-h {{localDbHost}}';
	$command2prefix = $useGunzip ? '{{bin/gunzip}} < '.$file.' | ' : '';
	$command2suffix = $useGunzip ? '' : ' < '.$file;
	
	$command1 = '{{bin/mysql}} '.$host.' -u '.$db[1].' -p"'.$db[2].'" -e "DROP DATABASE IF EXISTS \`'.$db[0].'\`; CREATE DATABASE \`'.$db[0].'\`"';
	$command2 = 'mysql '.$host.' -D '.$db[0].' -u '.$db[1].' -p"'.$db[2].'" --default-character-set=utf8';
	$command2 = $command2prefix . $command2 . $command2suffix;
	
	if ($runLocally) {
		runLocally($command1);
		runLocally($command2);
	} else {
		run($command1);
		run($command2);
	}
}

function getAllHosts() {	
	$deployer = Deployer::get();
	return array_keys($deployer->hosts->toArray());
}

function getConsole() {
	$deployer = Deployer::get();
	return $deployer->getConsole();
}

function getQuestionHelper() {
	$deployer = Deployer::get();
	return $deployer->getHelper('question');
}

function getHostConfig($host, $name) {
	$deployer = Deployer::get();
	$host = is_object($host) ? $host : $deployer->hosts->get($host);

	if (isset($name)) {
		return $host->get($name);
	} else {
		return $host->config();
	}
}