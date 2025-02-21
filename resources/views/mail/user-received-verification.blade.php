<x-mail::message>
# Verification Received

Your profile data has been verified by {{ $sender->name }} {{ $sender->surname }}.

<x-mail::button :url="$url">
    Review Profile Status
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>