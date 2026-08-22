<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Poll;
use App\Models\User;
use App\Models\PollVote;
use App\Models\PollOption;
use App\Models\PollReaction;
use App\Models\FeatureRequest;
use Illuminate\Database\Seeder;
use App\Models\PollAudienceRule;
use App\Models\FeatureRequestVote;
use Illuminate\Database\Eloquent\Collection;

/**
 * Demo data for local development — populates the DB with enough users,
 * polls, and feature requests that the mobile app and the web dashboard
 * feel "inhabited" without manual clicking around.
 *
 * The seeder is idempotent: every record is created via `firstOrCreate`
 * keyed on a stable identifier (email for users, question/title for
 * polls/features), so running it again on a non-empty DB tops up the
 * dataset without producing duplicates.
 *
 * Usage:
 *   php artisan migrate:fresh --seed         # full reset then seed
 *   php artisan db:seed                      # adds to current data
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * All seeded users share the same password (`self::PASSWORD`) so any
 * account can be signed into without juggling credentials.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Shared password applied to every seeded user. The User model's
     * `'password' => 'hashed'` cast hashes it on save — we never store
     * the plain text. Change this constant if you need a different
     * one for your environment.
     */
    public const PASSWORD = 'password';

    /**
     * Suffix for synthesized email addresses so we can find (and re-find)
     * the demo cohort with a single `whereLike` query, and so they're
     * trivially distinguishable from real signups in admin tooling.
     */
    private const EMAIL_DOMAIN = 'demo.e-syrians.com';

    /** Total demo users to maintain (named + synthesised). */
    private const TARGET_USER_COUNT = 30;

    public function run(): void
    {
        $this->command?->info('Seeding demo users…');
        $users = $this->seedUsers();
        $this->command?->info(
            sprintf('  → %d users (shared password: %s)', $users->count(), self::PASSWORD),
        );

        $this->command?->info('Seeding demo polls…');
        $pollStats = $this->seedPolls($users);
        $this->command?->info(
            sprintf('  → %d new polls (%d total in DB)', $pollStats['created'], $pollStats['total']),
        );

        $this->command?->info('Seeding demo feature requests…');
        $featureStats = $this->seedFeatureRequests($users);
        $this->command?->info(
            sprintf('  → %d new feature requests (%d total in DB)', $featureStats['created'], $featureStats['total']),
        );
    }

    /**
     * Create the demo user cohort. Returns *all* demo users (newly created
     * + already-present from a prior run) so downstream pollsters and
     * feature-requesters can distribute work across the whole cohort.
     */
    private function seedUsers(): Collection
    {
        // — Named accounts: predictable emails so QA and devs can sign in
        // without grepping the DB. Each one hits a distinct verification
        // state so the Account tab UI can be exercised end-to-end.
        $named = [
            ['email' => 'admin@'    . self::EMAIL_DOMAIN, 'name' => 'Admin', 'surname' => 'Demo',   'role' => 'admin', 'verified' => true],
            ['email' => 'feras@'    . self::EMAIL_DOMAIN, 'name' => 'Feras', 'surname' => 'Jobeir', 'verified' => true],
            ['email' => 'sara@'     . self::EMAIL_DOMAIN, 'name' => 'Sara',  'surname' => 'Khoury', 'verified' => true],
            ['email' => 'omar@'     . self::EMAIL_DOMAIN, 'name' => 'Omar',  'surname' => 'Haddad', 'verified' => false],
            ['email' => 'leila@'    . self::EMAIL_DOMAIN, 'name' => 'Leila', 'surname' => 'Hassan', 'verified' => false],
            ['email' => 'rami@'     . self::EMAIL_DOMAIN, 'name' => 'Rami',  'surname' => 'Najjar', 'verified' => true],
            ['email' => 'noura@'    . self::EMAIL_DOMAIN, 'name' => 'Noura', 'surname' => 'Saliba', 'verified' => true],
        ];

        foreach ($named as $row) {
            $user = User::firstOrCreate(
                ['email' => $row['email']],
                $this->userAttributes(
                    name: $row['name'],
                    surname: $row['surname'],
                    email: $row['email'],
                    verified: $row['verified'],
                ),
            );

            if (! empty($row['role']) && ! $user->hasRole($row['role'])) {
                $user->assignRole($row['role']);
            }
        }

        // — Synthesised accounts: round out the cohort up to TARGET_USER_COUNT
        // with varied gender / hometown / country / ethnicity / religion so
        // the census charts have meaningful spread on the Stats screen.
        $existingDemoCount = User::query()
            ->whereLike('email', '%@' . self::EMAIL_DOMAIN)
            ->count();
        $toCreate = max(0, self::TARGET_USER_COUNT - $existingDemoCount);

        for ($i = 0; $i < $toCreate; $i++) {
            $first = fake()->firstName();
            $last = fake()->lastName();
            // Index + random shard guarantees uniqueness across re-runs even
            // if the cohort gets partly cleared between seeds.
            $email = sprintf(
                'user-%03d-%s@%s',
                $existingDemoCount + $i + 1,
                fake()->lexify('????'),
                self::EMAIL_DOMAIN,
            );
            User::firstOrCreate(
                ['email' => $email],
                $this->userAttributes(
                    name: $first,
                    surname: $last,
                    email: $email,
                    // ~70% verified — leaves a healthy unverified slice for
                    // the stacked-bar census charts to actually show two
                    // colours, not one.
                    verified: fake()->boolean(70),
                ),
            );
        }

        return User::query()
            ->whereLike('email', '%@' . self::EMAIL_DOMAIN)
            ->get();
    }

    /**
     * Build the attribute array for a new demo user. The User model's `boot`
     * method auto-generates `uuid` and the hashed-pii companions
     * (`email_hashed`, `national_id_hashed`, `phone_hashed`) on save, so
     * we don't need to set them here.
     */
    private function userAttributes(string $name, string $surname, string $email, bool $verified): array
    {
        // Pulled in from the enums on each call so updates to the canonical
        // enum lists flow automatically. Subset is hand-picked to keep the
        // chart buckets recognisably Syrian without flooring all 250+
        // CountryEnum cases at near-zero.
        $genders = ['f', 'm'];
        $hometowns = ['damascus', 'aleppo', 'homs', 'hama', 'latakia', 'tartus', 'idlib', 'deir-ezzor', 'hasakah'];
        $countries = ['SY', 'TR', 'DE', 'CA', 'US', 'SE', 'JO', 'LB', 'NL', 'GB', 'FR'];
        $ethnicities = ['arab', 'kurd', 'assyrian', 'armenian', 'turkmen', 'circassian'];
        $religions = ['sunni', 'shia', 'alawites', 'druze', 'greek-orthodox', 'syriac-orthodox', 'protestant', 'non-religious'];
        $verificationReasons = ['first_registrant', 'verifiers'];

        $verificationReason = $verified
            ? fake()->randomElement($verificationReasons)
            : null;

        $attributes = [
            'name' => $name,
            'surname' => $surname,
            'email' => $email,
            'email_verified_at' => now(),
            // Auto-hashed by `'password' => 'hashed'` cast on the model.
            'password' => self::PASSWORD,
            'gender' => fake()->randomElement($genders),
            'birth_date' => fake()->dateTimeBetween('-65 years', '-14 years')->format('Y-m-d'),
            'hometown' => fake()->randomElement($hometowns),
            'country' => fake()->randomElement($countries),
            'ethnicity' => fake()->randomElement($ethnicities),
            'religious_affiliation' => fake()->randomElement($religions),
            'verified_at' => $verified
                ? fake()->dateTimeBetween('-180 days', 'now')
                : null,
            'verification_reason' => $verificationReason,
        ];

        // First-registrant users power the `/users/first` endpoint, which
        // requires `avatar IS NOT NULL` AND at least one social-link
        // column to be set. Without those, the seeded set passed the
        // `verification_reason='first_registrant'` filter but matched
        // nothing else, leaving the "First Registrants" screen empty
        // even though the DB looked populated.
        //
        // The avatar path is fake — no file exists on disk. UserResource
        // catches the resulting `temporaryUrl` exception and serves a
        // null avatar URL, so the mobile/web cards just fall back to
        // initials. That's good enough for demo data.
        if ($verificationReason === 'first_registrant') {
            $attributes['avatar'] = sprintf('avatars/demo-%s.jpg', fake()->uuid());

            // The controller's social-link filter checks 11 columns. Pick
            // 1–3 at random per user so the demo shows a mix of social
            // presences rather than every card looking identical.
            $socialColumns = [
                'facebook_link', 'twitter_link', 'linkedin_link', 'instagram_link',
                'github_link', 'youtube_link', 'tiktok_link', 'pinterest_link',
                'twitch_link', 'snapchat_link', 'website',
            ];
            foreach (fake()->randomElements($socialColumns, fake()->numberBetween(1, 3)) as $col) {
                $platform = str_replace('_link', '', $col);
                $attributes[$col] = sprintf('https://%s.example.com/%s', $platform, fake()->userName());
            }
        }

        return $attributes;
    }

    /**
     * Seed a curated list of polls — covering active, upcoming, expired,
     * and audience-restricted variants — so the polls list / detail UI
     * has interesting cases to render. Each poll gets options, votes,
     * and reactions wired up.
     *
     * @param  Collection<int, User>  $users
     * @return array{created: int, total: int}
     */
    private function seedPolls(Collection $users): array
    {
        $polls = [
            [
                'question' => 'Should the platform support voice-based poll responses?',
                'options' => ['Yes, definitely', 'Maybe, if implemented well', 'No, keep text-only'],
                'days_offset_start' => -10,
                'days_offset_end' => 20,
            ],
            [
                'question' => 'Which language should we prioritise next on mobile?',
                'options' => ['Kurdish (Sorani)', 'French', 'Turkish', 'Russian'],
                'days_offset_start' => -5,
                'days_offset_end' => 25,
                'audience' => [
                    ['criterion' => 'age_min', 'value' => '18'],
                ],
            ],
            [
                'question' => 'How often do you check the platform?',
                'options' => ['Daily', 'Weekly', 'Monthly', 'Rarely'],
                'days_offset_start' => -30,
                'days_offset_end' => -2,
            ],
            [
                'question' => 'Which feature would help the diaspora most?',
                'options' => ['Document translation', 'Diaspora directory', 'Job board', 'Skill exchange'],
                'days_offset_start' => -3,
                'days_offset_end' => 14,
            ],
            [
                'question' => 'What should the next civic-engagement campaign focus on?',
                'options' => ['Education access', 'Healthcare', 'Housing', 'Infrastructure'],
                'days_offset_start' => -1,
                'days_offset_end' => 30,
            ],
            [
                'question' => 'Should poll creators see voter identities by default?',
                'options' => ['Yes, transparency', 'No, privacy', 'Configurable per poll'],
                'days_offset_start' => -7,
                'days_offset_end' => 21,
            ],
            [
                'question' => 'Should we publish a monthly civic-engagement digest by email?',
                'options' => ['Yes, monthly', 'Quarterly is enough', 'No emails please'],
                'days_offset_start' => 2, // upcoming
                'days_offset_end' => 32,
            ],
            [
                'question' => 'Audience-only: should we add a Syriac Christian sub-forum?',
                'options' => ['Yes', 'No', 'Not sure'],
                'days_offset_start' => -2,
                'days_offset_end' => 15,
                'audience_only' => true,
                'audience' => [
                    ['criterion' => 'religious_affiliation', 'value' => 'syriac-orthodox'],
                    ['criterion' => 'religious_affiliation', 'value' => 'syriac-catholic'],
                ],
            ],
        ];

        $created = 0;

        foreach ($polls as $config) {
            $creator = $users->random();
            $poll = Poll::firstOrCreate(
                ['question' => $config['question']],
                [
                    'start_date' => now()->addDays($config['days_offset_start']),
                    'end_date' => now()->addDays($config['days_offset_end']),
                    'max_selections' => 1,
                    'audience_can_add_options' => false,
                    'reveal_results' => 'before-voting',
                    'voters_are_visible' => true,
                    'is_private' => false,
                    'audience_only' => $config['audience_only'] ?? false,
                    'created_by' => $creator->id,
                ],
            );

            if (! $poll->wasRecentlyCreated) {
                continue;
            }

            $created++;

            foreach ($config['options'] as $optionText) {
                PollOption::create([
                    'poll_id' => $poll->id,
                    'option_text' => $optionText,
                    'created_by' => $creator->id,
                ]);
            }

            if (! empty($config['audience'])) {
                foreach ($config['audience'] as $rule) {
                    PollAudienceRule::firstOrCreate([
                        'poll_id' => $poll->id,
                        'criterion' => $rule['criterion'],
                        'value' => $rule['value'],
                    ]);
                }
            }

            // Only seed votes/reactions on polls whose voting window has
            // opened — votes on future polls would look bogus and would
            // also fail the API's date-range guards if exercised.
            if (! $poll->start_date->isPast()) {
                continue;
            }

            $options = $poll->options()->get();
            $voterPool = min($users->count(), random_int(8, 22));
            foreach ($users->random($voterPool) as $voter) {
                $option = $options->random();
                PollVote::firstOrCreate([
                    'poll_id' => $poll->id,
                    'poll_option_id' => $option->id,
                    'user_id' => $voter->id,
                ]);
            }

            $reactorPool = min($users->count(), random_int(5, 15));
            foreach ($users->random($reactorPool) as $reactor) {
                PollReaction::firstOrCreate(
                    [
                        'poll_id' => $poll->id,
                        'user_id' => $reactor->id,
                    ],
                    [
                        // 75% upvotes, 25% downvotes — produces a positive but
                        // not unanimous score, easier to scan in the UI.
                        'reaction' => fake()->randomElement(['up', 'up', 'up', 'down']),
                    ],
                );
            }
        }

        return [
            'created' => $created,
            'total' => Poll::count(),
        ];
    }

    /**
     * Seed feature requests across all four lifecycle states so the
     * sort=newest / sort=popular / sort=shipped UIs each have content.
     *
     * @param  Collection<int, User>  $users
     * @return array{created: int, total: int}
     */
    private function seedFeatureRequests(Collection $users): array
    {
        $features = [
            [
                'title' => 'Dark-mode toggle on web',
                'status' => 'shipped',
                'description' => 'Add a system / light / dark switcher to the web header so users can match their OS preference or override it on a per-session basis.',
            ],
            [
                'title' => 'Native iOS app',
                'status' => 'in_development',
                'description' => 'Bring a first-class iOS app to the diaspora — login, polls, census, feature requests, push notifications. Mirror the web feature set with a mobile-first layout.',
            ],
            [
                'title' => 'Multilingual push notifications',
                'status' => 'in_testing',
                'description' => "Send push notifications in the user's preferred language (ar / en / ku) instead of always English. Respect the same locale setting the rest of the app uses.",
            ],
            [
                'title' => 'Two-factor authentication via SMS',
                'status' => 'shipped',
                'description' => 'Allow users to add SMS-based 2FA in addition to TOTP authenticator apps, for users without a smartphone.',
            ],
            [
                'title' => 'Public profile QR codes',
                'status' => 'idea',
                'description' => 'Generate a QR code for each verified profile so users can share their identity at conferences and events without typing anything.',
            ],
            [
                'title' => 'Comment threads on polls',
                'status' => 'idea',
                'description' => 'Let users discuss polls with threaded replies — currently up/down reactions are too coarse to capture nuance.',
            ],
            [
                'title' => 'Census data export (PDF)',
                'status' => 'idea',
                'description' => 'Allow verified users to download a personalised PDF summarising their census record. Useful for embassy or NGO appointments.',
            ],
            [
                'title' => 'Verified-volunteers badge',
                'status' => 'idea',
                'description' => 'Add a special badge to users who have helped verify others — encourages the verification chain to keep growing.',
            ],
            [
                'title' => 'Apple Sign-In',
                'status' => 'shipped',
                'description' => 'Allow account creation and sign-in via Apple ID, matching the existing Google flow. Covers iOS, macOS, and the web.',
            ],
            [
                'title' => 'Poll templates',
                'status' => 'in_development',
                'description' => 'Save commonly-used poll structures (audience + options) as templates so creators can spin up similar polls in seconds.',
            ],
            [
                'title' => 'Bulk SMS for verification reminders',
                'status' => 'idea',
                'description' => 'Allow opt-in SMS reminders to verifiers when their queue exceeds a configurable threshold.',
            ],
            [
                'title' => 'Feature-request roadmap view',
                'status' => 'in_testing',
                'description' => "Show shipped / in-development / in-testing / idea features grouped on a roadmap board so users can see what's coming next.",
            ],
        ];

        $now = now();
        $created = 0;

        foreach ($features as $config) {
            $creator = $users->random();

            // Status is derived from the timeline timestamps in the model
            // (no `status` column exists), so we set the timeline to match
            // the desired status and let the accessor surface it.
            $codedAt = match ($config['status']) {
                'in_development', 'in_testing', 'shipped' => $now->copy()->subDays(random_int(20, 90)),
                default => null,
            };
            $testedAt = match ($config['status']) {
                'in_testing', 'shipped' => $now->copy()->subDays(random_int(5, 19)),
                default => null,
            };
            $deployedAt = match ($config['status']) {
                'shipped' => $now->copy()->subDays(random_int(1, 4)),
                default => null,
            };

            $feature = FeatureRequest::firstOrCreate(
                ['title' => $config['title']],
                [
                    'description' => $config['description'],
                    'created_by' => $creator->id,
                    'coded_at' => $codedAt,
                    'tested_at' => $testedAt,
                    'deployed_at' => $deployedAt,
                ],
            );

            if (! $feature->wasRecentlyCreated) {
                continue;
            }

            $created++;

            $voterPool = min($users->count(), random_int(6, 20));
            foreach ($users->random($voterPool) as $voter) {
                FeatureRequestVote::firstOrCreate(
                    [
                        'feature_request_id' => $feature->id,
                        'user_id' => $voter->id,
                    ],
                    [
                        // 80% upvotes — feature requests skew positive in
                        // the web data so we mirror that here.
                        'vote' => fake()->randomElement(['up', 'up', 'up', 'up', 'down']),
                    ],
                );
            }
        }

        return [
            'created' => $created,
            'total' => FeatureRequest::count(),
        ];
    }
}
