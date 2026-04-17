<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Poll;
use App\Models\User;
use App\Mail\WeeklyNewsletter;
use App\Models\FeatureRequest;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class SendWeeklyNewsletter extends Command
{
    protected $signature = 'app:send-weekly-newsletter
                            {--dry-run : Show how many users would receive the email without actually sending}';

    protected $description = 'Send the weekly newsletter digest to all users. Contains polls and feature requests created in the past 7 days.';

    public function handle(): int
    {
        $since = Carbon::now()->subDays(7);

        // Fetch public polls created in the past week (with options eager-loaded)
        $polls = Poll::withoutGlobalScopes()
            ->where('is_private', false)
            ->where('audience_only', false)
            ->where('created_at', '>=', $since)
            ->whereNull('deleted_at')
            ->with('options')
            ->orderBy('created_at', 'desc')
            ->get();

        // Fetch feature requests created in the past week
        $featureRequests = FeatureRequest::where('created_at', '>=', $since)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $this->info("Polls this week: {$polls->count()}");
        $this->info("Feature requests this week: {$featureRequests->count()}");

        if ($polls->isEmpty() && $featureRequests->isEmpty()) {
            $this->info('Nothing to share this week. Skipping newsletter.');
            return self::SUCCESS;
        }

        // Group users by their language preference
        $locales = ['en', 'ar', 'ku'];
        $usersByLocale = [];
        foreach ($locales as $locale) {
            $usersByLocale[$locale] = User::where('language', $locale)
                ->whereNotNull('email_verified_at')
                ->pluck('email')
                ->filter()
                ->values();
        }

        // Users without a language set default to Arabic
        $defaultLocaleUsers = User::whereNull('language')
            ->whereNotNull('email_verified_at')
            ->pluck('email')
            ->filter()
            ->values();
        $usersByLocale['ar'] = $usersByLocale['ar']->merge($defaultLocaleUsers)->unique()->values();

        $totalRecipients = collect($usersByLocale)->sum(fn (Collection $emails) => $emails->count());
        $this->info("Total recipients: {$totalRecipients}");

        if ($this->option('dry-run')) {
            foreach ($usersByLocale as $locale => $emails) {
                $this->line("  [{$locale}] {$emails->count()} users");
            }
            $this->info('Dry run — no emails sent.');
            return self::SUCCESS;
        }

        if ($totalRecipients === 0) {
            $this->warn('No eligible recipients found. Skipping.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($usersByLocale as $locale => $emails) {
            if ($emails->isEmpty()) {
                continue;
            }

            $mailable = new WeeklyNewsletter(
                polls: $polls,
                featureRequests: $featureRequests,
                userLocale: $locale,
            );

            // Send in chunks using BCC to avoid exposing addresses
            foreach ($emails->chunk(50) as $chunk) {
                Mail::to([])
                    ->bcc($chunk->toArray())
                    ->queue($mailable);
                $sent += $chunk->count();
            }

            $this->info("Queued {$emails->count()} emails for [{$locale}]");
        }

        $this->info("Done. {$sent} emails queued.");

        return self::SUCCESS;
    }
}
