<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\NullOutput;

$expectedDatabase = getenv('BEPARTNERS_INIT_DB');
if ($expectedDatabase === false || $expectedDatabase === '') {
    exit(0);
}

// Keep exception details and connection parameters out of the container log.
$failure = 'no se pudo verificar la conexión PostgreSQL';

try {
    $root = dirname(__DIR__);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $app['config']->set('logging.default', 'null');

    $connection = $app['db']->connection();
    if ($connection->getName() !== 'pgsql' || $connection->getDriverName() !== 'pgsql'
        || $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        throw new RuntimeException();
    }

    $connection->transaction(function () use ($app, $connection, $expectedDatabase, &$failure): void {
        $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $connection->statement("SET LOCAL lock_timeout = '5s'");
        $connection->statement("SET LOCAL statement_timeout = '30s'");

        $failure = 'la base o el esquema activo no coincide con el destino autorizado';
        $target = $connection->selectOne(
            'select pg_catalog.current_database() as name, pg_catalog.current_schema() as schema'
        );
        if ($target?->name !== $expectedDatabase || $target?->schema !== 'public') {
            throw new RuntimeException();
        }

        $failure = 'hay otra inicialización en curso o no se pudo obtener el bloqueo';
        $guard = $connection->selectOne('select pg_catalog.pg_try_advisory_xact_lock(129505, 301) as locked');
        if ($guard?->locked !== true) {
            throw new RuntimeException();
        }

        $failure = 'la base contiene objetos o no se pudo confirmar que está vacía';
        $state = $connection->selectOne(<<<'SQL'
            select (
                exists (
                    select 1 from pg_catalog.pg_namespace
                    where nspname not in ('public', 'pg_catalog', 'pg_toast', 'information_schema')
                      and nspname !~ '^pg_(toast_)?temp_[0-9]+$'
                )
                or exists (
                    select 1 from pg_catalog.pg_class as c
                    join pg_catalog.pg_namespace as n on n.oid = c.relnamespace
                    where n.nspname = 'public'
                )
                or exists (
                    select 1 from pg_catalog.pg_depend as d
                    join pg_catalog.pg_namespace as n on n.oid = d.refobjid
                    where d.refclassid = 'pg_catalog.pg_namespace'::pg_catalog.regclass
                      and n.nspname = 'public'
                )
                or exists (select 1 from pg_catalog.pg_extension where extname <> 'plpgsql')
                or exists (select 1 from pg_catalog.pg_largeobject_metadata)
                or exists (select 1 from pg_catalog.pg_event_trigger)
                or exists (select 1 from pg_catalog.pg_publication)
                or exists (
                    select subdbid from pg_catalog.pg_subscription
                    where subdbid = (
                        select oid from pg_catalog.pg_database where datname = pg_catalog.current_database()
                    )
                )
                or exists (select 1 from pg_catalog.pg_foreign_data_wrapper)
                or exists (select 1 from pg_catalog.pg_foreign_server)
            ) as occupied
            SQL);
        if ($state?->occupied !== false) {
            throw new RuntimeException();
        }

        $failure = 'falló el lote de migraciones';
        $migrator = $app->make('migrator');
        $migrator->setOutput(new NullOutput());
        $migrator->usingConnection('pgsql', function () use ($migrator, $app): void {
            $migrator->getRepository()->createRepository();
            $migrator->run([$app->databasePath('migrations')]);
        });

        $failure = 'falló la carga de roles';
        $app->make(RoleSeeder::class)->run();
        $failure = 'no se pudo confirmar la transacción';
    });
} catch (Throwable) {
    fwrite(STDERR, "BEPARTNERS INIT ERROR: {$failure}. Apache no arrancará.\n");
    exit(1);
}

fwrite(STDOUT, "BEPARTNERS INIT OK: migraciones y RoleSeeder confirmados.\n");
