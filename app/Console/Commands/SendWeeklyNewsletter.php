<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Poll;
use App\Models\User;
use App\Mail\WeeklyNewsletter;
use App\Models\FeatureRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

class SendWeeklyNewsletter extends Command
{
    protected $signature = 'app:send-weekly-newsletter
                            {--dry-run : Show how many users would receive the email without actually sending}';

    protected $description = 'Send the weekly newsletter digest to all users. Contains polls and feature requests created in the past 7 days.';

    public function handle(): int
    {
        $since = \Illuminate\Support\Facades\Date::now()->subDays(7);

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
            ->orderBy('created_at', 'desc')
            ->get();

        $this->info("Polls this week: {$polls->count()}");
        $this->info("Feature requests this week: {$featureRequests->count()}");

        if ($polls->isEmpty() && $featureRequests->isEmpty()) {
            $this->info('Nothing to share this week. Skipping newsletter.');
            return self::SUCCESS;
        }

        // Count recipients per locale for reporting
        $locales = ['en', 'ar', 'ku'];
        $countByLocale = [];
        foreach ($locales as $locale) {
            $countByLocale[$locale] = User::where('language', $locale)
                ->whereNotNull('email_verified_at')
                ->whereNotNull('email')
                ->count();
        }

        // Users without a language set default to Arabic
        $defaultCount = User::whereNull('language')
            ->whereNotNull('email_verified_at')
            ->whereNotNull('email')
            ->count();
        $countByLocale['ar'] += $defaultCount;

        $totalRecipients = array_sum($countByLocale);
        $this->info("Total recipients: {$totalRecipients}");

        if ($this->option('dry-run')) {
            foreach ($countByLocale as $locale => $count) {
                $this->line("  [{$locale}] {$count} users");
            }
            $this->info('Dry run — no emails sent.');
            return self::SUCCESS;
        }

        if ($totalRecipients === 0) {
            $this->warn('No eligible recipients found. Skipping.');
            return self::SUCCESS;
        }

        // Send individual emails per user, processed in chunks to limit memory usage
        $sent = 0;
        foreach ($locales as $locale) {
            $query = User::whereNotNull('email_verified_at')
                ->whereNotNull('email');

            if ($locale === 'ar') {
                $query->where(fn($q) => $q->where('language', 'ar')->orWhereNull('language'));
            } else {
                $query->where('language', $locale);
            }

            $query->chunkById(100, function ($users) use ($polls, $featureRequests, $locale, &$sent): void {
                foreach ($users as $user) {
                    Mail::to($user->email)
                        ->queue(new WeeklyNewsletter(
                            polls: $polls,
                            featureRequests: $featureRequests,
                            userLocale: $locale,
                        ));
                    $sent++;
                }
            });

            $this->info("Queued {$countByLocale[$locale]} emails for [{$locale}]");
        }

        $this->info("Done. {$sent} emails queued.");

        return self::SUCCESS;
    }
}
