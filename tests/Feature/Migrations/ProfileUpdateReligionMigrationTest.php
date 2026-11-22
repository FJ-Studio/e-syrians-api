<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;

it('upgrades existing audit enums without losing records and is safe on fresh schemas', function (bool $legacy): void {
    // An independent, prefixed connection keeps MySQL DDL (implicit commits)
    // outside RefreshDatabase's transaction and away from application tables.
    $connection = DB::build([
        ...config('database.connections.'.config('database.default')),
        'prefix' => 'census_upgrade_'.bin2hex(random_bytes(4)).'_',
    ]);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());
    $types = ['basic_data', 'address', 'regular', 'social', 'avatar'];

    try {
        Schema::create('profile_updates', function (Blueprint $table) use ($types, $legacy): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('change_type', $legacy ? $types : [...$types, 'religion']);
            $table->json('changes')->nullable();
            $table->timestamp('created_at');
            $table->softDeletes();
        });

        foreach ($types as $type) {
            $connection->table('profile_updates')->insert([
                'user_id' => 1,
                'change_type' => $type,
                'changes' => json_encode(['preserved' => true]),
                'created_at' => '2026-01-01 00:00:00',
                'deleted_at' => $type === 'avatar' ? '2026-02-01 00:00:00' : null,
            ]);
        }
        $before = $connection->table('profile_updates')->orderBy('id')->get()->toArray();
        $religion = ['user_id' => 1, 'change_type' => 'religion', 'created_at' => '2026-11-22 00:00:00'];

        if ($legacy) {
            expect(fn () => $connection->table('profile_updates')->insert($religion))->toThrow(QueryException::class);
        }

        $migration = require database_path('migrations/2026_11_22_000001_add_religion_to_profile_update_change_types.php');
        $migration->up();
        expect($connection->table('profile_updates')->orderBy('id')->get()->toArray())->toEqual($before);
        $connection->table('profile_updates')->insert($religion);
        expect($connection->table('profile_updates')->where('change_type', 'religion')->count())->toBe(1);

        // Rollback must not truncate an audit type that is already in use.
        $migration->down();
        $migration->up();
        expect($connection->table('profile_updates')->count())->toBe(6)
            ->and($connection->table('profile_updates')->where('change_type', 'religion')->count())->toBe(1);
        expect(fn () => $connection->table('profile_updates')->insert([
            ...$religion, 'change_type' => null,
        ]))->toThrow(QueryException::class);
    } finally {
        Schema::dropIfExists('profile_updates');
        Schema::swap($originalSchema);
        $connection->disconnect();
    }
})->with(['existing database' => true, 'fresh database' => false]);
