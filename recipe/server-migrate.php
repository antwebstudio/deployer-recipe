<?php
namespace Deployer;

task('server-migrate:cache', function() use($migrateConfig) {
	$newServerPath = $migrateConfig['newServerPath'];

	on(host('new'), function($host) use($newServerPath) {
		within($newServerPath.'/current', function() {
			run('if [ -f yii ]; then {{bin/php}} yii cache/flush-all; fi');
			run('if [ -f artisan ]; then {{bin/php}} artisan cache:clear; fi');
		});
	});
})->local();

task('server-migrate:chmod', function() use($migrateConfig) {
	$path = $migrateConfig['newServerPath'];


	if (test('[ -d '.$path.'releases ]')) {
		autoChmod($path.'/releases');
	} else {
		autoChmod($path);
	}
})->onHosts('new');

task('server-migrate:ls', function() use($migrateConfig) {
	$path = $migrateConfig['newServerPath'];
	within($path, function() use($path) {
		writeln(run('ls -l'));
	});
})->onHosts('new');

task('server-migrate:symlink', function() use($migrateConfig) {
	$path = $migrateConfig['newServerPath'];
	$symlinks = $migrateConfig['symlinks'] ?? [];

	on(host('new'), function($host) use($path, $symlinks) {
		within($path, function() use($symlinks) {
			foreach($symlinks as $from => $to) {
				run('if [ -L '.$from.' ]; then unlink '.$from.'; fi');
				run('if [ ! -L '.$from.' ]; then ln -s '.$to.' '.$from.'; fi');
			}
		});
	});

})->local();

task('server-migrate', [
	'server-migrate:file',
	'server-migrate:symlink',
	'server-migrate:db',
	'server-migrate:cache',
	'server-migrate:chmod',
]);

task('server-migrate:chown', function() use($migrateConfig) {
	$username = get('become');
	$path = $migrateConfig['newServerPath'];

	run('chown -R '.$username.':'.$username.' '.$path);
})->onHosts('new');

task('server-migrate:file', function() use($migrateConfig) {
	$copyToPath = get('downloadUsePath');
	$path = $migrateConfig['oldServerPath'];
	$newServerPath = $migrateConfig['newServerPath'];
	$filename = null;
	
	on(host('old'), function($host) use(&$filename, $path, $copyToPath) {
		within($path, function() use(&$filename, $path, $copyToPath) {
			$filename = zip('*');
			
			run('mv '.$filename.'.tar.gz '.$copyToPath);
		});
	});

	on(host('new'), function($host) use($filename, $newServerPath) {
		within($newServerPath, function() use($filename) {
			run('if [ -d ./public_html ]; then \rm -r ./public_html; fi');

			$url = str_replace('{filename}', $filename.'.tar.gz', get('downloadUrl'));
	
			downloadToServer($url);
	
			unzip($filename);
		});
	});

	on(host('old'), function($host) use($filename, $copyToPath) {
		run('unlink '.$copyToPath.'/'.$filename.'.tar.gz');
	});

})->local();

task('server-migrate:db', function() use($migrateConfig){
	$filename = null;
	$copyToPath = get('downloadUsePath');
	$newServerPath = $migrateConfig['newServerPath'];

	on(host('old'), function($host) use(&$filename, $copyToPath) {
		$oldDb = ask('Source DB: ');
		$oldDbUser = ask('Source DB username: ', $oldDb);
		$oldDbPassword = ask('Source DB password: ');

		$filename = dumpDb($oldDb, $oldDbUser, $oldDbPassword);
		
		run('mv '.$filename.' '.$copyToPath);
	});
	
	on(host('new'), function($host) use($filename, $newServerPath) {
		within($newServerPath, function() use($filename) {
			$url = str_replace('{filename}', $filename, get('downloadUrl'));
			
			$newDb = ask('Destination DB: ');
			$newDbUser = ask('Destination DB username: ', $newDb);
			$newDbPassword = ask('Destination DB password: ');

			downloadToServer($url);

			restoreDb($newDb, $newDbUser, $newDbPassword, $filename);

			run('unlink '.$filename);
		});
	});

	on(host('old'), function($host) use($filename, $copyToPath) {
		run('unlink '.$copyToPath.'/'.$filename);
	});

	// $canUseRsync = testIfCanUseRsync();

	// if ($canUseRsync) {

	// } else {
	// }

})->local();

function zip($path, $filename = null) {
	$filename = $filename ?? '_'.basename($path).'_'.date('Y-m-d_H-i-s');
	run('tar -czvf '.$filename.'.tar.gz '.$path);
	return $filename;
}

function unzip($filename) {
	run('tar -xzvf '.$filename.'.tar.gz');
}

function autoChmod($path) {
	within($path, function() {
		run('find ./ -type d -exec chmod 755 {} \;');
		run('find ./ -type f -exec chmod 644 {} \;');
	});
}

function dumpDb($db, $username, $password) {
	$dumpName = '_db_dump_'.$db.'_'.date('Y-m-d_H-i-s');
	$filename = $dumpName.'.sql.gz';
	
	run('{{bin/mysqldump}} --no-tablespaces -u '.$username.' -p"'.$password.'" '.$db.' > '.$dumpName.'.sql && {{bin/gzip}} '.$dumpName.'.sql');

	return $filename;
}

function restoreDb($db, $username, $password, $filename) {
	$host = '';
	$useGunzip = true;
	// $host = '-h localhost';
	$command2prefix = $useGunzip ? '{{bin/gunzip}} < '.$filename.' | ' : '';
	$command2suffix = $useGunzip ? '' : ' < '.$filename;
	
	$command1 = '{{bin/mysql}} '.$host.' -u '.$username.' -p"'.$password.'" -e "DROP DATABASE IF EXISTS \`'.$db.'\`; CREATE DATABASE \`'.$db.'\`"';
	$command2 = 'mysql '.$host.' -D '.$db.' -u '.$username.' -p"'.$password.'" --default-character-set=utf8';
	$command2 = $command2prefix . $command2 . $command2suffix;

	run($command2);
}

function downloadToServer($url) {
	run('wget '.$url);
}