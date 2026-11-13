<?php

/*
| PostgreSQL only. With PDO's server-side prepares every query costs an extra
| Parse/Describe round trip (and an unnamed-statement dance); disabling them
| makes pdo_pgsql send each query with PQexecParams: one round trip, values
| still sent out-of-band as parameters (no client-side interpolation).
*/
$pgsqlOptions = [];
if (extension_loaded('pdo_pgsql')) {
    $disablePrepares = defined('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
        ? constant('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
        : constant('PDO::PGSQL_ATTR_DISABLE_PREPARES');
    $pgsqlOptions[$disablePrepares] = true;
}

$pgsql = [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'wallet'),
    'username' => env('DB_USERNAME', 'wallet'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'disable'),
    'options' => $pgsqlOptions,
];

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => $pgsql,

        // The database the running API server writes to. Only the
        // live-server concurrency tests use it (to reconcile the ledger after
        // hammering the HTTP API); in-process feature tests use `pgsql`,
        // which phpunit.xml points at a separate wallet_test database.
        'pgsql_live' => array_merge($pgsql, [
            'database' => env('DB_LIVE_DATABASE', 'wallet'),
        ]),

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [],

];
