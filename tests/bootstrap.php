<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/*
 * Test database: created, migrated and filled with the demo fixtures once per PHPUnit run, so the access-control
 * tests run against the same accounts as the demo (TESTING.md). The test env uses its own database
 * ("<name>_test", config/packages/doctrine.yaml) and its own upload folder (config/services.yaml), so the dev data
 * is never touched. Set SKIP_TEST_DB_SETUP=1 to reuse the existing test database.
 */
if (!getenv('SKIP_TEST_DB_SETUP')) {
    $console = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/console');
    foreach ([
        'doctrine:database:create --if-not-exists',
        'doctrine:migrations:migrate --no-interaction --allow-no-migration',
        'doctrine:fixtures:load --no-interaction',
        'cache:pool:clear cache.rate_limiter',
    ] as $command) {
        exec($console.' '.$command.' --env=test 2>&1', $output, $code);
        if (0 !== $code) {
            fwrite(STDERR, "Test database setup failed: {$command}\n".implode("\n", $output)."\n");
            exit(1);
        }
        $output = [];
    }
}
