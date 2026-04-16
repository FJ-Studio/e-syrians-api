<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Use the query builder (not the Poll model) so we read the raw
        // `audience` JSON column directly, bypassing the new accessor which
        // reads from the normalized rules table.
        DB::table('polls')
            ->whereNotNull('audience')
            ->orderBy('id')
            ->chunkById(200, function ($polls): void {
                $now = now();
                $rules = [];

                foreach ($polls as $poll) {
                    $audience = json_decode($poll->audience, true);
                    if (empty($audience) || ! is_array($audience)) {
                        continue;
                    }

                    // Multi-value categorical criteria
                    $arrayCriteria = [
                        'gender',
                        'country',
                        'religious_affiliation',
                        'hometown',
                        'ethnicity',
                        'city_inside_syria',
                    ];

                    foreach ($arrayCriteria as $criterion) {
                        if (isset($audience[$criterion]) && is_array($audience[$criterion])) {
                            foreach (array_unique($audience[$criterion]) as $value) {
                                $rules[] = [
                                    'poll_id' => $poll->id,
                                    'criterion' => $criterion,
                                    'value' => (string) $value,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                            }
                        }
                    }

                    // Age range (skip defaults 13/120 — they mean "no restriction")
                    if (isset($audience['age_range']) && is_array($audience['age_range'])) {
                        $min = $audience['age_range']['min'] ?? null;
                        $max = $audience['age_range']['max'] ?? null;
                        if ($min !== null && $min !== '' && (int) $min !== 13) {
                            $rules[] = [
                                'poll_id' => $poll->id,
                                'criterion' => 'age_min',
                                'value' => (string) $min,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                        if ($max !== null && $max !== '' && (int) $max !== 120) {
                            $rules[] = [
                                'poll_id' => $poll->id,
                                'criterion' => 'age_max',
                                'value' => (string) $max,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    // Allowed voters — stored as criterion "allowed_voter" (singular) per row
                    if (isset($audience['allowed_voters']) && is_array($audience['allowed_voters'])) {
                        foreach (array_unique($audience['allowed_voters']) as $voter) {
                            $rules[] = [
                                'poll_id' => $poll->id,
                                'criterion' => 'allowed_voter',
                                'value' => (string) $voter,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }

                if (count($rules) > 0) {
                    DB::table('poll_audience_rules')->insert($rules);
                }
            });
    }

    public function down(): void
    {
        DB::table('poll_audience_rules')->truncate();
    }
};
