<?php
namespace Deployer;

class ServerMigrate {
	public static $sourceHost = null;
	public static $destinationHost = null;

	public static function askHosts() {
		if (!isset(self::$sourceHost)) {
			self::$sourceHost = askChoice('Select source host: ', getAllHosts());
		}
		if (!isset(self::$destinationHost)) {
			$hosts = [];
			// Destination host cannot be same as source host.
			foreach (getAllHosts() as $host) {
				if ($host != self::$sourceHost) {
					$hosts[] = $host;
				}
			}
			self::$destinationHost = askChoice('Select destination host: ', $hosts);
		}
		return [self::$sourceHost, self::$destinationHost];
	}

	public static function checkDownloadUsePath($host) {
		$deployPath = getHostConfig($host, 'deploy_path');
		// $copyToPath = get('downloadUsePath');
		// if (!$copyToPath) throw new \Exception('Variable {{downloadUsePath}} is not set. ');

		$copyToPath = $deployPath.'/current/public';

		return $copyToPath;
	}

	public static function checkDownloadUrl($host) {
		$baseUrl = getHostConfig($host, 'baseUrl');
		if (!$baseUrl) throw new \Exception('Variable {{baseUrl}} for host "'.$host.'" is not set. ');

		return $baseUrl;
	}

	public static function copyFileAcrossHost($sourceHost, $sourcePath, $destinationHost, $destinationPath) {
		$filename = null;
		$copyToPath = self::checkDownloadUsePath($sourceHost);
		$downloadUrl = self::checkDownloadUrl($sourceHost);
		
		self::zipAllFile($sourceHost, $sourcePath, $copyToPath);

		on(host($destinationHost), function($host) use($filename, $destinationPath, $downloadUrl) {
			within($destinationPath, function() use($filename, $downloadUrl) {
				// run('if [ -d ./public_html ]; then \rm -r ./public_html; fi');

				if (strpos($downloadUrl, '{filename}') !== false) {
					$url = str_replace('{filename}', $filename.'.tar.gz', $downloadUrl);
				} else {
					$url = $downloadUrl.'/'.$filename.'.tar.gz';
				}
		
				downloadToServer($url);
		
				unzip($filename);
			});
		});

		on(host($sourceHost), function($host) use($filename, $copyToPath) {
			run('unlink '.$copyToPath.'/'.$filename.'.tar.gz');
		});
	}

	public static function zipAllFile($sourceHost, $sourcePath, $copyToPath = null) {
		on(host($sourceHost), function($host) use(&$filename, $sourcePath, $copyToPath) {
			within($sourcePath, function() use(&$filename, $copyToPath) {
				$filename = zip('*');
				
				if (isset($copyToPath)) {
					run('mv '.$filename.'.tar.gz '.$copyToPath);
				}
			});
		});
	}
}

task('server-migrate:ask', function() {	
	ServerMigrate::askHosts();
});

set('downloadUsePath', '');

task('server-migrate:zip-file', function() {
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();
	$path = getHostConfig($sourceHost, 'deploy_path');

	writeln('File will be temporarily store in: ['.$sourceHost.']'. $path);

	ServerMigrate::zipAllFile($sourceHost, $path);
})->local();

task('server-migrate:cache', function() {
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();
	$newServerPath = getHostConfig($destinationHost, 'deploy_path');

	on(host($destinationHost), function($host) use($newServerPath) {
		within($newServerPath.'/current', function() {
			run('if [ -f yii ]; then {{bin/php}} yii cache/flush-all; fi');
			run('if [ -f artisan ]; then {{bin/php}} artisan cache:clear; fi');
		});
	});
})->local();

task('server-migrate:chmod', function() {
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();

	on(host($destinationHost), function($destinationHost) {
		$path = getHostConfig($destinationHost, 'deploy_path');

		if (test('[ -d '.$path.'releases ]')) {
			autoChmod($path.'/releases');
		} else {
			autoChmod($path);
		}
	});
})->local();

task('server-migrate:ls', function() {
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();
	
	on(host($destinationHost), function($destinationHost) {
		$path = getHostConfig($destinationHost, 'deploy_path');
		
		within($path, function() use($path) {
			writeln(run('ls -l'));
		});
	});
})->local();

