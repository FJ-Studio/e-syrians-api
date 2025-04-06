<x-mail::message>
# {{ __('mail.verified_title') }}

{{ __('mail.verified_body') }}

<x-mail::button :url="$url">
    {{ __('mail.go_to_profile') }}
</x-mail::button>

{{ __('mail.thanks') }}<br>
{{ config('app.name') }}
</x-mail::message>
