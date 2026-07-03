<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use App\Models\ProfileUpdate;
use InvalidArgumentException;
use Illuminate\Http\UploadedFile;
use App\Enums\ProfileChangeTypeEnum;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmailChangedNotification;
use App\Jobs\LogProfileChangeToBigQuery;
use App\Contracts\ProfileServiceContract;
use App\Contracts\FileUploadServiceContract;
use App\Exceptions\UpdateLimitReachedException;

class ProfileService implements ProfileServiceContract
{
    public function __construct(
        private readonly FileUploadServiceContract $fileUploadService,
    ) {
    }

    public function updateBasicInfo(User $user, array $data): void
    {
        $limit = config('e-syrians.verification.basic_info_updates_limit');
        $request = request();

        if ($user->getTotalUpdatesCount(ProfileChangeTypeEnum::BasicData->value) >= $limit) {
            $this->logBlockedAttempt($user, ProfileChangeTypeEnum::BasicData, $data, 'limit_reached', $request);

            throw new UpdateLimitReachedException('basic_info_updates_limit_reached');
        }

        $changes = $this->buildChanges($user, $data);

        $user->update($data);

        $profileUpdate = $this->createAuditRecord(
            $user,
            ProfileChangeTypeEnum::BasicData,
            $changes,
            $request,
        );

        dispatch(new LogProfileChangeToBigQuery($profileUpdate));

        // Cancel all active verifications when basic info changes
        $user->verifications()
            ->whereNull('cancelled_at')
            ->update([
                'cancelled_at' => now(),
                'cancelation_payload' => [
                    'reason' => 'user_updated_basic_info',
                ],
            ]);

        $user->markAsUnverified();
    }

    public function updateSocialLinks(User $user, array $data): void
    {
        $user->update($data);
    }

    public function updateAvatar(User $user, UploadedFile $file): string
    {
        $ext = $file->getClientOriginalExtension();
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (! in_array(strtolower($ext), $allowedExtensions)) {
            throw new InvalidArgumentException('invalid_file_type');
        }

        $fileName = $user->uuid.'.'.$ext;

        // Delete old avatar if it exists
        if (! empty($user->avatar)) {
            $this->fileUploadService->delete($user->avatar);
        }

        // Upload new avatar
        $path = $this->fileUploadService->upload($file, 'avatars', $fileName);

        // Update user avatar path
        $user->avatar = $path;
        $user->save();

        return $this->fileUploadService->url($path);
    }

    public function updateAddress(User $user, array $data, ?string $ipAddress, ?string $userAgent): void
    {
        $limit = config('e-syrians.verification.country_updates_limit');
        $request = request();

        if ($user->getAddressUpdatesCount() >= $limit) {
            $this->logBlockedAttempt($user, ProfileChangeTypeEnum::Address, $data, 'limit_reached', $request, $ipAddress, $userAgent);

            throw new UpdateLimitReachedException('country_updates_limit_reached');
        }

        $changes = $this->buildChanges($user, $data);

        $user->update($data);

        // Audit payload mirrors the three address fields we now
        // accept. `address` is opaque PII so we record only its
        // presence in the audit metadata, never the value itself
        // (the encrypted attribute is persisted on the user row
        // via `$user->update($data)` above).
        $profileUpdate = $this->createAuditRecord(
            $user,
            ProfileChangeTypeEnum::Address,
            $changes,
            $request,
            [
                'country' => $data['country'],
                'province' => $data['province'] ?? null,
                'address_set' => array_key_exists('address', $data) && $data['address'] !== null && $data['address'] !== '',
            ],
            $ipAddress,
            $userAgent,
        );

        dispatch(new LogProfileChangeToBigQuery($profileUpdate));
    }

