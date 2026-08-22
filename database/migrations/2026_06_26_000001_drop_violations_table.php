<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Drop the `violations` table.
     *
     * The violations feature was retired in earlier cleanup commits:
     *   - app/Models/Violation*.php deleted
     *   - app/Enums/Violation*Enum.php deleted
     *   - app/Http/Controllers/ViolationController.php deleted
     *   - app/Http/Resources/ViolationResource.php deleted
     *   - app/Http/Requests/Violations/*.php deleted
     *   - Mobile + web UI surfaces removed
     *
     * Only the table itself remained, kept around briefly in case
     * we wanted to export the existing rows. We don't — drop it
     * now.
     *
     * DATA LOSS — irreversible. Any existing violation rows are
     * destroyed by this migration. We keep `down()` reversible at
     * the schema level so `migrate:fresh` / test rollback paths
     * still work, but the previous data cannot be restored from
     * within the migration system.
     */
    public function up(): void
    {
        Schema::dropIfExists('violations');
    }

    /**
     * Re-create the empty `violations` table schema. Schema-only
     * recovery — the data this table once held is gone. Kept here
     * so a downward migration in CI / test environments doesn't
     * fail; production rollback would only get the empty schema.
     */
    public function down(): void
    {
        Schema::create('violations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->enum('category', [
                'violence',
                'hate-speech',
                'child-abuse',
                'sexual-abuse',
                'corruption',
                'other',
            ]);
            $table->string('target');
            $table->text('description');
            $table->date('date_of_violation');
            $table->text('location');
            $table->string('target_group');
            $table->json('attachments')->nullable();
            $table->json('links')->nullable();
            $table->enum('status', [
                'pending',
                'published',
                'removed',
            ])->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