task('server-migrate:symlink', function() {
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();

	$path = getHostConfig($destinationHost, 'deploy_path');

	// $symlinks = $migrateConfig['symlinks'] ?? [];
	$symlinks = [];

	on(host($destinationHost), function($host) use($path, $symlinks) {
		within($path, function() use($symlinks) {
			foreach($symlinks as $from => $to) {
				run('if [ -L '.$from.' ]; then unlink '.$from.'; fi');
				run('if [ ! -L '.$from.' ]; then ln -s '.$to.' '.$from.'; fi');
			}
		});
	});

})->local();

task('server-migrate', [
	'server-migrate:ask',
	'server-migrate:file',
	'server-migrate:symlink',
	'server-migrate:db',
	'server-migrate:cache',
	'server-migrate:chmod',
])->once();

task('server-migrate:chown', function() {
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();

	on(host($destinationHost), function($destinationHost) {
		$username = get('become');
		$path = getHostConfig($destinationHost, 'deploy_path');

		run('chown -R '.$username.':'.$username.' '.$path);
	});
})->local();

task('server-migrate:file', function() {
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();
	
	$path = getHostConfig($sourceHost, 'deploy_path');
	$newServerPath = getHostConfig($destinationHost, 'deploy_path');

	writeln('Copy file from: ['.$sourceHost.']'. $path);
	writeln('Copy file to: ['.$destinationHost.']'. $newServerPath);
	// writeln('File will be temporarily store in: ['.$sourceHost.']'. $copyToPath);
	
	if (!askConfirmation('Are you sure to migrate server files from "'.$sourceHost.'" to "'.$destinationHost.'"?')) return;

	ServerMigrate::copyFileAcrossHost($sourceHost, $path, $destinationHost, $newServerPath);
})->local();

task('server-migrate:shared', function() {
	
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();
	$copyToPath = ServerMigrate::checkDownloadUsePath($sourceHost);
	ServerMigrate::checkDownloadUrl($sourceHost);

	$path = getHostConfig($sourceHost, 'deploy_path');
	$newServerPath = getHostConfig($destinationHost, 'deploy_path');

	writeln('Copy file from: ['.$sourceHost.']'. $path);
	writeln('Copy file to: ['.$destinationHost.']'. $newServerPath);
	writeln('File will be temporarily store in: ['.$sourceHost.']'. $copyToPath);
	
	if (!askConfirmation('Are you sure to migrate server shared files from "'.$sourceHost.'" to "'.$destinationHost.'"?')) return;

	ServerMigrate::copyFileAcrossHost($sourceHost, $path.'/shared', $destinationHost, $newServerPath.'/shared');
})->local();

