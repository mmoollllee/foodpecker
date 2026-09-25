<?php

namespace Deployer;

require 'vendor/mmoollllee/laravel-deployer/recipe/app.php';

/*
|--------------------------------------------------------------------------
| Server
|--------------------------------------------------------------------------
| The repository is public, so the server details live in the local .env
| (see the "Deployment" block in .env.example) instead of this file.
|
| The DEPLOY_* keys are parsed here instead of through Dotenv: a globally
| installed `dep` boots with its own autoloader, which has no
| vlucas/phpdotenv, so the host silently stayed undefined and every task
| failed with "No host configured".
*/

$deployEnv = [];

if (is_readable(__DIR__.'/.env')) {
    foreach (file(__DIR__.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*(DEPLOY_[A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $match)) {
            $value = trim($match[2], '"\'');

            if ($value !== '') {
                $deployEnv[$match[1]] = $value;
            }
        }
    }
}

$deployHost = $deployEnv['DEPLOY_HOST'] ?? null;

if ($deployHost !== null) {
    host($deployHost)
        ->set('remote_user', $deployEnv['DEPLOY_USER'] ?? get('user'))
        ->set('deploy_path', $deployEnv['DEPLOY_PATH'] ?? '~/'.$deployHost)
        ->set('remote_php', $deployEnv['DEPLOY_PHP'] ?? '/opt/plesk/php/8.3/bin/php')
        ->set('git_ssh_key', $deployEnv['DEPLOY_SSH_KEY'] ?? null);
}

// Product images uploaded on the server, synced by `dep pull:files` / `dep push:files`.
set('files', [
    'storage/app/public/',
]);

desc('Publish code on the remote');
task('deploy', function () {
    deploy_standard();

    cd('{{deploy_path}}');
    run('{{bin/php}} artisan storage:link --force');
});