    public function updateCensusData(User $user, array $data): void
    {
        $request = request();

        // Only collapse the array-shaped fields into the comma-
        // separated DB column when the client actually sent them.
        // The mobile census flow PATCHes one category at a time
        // (Employment / Education / Health / Demographics), so a
        // blind `implode(',', $data[...] ?? [])` would coerce the
        // absent keys to empty strings and wipe the stored values
        // on every partial save.
        if (array_key_exists('languages', $data)) {
            $data['languages'] = implode(',', $data['languages'] ?? []);
        }
        if (array_key_exists('other_nationalities', $data)) {
            $data['other_nationalities'] = implode(',', $data['other_nationalities'] ?? []);
        }

        // Religion is rate-limited separately from the rest of the
        // Census data. Polls can target by `religious_affiliation`,
        // so without a per-year cap a user could flip just-in-time
        // to vote in audience-gated polls. We detect a real change
        // (incoming value present AND different from stored) before
        // counting it against the limit — saving the form without
        // touching religion never burns a slot.
        $oldReligion = $user->religious_affiliation;
        $religionChanged = array_key_exists('religious_affiliation', $data)
            && $data['religious_affiliation'] !== null
            && $data['religious_affiliation'] !== ''
            && $data['religious_affiliation'] !== $oldReligion;

        if ($religionChanged) {
            $religionLimit = config('e-syrians.verification.religion_updates_limit');
            if ($user->getReligionUpdatesCount() >= $religionLimit) {
                $this->logBlockedAttempt(
                    $user,
                    ProfileChangeTypeEnum::Religion,
                    ['religious_affiliation' => $data['religious_affiliation']],
                    'limit_reached',
                    $request,
                    null,
                    null,
                );

                throw new UpdateLimitReachedException('religion_updates_limit_reached');
            }
        }

        $changes = $this->buildChanges($user, $data);

        $user->update($data);

        $profileUpdate = $this->createAuditRecord(
            $user,
            ProfileChangeTypeEnum::Regular,
            $changes,
            $request,
        );

        dispatch(new LogProfileChangeToBigQuery($profileUpdate));

        // Religion changes also get a dedicated audit row so the
        // per-year counter (getReligionUpdatesCount) can count
        // them without filtering through the generic Regular bag.
        // We log only the religion delta — the broader census
        // changes are already covered by the Regular audit above.
        //
        // The old value is taken from the pre-update snapshot we
        // captured above, NOT from `$user->getOriginal(...)`:
        // Eloquent's `update()` calls `syncOriginal()` internally,
        // so by the time we get here `getOriginal` returns the
        // freshly-saved new value and the audit would record
        // old=new, defeating the audit's purpose.
        if ($religionChanged) {
            $religionAudit = $this->createAuditRecord(
                $user,
                ProfileChangeTypeEnum::Religion,
                [
                    'religious_affiliation' => [
                        'old' => $oldReligion,
                        'new' => $data['religious_affiliation'],
                    ],
                ],
                $request,
            );

            dispatch(new LogProfileChangeToBigQuery($religionAudit));
        }
    }

    public function changeEmail(User $user, string $email): void
    {
        $oldEmail = $user->email;

        $user->email = $email;
        $user->email_verified_at = null;
        $user->save();
        $user->sendEmailVerificationNotification();

        // Notify the old email address about the change
        if ($oldEmail && $oldEmail !== $email) {
            Mail::to($oldEmail)->queue(new EmailChangedNotification($user, $email));
        }
    }

    public function updateNotifications(User $user, array $preferences): void
    {
        $user->received_verification_email = $preferences['received_verification_email'];
        $user->account_verified_email = $preferences['account_verified_email'];
        $user->save();
    }

    public function updateLanguage(User $user, string $language): void
    {
        $user->language = $language;
        $user->save();
    }

    /**
     * Build a diff of old vs new values for the given fields.
     */
    private function buildChanges(User $user, array $data): array
    {
        $changes = [];

        foreach ($data as $field => $newValue) {
            $oldValue = $user->getOriginal($field);
            $newValueStr = is_null($newValue) ? null : (string) $newValue;
            $oldValueStr = is_null($oldValue) ? null : (string) $oldValue;

            if ($oldValueStr !== $newValueStr) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    /**
     * Detect request source from the User-Agent header.
     */
    private function detectRequestSource(?Request $request): string
    {
        if (! $request) {
            return 'unknown';
        }

        $ua = strtolower($request->userAgent() ?? '');

        if (str_contains($ua, 'postman') || str_contains($ua, 'insomnia') || str_contains($ua, 'httpie') || str_contains($ua, 'curl/')) {
            return 'api_tool';
        }

        if ($request->hasHeader('X-Mobile-App')) {
            return 'mobile';
        }

        return 'web';
    }

    /**
     * Create a profile update audit record.
     */
    private function createAuditRecord(
        User $user,
        ProfileChangeTypeEnum $changeType,
        array $changes,
        ?Request $request,
        array $metaData = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProfileUpdate {
        /** @var ProfileUpdate $profileUpdate */
        $profileUpdate = $user->profileUpdates()->create([
            'change_type' => $changeType->value,
            'meta_data' => $metaData,
            'changes' => $changes,
            'ip_address' => $ipAddress ?? $request?->ip(),
            'user_agent' => $userAgent ?? $request?->userAgent(),
            'request_source' => $this->detectRequestSource($request),
            'session_id' => $request?->user()?->currentAccessToken()?->id,
        ]);

        return $profileUpdate;
    }

    /**
     * Log a blocked update attempt for audit/fraud detection.
     */
    private function logBlockedAttempt(
        User $user,
        ProfileChangeTypeEnum $changeType,
        array $data,
        string $blockReason,
        ?Request $request,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $changes = $this->buildChanges($user, $data);

        /** @var ProfileUpdate $profileUpdate */
        $profileUpdate = $user->profileUpdates()->create([
            'change_type' => $changeType->value,
            'meta_data' => [],
            'changes' => $changes,
            'ip_address' => $ipAddress ?? $request?->ip(),
            'user_agent' => $userAgent ?? $request?->userAgent(),
            'request_source' => $this->detectRequestSource($request),
            'session_id' => $request?->user()?->currentAccessToken()?->id,
            'blocked' => true,
            'block_reason' => $blockReason,
        ]);

        dispatch(new LogProfileChangeToBigQuery($profileUpdate));
    }
}
