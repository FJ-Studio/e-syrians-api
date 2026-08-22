<x-mail::message>
# {{ __('mail.otp_heading') }}

{{ __('mail.otp_body') }}

<x-mail::panel>
<div style="text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 8px; font-family: monospace;">
{{ $code }}
</div>
</x-mail::panel>

{{ __('mail.otp_expiry') }}

{{ __('mail.otp_ignore') }}

{{ __('mail.thanks') }}<br>
{{ config('app.name') }}
</x-mail::message>
