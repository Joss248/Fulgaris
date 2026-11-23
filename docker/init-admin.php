<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\Rules\Password;

$expectedDatabase = getenv('BEPARTNERS_INIT_ADMIN_DB');
if ($expectedDatabase === false || $expectedDatabase === '') {
    exit(0);
}

$failure = 'faltan variables temporales del administrador';

try {
    $name = trim((string) getenv('BEPARTNERS_INIT_ADMIN_NAME'));
    $email = trim((string) getenv('BEPARTNERS_INIT_ADMIN_EMAIL'));
    $password = (string) getenv('BEPARTNERS_INIT_ADMIN_PASSWORD');
    if ($name === '' || $email === '' || $password === '') {
        throw new RuntimeException();
    }

    $failure = 'no se pudo verificar la conexión PostgreSQL';
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

    $attributes = ['name' => $name, 'email' => $email, 'password' => $password];
    $created = $connection->transaction(function () use ($app, $connection, $expectedDatabase, $attributes, &$failure): bool {
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

        $failure = 'faltan las tablas users o roles, o no se pudieron verificar';
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable('public.users') || ! $schema->hasTable('public.roles')) {
            throw new RuntimeException();
        }

        // Serialize bootstrap attempts and block concurrent application writes to users.
        $failure = 'no se pudo bloquear la tabla users';
        $connection->statement('LOCK TABLE public.users IN SHARE ROW EXCLUSIVE MODE');

        $failure = 'no se pudo verificar si existen usuarios';
        if (User::query()->exists()) {
            return false;
        }

        $failure = 'no se encontró el rol admin';
        $role = Role::query()->where('name', 'admin')->firstOrFail();

        $failure = 'nombre, correo o contraseña inválidos';
        $validated = $app['validator']->make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ])->validate();

        $failure = 'no se pudo guardar o confirmar el alta';
        $user = new User($validated);
        $user->role()->associate($role);
        if (! $user->save()) {
            throw new RuntimeException();
        }

        return true;
    });
} catch (Throwable) {
    fwrite(STDERR, "BEPARTNERS ADMIN ERROR: {$failure}. Apache no arrancará.\n");
    exit(1);
}

fwrite(STDOUT, $created
    ? "BEPARTNERS ADMIN OK: primera cuenta administrativa creada.\n"
    : "BEPARTNERS ADMIN OMITIDO: ya existen usuarios.\n");
