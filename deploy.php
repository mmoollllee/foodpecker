<?php

namespace Deployer;

use Dotenv\Dotenv;

require 'vendor/mmoollllee/laravel-deployer/recipe/app.php';

/*
|--------------------------------------------------------------------------
| Server
|--------------------------------------------------------------------------
| The repository is public, so the server details live in the local .env
| (see the "Deployment" block in .env.example) instead of this file.
*/

if (class_exists(Dotenv::class)) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

$deployHost = $_ENV['DEPLOY_HOST'] ?? null;

if (is_string($deployHost) && trim($deployHost) !== '') {
    host($deployHost)
        ->set('remote_user', $_ENV['DEPLOY_USER'] ?? get('user'))
        ->set('deploy_path', $_ENV['DEPLOY_PATH'] ?? '~/'.$deployHost)
        ->set('remote_php', $_ENV['DEPLOY_PHP'] ?? '/opt/plesk/php/8.3/bin/php')
        ->set('git_ssh_key', $_ENV['DEPLOY_SSH_KEY'] ?? null);
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
