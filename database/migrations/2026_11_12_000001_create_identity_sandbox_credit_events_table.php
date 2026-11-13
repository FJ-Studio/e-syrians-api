<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('identity_sandbox_credit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_id')->nullable()->constrained('identity_sandbox_credits')->nullOnDelete();
            $table->string('event', 32);
            $table->timestamp('occurred_at');
            $table->unique(['credit_id', 'event']);
            $table->index(['user_id', 'occurred_at', 'id'], 'identity_credit_events_user_timeline');
        });

        DB::table('identity_sandbox_credits')
            ->select('id', 'owner_id', 'issued_to_user_id', 'source', 'created_at', 'gifted_at')
            ->chunkById(500, function (Collection $credits): void {
                $events = [];
                foreach ($credits as $credit) {
                    $add = static function (int $userId, string $event, string $occurredAt) use ($credit, &$events): void {
                        $events[] = ['user_id' => $userId, 'credit_id' => $credit->id, 'event' => $event, 'occurred_at' => $occurredAt];
                    };
                    if ($credit->source === 'admin_grant' && $credit->created_at !== null) {
                        $add((int) $credit->owner_id, 'admin_granted', $credit->created_at);
                    }
                    if ($credit->source === 'self_claim' && $credit->issued_to_user_id !== null && $credit->created_at !== null) {
                        $add((int) $credit->issued_to_user_id, 'claimed', $credit->created_at);
                    }
                    if ($credit->gifted_at !== null) {
                        $add((int) $credit->owner_id, 'gift_received', $credit->gifted_at);
                        // Before this ledger, only the original claim identified a historical sender.
                        if ($credit->source === 'self_claim' && $credit->issued_to_user_id !== null
                            && (int) $credit->issued_to_user_id !== (int) $credit->owner_id) {
                            $add((int) $credit->issued_to_user_id, 'gift_sent', $credit->gifted_at);
                        }
                    }
                }
                if ($events !== []) {
                    DB::table('identity_sandbox_credit_events')->insert($events);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_sandbox_credit_events');
    }
};