task('server-migrate:db', function() {
	list($sourceHost, $destinationHost) = ServerMigrate::askHosts();

	if (!askConfirmation('Are you sure to migrate database from "'.$sourceHost.'" to "'.$destinationHost.'"?')) return;

	$filename = null;
	$path = getHostConfig($sourceHost, 'deploy_path');
	// $fileSourcePath = get('downloadUsePath');
	$fileSourcePath = $path;
	$newServerPath = getHostConfig($destinationHost, 'deploy_path');

	on(host($sourceHost), function($sourceHost) use(&$filename, $fileSourcePath) {
		within($fileSourcePath, function() use(&$filename, $fileSourcePath, $sourceHost) {
			$oldDb = ask('['.$sourceHost.'] Source DB: ', getHostConfig($sourceHost, 'db'));
			$oldDbUser = ask('['.$sourceHost.'] Source DB username: ', getHostConfig($sourceHost, 'dbUser'));
			$oldDbPassword = ask('['.$sourceHost.'] Source DB password: ', getHostConfig($sourceHost, 'dbPassword'));

			$filename = dumpDb($oldDb, $oldDbUser, $oldDbPassword);

			downloadWithScp($fileSourcePath.'/'.$filename, $filename);
			
			// run('mv '.$filename.' '.$fileSourcePath);
		});
	});
	
	on(host($destinationHost), function($destinationHost) use($filename, $newServerPath) {
		within($newServerPath, function() use($filename, $newServerPath, $destinationHost) {
			$newDb = ask('['.$destinationHost.'] Destination DB: ', getHostConfig($destinationHost, 'db'));
			$newDbUser = ask('['.$destinationHost.'] Destination DB username: ', getHostConfig($destinationHost, 'dbUser'));
			$newDbPassword = ask('['.$destinationHost.'] Destination DB password: ', getHostConfig($destinationHost, 'dbPassword'));
			
			// $url = str_replace('{filename}', $filename, get('downloadUrl'));
			// downloadToServer($url);

			//downloadFromHost($sourceHost, getHostConfig($sourceHost, 'deploy_path').'/'.$filename, getHostConfig($destinationHost, 'deploy_path'));
			uploadWithScp($filename, $newServerPath.'/'.$filename);

			restoreDb2($newDb, $newDbUser, $newDbPassword, $filename);

			run('unlink '.$filename);
		});
	});

	on(host($sourceHost), function($sourceHost) use($filename, $fileSourcePath) {
		if (isset($fileSourcePath) && trim($fileSourcePath) != '') {
			run('unlink '.$fileSourcePath.'/'.$filename);
		} else {
			run('unlink '.$filename);
		}
	});

	runLocally('unlink '.$filename);

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

function restoreDb2($db, $username, $password, $filename) {
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

/*function rsyncAcrossHost($sourceHost, string $source, $destinationHost, string $destination, array $config = []): void
{
	$sourceHost = host($sourceHost);
	$destinationHost = host($destinationHost);

    $rsync = Deployer::get()->rsync;
    $source = parse($source);
    $destination = parse($destination);

    // if ($host instanceof Localhost) {
        // $rsync->call($host, $source, $destination, $config);
    // } else {
        $rsync->call($sourceHost, "{$sourceHost->getConnectionString()}:$source", "{$destinationHost->getConnectionString()}:$destination", $config);
    // }
}*/

function rsyncFromHost($sourceHost, string $source, string $destination, array $config = [])
{
    $rsync = Deployer::get()->rsync;
	$sourceHost = is_object($sourceHost) ? $sourceHost : host($sourceHost);
	// $destinationHost = host($destinationHost);
	$destinationHost = \Deployer\Task\Context::get()->getHost();

	// $mainHost = $sourceHost;
    // $host = Context::get()->getHost();
    $source = parse($source);
    $destination = parse($destination);

    // if ($host instanceof Localhost) {
    //     $rsync->call($host->getHostname(), $source, $destination, $config);
    // } else {
        if (!isset($config['options']) || !is_array($config['options'])) {
            $config['options'] = [];
        }

        $sshArguments = $sourceHost->getSshArguments()->getCliArguments();
        if (empty($sshArguments) === false) {
            $config['options'][] = "-e 'ssh $sshArguments'";
        }

        // if ($sourceHost->has("become")) {
        //     $config['options'][]  = "--rsync-path='sudo -H -u " . $sourceHost->get('become') . " rsync'";
        // }
        $rsync->call($destinationHost->getHostname(), "$sourceHost:$source", $destination, $config);
    // }
}

function downloadFromHost($sourceHost, string $source, string $destination, array $config = []) {
	$sourceHost = is_object($sourceHost) ? $sourceHost : host($sourceHost);
	run('scp -P '.$sourceHost->get('port').' '.$sourceHost.':'.$source.' '.$destination);
}

function downloadWithScp($source, $destination, $config = []) {
	// $sourceHost = host($sourceHost);
	$sourceHost = \Deployer\Task\Context::get()->getHost();
	runLocally('scp -P '.$sourceHost->get('port').' '.$sourceHost.':'.$source.' '.$destination);
}

function uploadWithScp($source, $destination, $config = []) {
	// $destinationHost = host($destinationHost);
	$destinationHost = \Deployer\Task\Context::get()->getHost();
	runLocally('scp -P '.$destinationHost->get('port').' '.$source.' '.$destinationHost.':'.$destination);
}

