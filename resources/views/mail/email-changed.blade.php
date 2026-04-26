<x-mail::message>
# {{ __('mail.email_changed_heading') }}

{{ __('mail.email_changed_body', ['name' => $user->name]) }}

{{ __('mail.email_changed_new_address', ['email' => $newEmail]) }}

{{ __('mail.email_changed_warning') }}

{{ __('mail.thanks') }}<br>
{{ config('app.name') }}
</x-mail::message>
