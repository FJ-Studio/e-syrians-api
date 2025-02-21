<x-mail::message>
# Congratulations! Your account has been verified. 🥳

You have collected enough verifications from the community. You can now enjoy the benefits of a verified profile.

<x-mail::button :url="$url">
Go to my profile
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>