<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RealRashid\Beam\Push\Stores\DatabaseStore;

/**
 * The one table Beam ships has to create itself on every database Laravel
 * supports. It did not: a unique index on the endpoint URL (varchar(1000),
 * 4000 bytes in utf8mb4) is refused by InnoDB, whose key limit is 3072 — so
 * `php artisan migrate` failed on MySQL and MariaDB while SQLite, which the
 * suite runs on, never noticed.
 *
 * pretend() compiles the real migration file through each driver's grammar
 * without a server, so this holds the promise without a MySQL job in CI.
 */
$drivers = [
    'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'beam', 'username' => 'u', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => ''],
    'pgsql' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'beam', 'username' => 'u', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public'],
    'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    'sqlsrv' => ['driver' => 'sqlsrv', 'host' => '127.0.0.1', 'database' => 'beam', 'username' => 'u', 'password' => '', 'prefix' => ''],
];

/** The SQL the migration would run on one driver. */
$compile = function (string $name, array $config): string {
    config()->set("database.connections.beam_{$name}", $config);

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000000_create_beam_push_subscriptions_table.php';

    $previous = config('database.default');
    config()->set('database.default', "beam_{$name}");
    $queries = DB::connection("beam_{$name}")->pretend(fn () => $migration->up());
    config()->set('database.default', $previous);

    return implode(";\n", array_map(fn (array $q) => $q['query'], $queries));
};

it('creates its table on every driver, with no index wider than a key', function (string $name) use ($drivers, $compile) {
    $sql = $compile($name, $drivers[$name]);

    // The endpoint is stored, and never indexed.
    expect($sql)->toContain('endpoint')
        ->and($sql)->not->toContain('endpoint_unique')
        ->and($sql)->not->toMatch('/unique[^;]*\(.?endpoint.?\)/i');

    // Uniqueness lives on the hash, which is 64 characters — 256 bytes at four
    // bytes per character, comfortably inside InnoDB's 3072-byte key limit.
    expect($sql)->toContain('endpoint_hash')
        ->and($sql)->toMatch('/(unique|primary)[^;]*endpoint_hash|endpoint_hash.?\)/i');

    foreach (['user_id', 'p256dh', 'auth', 'user_agent', 'created_at', 'updated_at'] as $column) {
        expect($sql)->toContain($column);
    }
})->with(array_keys($drivers));

it('keeps every indexed column inside the tightest key limit of any driver', function () use ($drivers, $compile) {
    $sql = $compile('mysql', $drivers['mysql']);

    // Every column named in an index or unique constraint, with the width the
    // create statement gave it. 3072 bytes is InnoDB's key limit with the
    // DYNAMIC row format — MySQL 5.7 and up, MariaDB 10.2.2 and up. Servers
    // older than that cap a key at 767 bytes, where Laravel's own unique index
    // on users.email (varchar(255), 1020 bytes) fails too; those applications
    // already call Schema::defaultStringLength(191), which narrows ours with it.
    preg_match_all('/(?:add unique|add index)[^(]*\(`([^`]+)`\)/', $sql, $indexed);

    expect($indexed[1])->not->toBeEmpty();

    foreach ($indexed[1] as $column) {
        preg_match('/`'.preg_quote($column, '/').'` (?:var)?char\((\d+)\)/', $sql, $width);
        $bytes = (int) ($width[1] ?? 0) * 4;   // utf8mb4

        expect($bytes)->toBeLessThanOrEqual(3072, "the index on [{$column}] is {$bytes} bytes; InnoDB refuses anything over 3072");
    }

    // And the one that has to be unique is nowhere near it.
    preg_match('/`endpoint_hash` (?:var)?char\((\d+)\)/', $sql, $hash);
    expect((int) $hash[1] * 4)->toBeLessThanOrEqual(1020);
});

it('identifies a device by the hash of its endpoint, so a URL of any length fits', function () {
    $long = 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 900);

    $store = app(DatabaseStore::class);
    $store->add(7, ['endpoint' => $long, 'keys' => ['p256dh' => 'P', 'auth' => 'A']]);
    $store->add(7, ['endpoint' => $long, 'keys' => ['p256dh' => 'P2', 'auth' => 'A2']]);   // same device: replaced, not duplicated

    expect($store->for(7))->toHaveCount(1)
        ->and($store->for(7)[0]['endpoint'])->toBe($long)
        ->and($store->for(7)[0]['keys']['p256dh'])->toBe('P2')
        ->and(DB::table(DatabaseStore::TABLE)->value('endpoint_hash'))->toBe(hash('sha256', $long));

    $store->remove(7, $long);
    expect($store->for(7))->toBe([]);
});
