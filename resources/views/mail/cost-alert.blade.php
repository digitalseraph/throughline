@php
    /** @var \App\Models\User $admin */
    /** @var float $total */
    /** @var float $threshold */
    /** @var float $fixed */
    /** @var float $ai */
    $appUrl = config('app.url');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Infrastructure cost alert</title>
</head>
<body style="font-family: system-ui, -apple-system, Segoe UI, sans-serif; color: #1a1410; background: #fdfaf5; padding: 24px;">
    <h2 style="margin-top: 0;">Infrastructure cost alert</h2>

    <p>Hi {{ $admin->name }},</p>

    <p>
        This month's infrastructure cost has crossed the alert threshold you configured in admin settings.
    </p>

    <table style="border-collapse: collapse; margin: 16px 0;">
        <tr>
            <td style="padding: 4px 16px 4px 0; color: #555;">Current month total</td>
            <td style="padding: 4px 0; font-weight: 600;">${{ number_format($total, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; color: #555;">Fixed subscriptions</td>
            <td style="padding: 4px 0;">${{ number_format($fixed, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; color: #555;">AI (month-to-date)</td>
            <td style="padding: 4px 0;">${{ number_format($ai, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; color: #555;">Configured threshold</td>
            <td style="padding: 4px 0;">${{ number_format($threshold, 2) }}</td>
        </tr>
    </table>

    <p>
        <a href="{{ $appUrl }}/admin/costs" style="color: #a83a1a;">Open the costs dashboard</a> to review line items and adjust the threshold if needed.
    </p>

    <p style="color: #8b7e72; font-size: 12px; margin-top: 32px;">
        You'll only receive this alert once per calendar month. The flag resets on the 1st.
    </p>
</body>
</html>
