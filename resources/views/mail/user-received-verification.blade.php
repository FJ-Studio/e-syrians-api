<x-mail::message>
# {{ __('mail.verification_received_title') }}

{{ __('mail.verification_received_body', ['name' => $sender->name, 'surname' => $sender->surname]) }}

<x-mail::button :url="$url">
    {{ __('mail.review_profile_status') }}
</x-mail::button>

{{ __('mail.thanks') }}<br>
{{ config('app.name') }}
</x-mail::message>
