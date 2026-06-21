<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Exception;
use Illuminate\Http\Request;
use App\Enums\ProfileChangeTypeEnum;
use Illuminate\Support\Facades\Date;
use App\Contracts\FileUploadServiceContract;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOwner = ($request->user() && $request->user()->uuid === $this->uuid)
            || (isset($this->additional['isOwner']) && $this->additional['isOwner'] === true);

        $avatarUrl = null;
        if ($this->avatar) {
            try {
                $fileService = resolve(FileUploadServiceContract::class);
                $avatarUrl = $fileService->temporaryUrl(
                    $this->avatar,
                    (int) config('e-syrians.files.avatar.ttl', 60),
                );
            } catch (Exception $e) {
                $avatarUrl = null;
            }
        }

        $data = [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'surname' => $this->surname,
            'avatar' => $avatarUrl,
            'created_at' => $this->created_at,
            /*
             * Public birth-year only. The exact `birth_date` lives in
             * the owner-only block below — exposing the full date
             * publicly was a privacy/security leak (birth date is a
             * common identity-challenge field at banks, gov agencies,
             * etc., and the public profile is unauthenticated +
             * deep-linkable + scrapable). The year alone is enough
             * for community context (age cohort, "joined in their
             * 30s", etc.) without revealing the challenge value.
             *
             * `birth_date` is stored as a string (not in the model's
             * cast map), so we parse with `Date::parse` — same
             * approach the User model uses in `audienceCheckFor()`
             * for the age check. Wrapped in a try/catch so a
             * malformed value falls back to null instead of throwing.
             */
            'birth_year' => (function () {
                if (! $this->birth_date) {
                    return null;
                }
                try {
                    return (int) Date::parse($this->birth_date)->year;
                } catch (Exception $e) {
                    return null;
                }
            })(),
            'hometown' => $this->hometown,
            'country' => $this->country,
            'gender' => $this->gender,
            'ethnicity' => $this->ethnicity,
            'verified_at' => $this->verified_at,

            /*
             * Public profile stats trio. Mobile + web read these to
             * render the "verified by N · M votes · K requests" row
             * on /verify/{uuid}. We use direct ->count() relationship
             * queries rather than `whenCounted` because UserResource
             * is consumed both by single-user fetches (where this is
             * cheap) and by the small first-registrants list — both
             * acceptable without a withCount preload. If a future
             * endpoint exposes a large paginated user list, switch
             * those queries to `withCount(...)` + `whenCounted` here.
             *
             * `activeVerifiers` excludes cancelled verifications so
             * the surfaced count matches what the verifiers list
             * actually shows.
             */
            'verified_by_count' => $this->activeVerifiers()->count(),
            'polls_count' => $this->polls()->count(),
            'requests_count' => $this->featureRequests()->count(),

            // Social links
            'facebook_link' => $this->facebook_link,
            'twitter_link' => $this->twitter_link,
            'linkedin_link' => $this->linkedin_link,
            'instagram_link' => $this->instagram_link,
            'snapchat_link' => $this->snapchat_link,
            'tiktok_link' => $this->tiktok_link,
            'youtube_link' => $this->youtube_link,
            'pinterest_link' => $this->pinterest_link,
            'twitch_link' => $this->twitch_link,
            'website' => $this->website,
            'github_link' => $this->github_link,

            // Owner-only fields
            $this->mergeWhen($isOwner, [
                'birth_date' => $this->birth_date,
                'record_id' => $this->record_id,
                'phone' => $this->phone,
                'national_id' => $this->national_id,
                'middle_name' => $this->middle_name,
                'email' => $this->email,
                'address' => $this->address,
                'shelter' => $this->shelter,
                'education_level' => $this->education_level,
                'skills' => $this->skills,
                'marital_status' => $this->marital_status,
                'source_of_income' => $this->source_of_income,
                'estimated_monthly_income' => $this->estimated_monthly_income,
                'number_of_dependents' => $this->number_of_dependents,
                'health_status' => $this->health_status,
                'health_insurance' => $this->health_insurance,
                'easy_access_to_healthcare_services' => $this->easy_access_to_healthcare_services,
                'religious_affiliation' => $this->religious_affiliation,
                'other_nationalities' => $this->other_nationalities,
                'communication' => $this->communication,
                'more_info' => $this->more_info,
                'email_verified_at' => $this->email_verified_at,
                'phone_verified_at' => $this->phone_verified_at,
                'verification_reason' => $this->verification_reason,
                'marked_as_fake_at' => $this->marked_as_fake_at,
                'languages' => $this->languages,
                'roles' => $this->getRoleNames(),
                'permissions' => $this->getAllPermissions()->pluck('name'),
                'basic_info_updates' => (int) (config('e-syrians.verification.basic_info_updates_limit') - $this->getTotalUpdatesCount(ProfileChangeTypeEnum::BasicData->value)),
                /*
                 * Count of active verifications the user has
                 * issued (rows where verifier_id = me AND not
                 * cancelled). Surfaced for the Sent Verifications
                 * tab's StatCard so it can show "X people you've
                 * verified · Y of 25 left" without a separate
                 * round-trip. The 25 cap lives in
                 * config('e-syrians.verification.max') and is
                 * enforced server-side by canVerify().
                 */
                'verifications_made_count' => $this->verifications()->whereNull('cancelled_at')->count(),
                /*
                 * Religion change budget remaining in the current
                 * 365-day window. Surfaced so the census form can
                 * show "1 religion change left" near the religion
                 * picker before the user commits and gets a 403.
                 * The cap exists because polls target by
                 * religious_affiliation — see config
                 * `verification.religion_updates_limit`.
                 */
                'religion_updates' => (int) max(
                    0,
                    config('e-syrians.verification.religion_updates_limit') - $this->resource->getReligionUpdatesCount(),
                ),
                'received_verification_email' => $this->received_verification_email,
                'account_verified_email' => $this->account_verified_email,
                'province' => $this->province,
                'language' => $this->language,
                'has_password' => ! is_null($this->resource->password),
                /*
                 * Profile-completeness trio (`{filled, total, percentage}`).
                 * Lives on the User model under
                 * `User::getProfileCompleteness()` and counts fields from
                 * `User::PROFILE_COMPLETENESS_FIELDS`. Surfaced here so the
                 * web account dashboard + mobile "Complete your profile"
                 * CTA read identical numbers without each client
                 * re-implementing the rule.
                 */
                'profile_completeness' => $this->resource->getProfileCompleteness(),
            ]),

        ];

        return $data;
    }

    /**
     * Resolve the resource to an array, stripping null values.
     *
     * @param  Request|null  $request
     * @return array<string, mixed>
     */
    public function resolve($request = null): array
    {
        $resolved = parent::resolve($request);

        return array_filter($resolved, fn ($value) => ! is_null($value));
    }
}
