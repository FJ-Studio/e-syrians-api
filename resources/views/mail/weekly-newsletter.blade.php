@php $isRtl = in_array($userLocale, ['ar']); $dir = $isRtl ? 'rtl' : 'ltr'; $align = $isRtl ? 'right' : 'left'; @endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="{{ $dir }}" lang="{{ $userLocale }}">

<head>
    <title>{{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="color-scheme" content="light" />
    <style>
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            background-color: #edf2f7;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #374151;
            -webkit-text-size-adjust: none;
            direction: {{ $dir }};
            text-align: {{ $align }};
        }

        a {
            color: #4f46e5;
            text-decoration: none;
        }

        @media only screen and (max-width: 600px) {
            .inner-body {
                width: 100% !important;
            }

            .content-cell {
                padding: 24px 16px !important;
            }
        }
    </style>
</head>

<body>
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #edf2f7; margin: 0; padding: 0;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation">

                    {{-- Header --}}
                    <tr>
                        <td style="padding: 25px 0; text-align: center;">
                            <a href="{{ $frontendUrl }}" style="color: #3d4852; font-size: 19px; font-weight: bold; text-decoration: none;text-transform: uppercase;">
                                {{ config('app.name') }}
                            </a>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td width="100%" style="background-color: #edf2f7; border: hidden;">
                            <table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 0 rgba(0,0,150,.025), 2px 4px 0 rgba(0,0,150,.015); margin: 0 auto; width: 570px;">
                                <tr>
                                    <td class="content-cell" style="padding: 32px; direction: {{ $dir }}; text-align: {{ $align }};">

                                        {{-- Greeting --}}
                                        <h1 style="font-size: 22px; font-weight: 700; color: #111827; margin: 0 0 6px; text-align: {{ $align }};">{{ __('mail.newsletter_greeting') }}</h1>
                                        <p style="color: #6b7280; font-size: 15px; margin: 0 0 28px; line-height: 1.5; text-align: {{ $align }};">{{ __('mail.newsletter_intro') }}</p>

                                        {{-- ── Polls ── --}}
                                        @if($polls->isNotEmpty())
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 16px;">
                                            <tr>
                                                <td style="font-size: 14px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.08em; padding-bottom: 10px; border-bottom: 2px solid #e5e7eb; text-align: {{ $align }};">
                                                    📊&nbsp; {{ __('mail.newsletter_polls_heading') }}
                                                </td>
                                            </tr>
                                        </table>

                                        @foreach($polls as $poll)
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px;">
                                            {{-- Poll question --}}
                                            <tr>
                                                <td style="padding: 16px 18px 12px; background-color: #fafafa; border-radius: 8px 8px 0 0; text-align: {{ $align }};">
                                                    <a href="{{ $frontendUrl }}/{{ $userLocale }}/polls/{{ $poll->id }}" style="color: #111827; font-size: 15px; font-weight: 700; text-decoration: none; line-height: 1.4;">
                                                        {{ $poll->question }}
                                                    </a>
                                                </td>
                                            </tr>
                                            {{-- Options --}}
                                            <tr>
                                                <td style="padding: 6px 18px 16px; background-color: #fafafa;">
                                                    @foreach($poll->options as $index => $option)
                                                    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 6px;">
                                                        <tr>
                                                            <td style="padding: 10px 14px; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; color: #374151; line-height: 1.4; direction: {{ $dir }}; text-align: {{ $align }};">
                                                                <table cellpadding="0" cellspacing="0" role="presentation" style="direction: {{ $dir }};">
                                                                    <tr>
                                                                        <td style="width: 28px; height: 28px; border-radius: 50%; background-color: #eef2ff; color: #4f46e5; font-size: 12px; font-weight: 700; text-align: center; line-height: 28px; vertical-align: middle;" width="28" height="28">{{ $index < 26 ? chr(65 + $index) : $index + 1 }}</td>
                                                                        <td style="padding-{{ $isRtl ? 'right' : 'left' }}: 12px; vertical-align: middle; text-align: {{ $align }};">{{ $option->option_text }}</td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    @endforeach
                                                </td>
                                            </tr>
                                            {{-- Vote CTA --}}
                                            <tr>
                                                <td style="padding: 0 18px 16px; background-color: #fafafa; border-radius: 0 0 8px 8px; text-align: {{ $align }};">
                                                    <a href="{{ $frontendUrl }}/{{ $userLocale }}/polls/{{ $poll->id }}" style="display: inline-block; padding: 8px 22px; background-color: #4f46e5; color: #ffffff; font-size: 13px; font-weight: 600; text-decoration: none; border-radius: 6px; line-height: 1;">
                                                        {{ __('mail.newsletter_vote_now') }}
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                        @endforeach
                                        @endif

                                        {{-- ── Feature Requests ── --}}
                                        @if($featureRequests->isNotEmpty())
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 16px; margin-top: 8px;">
                                            <tr>
                                                <td style="font-size: 14px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.08em; padding-bottom: 10px; border-bottom: 2px solid #e5e7eb; text-align: {{ $align }};">
                                                    💡&nbsp; {{ __('mail.newsletter_features_heading') }}
                                                </td>
                                            </tr>
                                        </table>

                                        @foreach($featureRequests as $feature)
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                                            <tr>
                                                <td style="padding: 14px 18px; background-color: #fafafa; border-radius: 8px; text-align: {{ $align }}; direction: {{ $dir }};">
                                                    <a href="{{ $frontendUrl }}/{{ $userLocale }}/feature-requests" style="color: #111827; font-size: 15px; font-weight: 700; text-decoration: none; display: block; margin-bottom: 4px; line-height: 1.4;">
                                                        {{ $feature->title }}
                                                    </a>
                                                    <p style="color: #6b7280; font-size: 13px; margin: 0; line-height: 1.5;">
                                                        {{ Str::words($feature->description, 20, '…') }}
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                        @endforeach
                                        @endif

                                        {{-- ── Empty state ── --}}
                                        @if($polls->isEmpty() && $featureRequests->isEmpty())
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 20px 0;">
                                            <tr>
                                                <td style="text-align: center; padding: 30px 20px; background-color: #fafafa; border-radius: 8px;">
                                                    <p style="color: #6b7280; font-size: 15px; margin: 0;">{{ __('mail.newsletter_empty') }}</p>
                                                </td>
                                            </tr>
                                        </table>
                                        @endif

                                        {{-- Main CTA --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 28px auto 0; text-align: center;">
                                            <tr>
                                                <td align="center">
                                                    <a href="{{ $frontendUrl }}/{{ $userLocale }}" class="button button-primary" style="display: inline-block; background-color: #2d3748; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 4px; padding: 10px 24px; line-height: 1;">
                                                        {{ __('mail.newsletter_cta') }}
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Sign-off --}}
                                        <p style="color: #718096; font-size: 16px; margin-top: 28px; line-height: 1.5; text-align: {{ $align }};">
                                            {{ __('mail.thanks') }}<br><span style="text-transform:uppercase">{{ config('app.name') }}</span>
                                        </p>

                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td>
                            <table align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto; padding: 0; text-align: center; width: 570px;">
                                <tr>
                                    <td style="padding: 32px; text-align: center;">
                                        <p style="color: #b0adc5; font-size: 12px; margin: 0;">&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>

</html>