<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <title>{{ config('app.name') }} — Suspicious Activity Alert</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <style>
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            background-color: #edf2f7;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #374151;
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
                            <span style="color: #3d4852; font-size: 19px; font-weight: bold; text-transform: uppercase;">
                                {{ config('app.name') }}
                            </span>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td width="100%" style="background-color: #edf2f7;">
                            <table align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 0 rgba(0,0,150,.025); margin: 0 auto; width: 570px;">
                                <tr>
                                    <td style="padding: 32px;">

                                        {{-- Severity banner --}}
                                        @php
                                            $colors = [
                                                'high' => ['bg' => '#fef2f2', 'border' => '#ef4444', 'text' => '#991b1b'],
                                                'medium' => ['bg' => '#fffbeb', 'border' => '#f59e0b', 'text' => '#92400e'],
                                                'low' => ['bg' => '#f0fdf4', 'border' => '#22c55e', 'text' => '#166534'],
                                            ];
                                            $c = $colors[$activity->severity] ?? $colors['medium'];
                                        @endphp
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 24px;">
                                            <tr>
                                                <td style="padding: 12px 16px; background-color: {{ $c['bg'] }}; border-left: 4px solid {{ $c['border'] }}; border-radius: 4px;">
                                                    <span style="font-size: 14px; font-weight: 700; color: {{ $c['text'] }}; text-transform: uppercase;">
                                                        {{ $activity->severity }} severity
                                                    </span>
                                                    <span style="font-size: 14px; color: {{ $c['text'] }};">
                                                        &mdash; Score: {{ $activity->score }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>

                                        <h1 style="font-size: 20px; font-weight: 700; color: #111827; margin: 0 0 16px;">Suspicious Activity Detected</h1>

                                        {{-- User info --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px;">
                                            <tr>
                                                <td style="padding: 14px 18px; background-color: #fafafa; border-radius: 8px;">
                                                    <p style="margin: 0 0 4px; font-size: 14px; color: #6b7280;">User</p>
                                                    @if($user)
                                                        <p style="margin: 0; font-size: 15px; font-weight: 600; color: #111827;">
                                                            {{ $user->name }} {{ $user->surname }}
                                                            <span style="font-weight: 400; color: #6b7280;">({{ $user->email }})</span>
                                                        </p>
                                                        <p style="margin: 4px 0 0; font-size: 13px; color: #6b7280;">
                                                            ID: #{{ $user->id }}
                                                        </p>
                                                    @else
                                                        <p style="margin: 0; font-size: 15px; color: #111827;">User #{{ $activity->user_id }}</p>
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Rules triggered --}}
                                        <p style="font-size: 14px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 10px;">
                                            Rules Triggered
                                        </p>
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 20px;">
                                            @foreach($activity->rules_triggered as $rule)
                                            <tr>
                                                <td style="padding: 8px 14px; background-color: #fafafa; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; color: #374151; margin-bottom: 4px;">
                                                    {{ str_replace('_', ' ', ucfirst($rule)) }}
                                                </td>
                                            </tr>
                                            <tr><td style="height: 4px;"></td></tr>
                                            @endforeach
                                        </table>

                                        {{-- Detected at --}}
                                        <p style="font-size: 13px; color: #6b7280; margin: 0 0 24px;">
                                            Detected at: {{ $activity->detected_at->format('Y-m-d H:i:s') }} UTC
                                        </p>

                                        {{-- Sign-off --}}
                                        <p style="color: #718096; font-size: 14px; margin: 0; line-height: 1.5;">
                                            This is an automated alert from the fraud detection system.
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
                                        <p style="color: #b0adc5; font-size: 12px; margin: 0;">&copy; {{ date('Y') }} {{ config('app.name') }}.</p>
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
