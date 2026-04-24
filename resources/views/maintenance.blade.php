<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $siteName }} — Under construction</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..700;1,9..144,400..700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bone: #fdfaf5;
            --ink: #1a1410;
            --ink-2: #443a32;
            --ink-3: #8b7e72;
            --oxblood: #a83a1a;
            --hairline: rgba(26, 20, 16, 0.12);
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: var(--bone);
            color: var(--ink);
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
        }
        .wrap {
            max-width: 640px;
            margin: 0 auto;
            padding: 96px 32px 64px;
            text-align: center;
        }
        .eyebrow {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 11px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--oxblood);
            margin-bottom: 24px;
        }
        h1 {
            font-family: 'Fraunces', serif;
            font-style: italic;
            font-weight: 500;
            font-size: 56px;
            line-height: 1.05;
            margin: 0 0 24px;
            color: var(--ink);
        }
        p {
            font-size: 17px;
            line-height: 1.55;
            color: var(--ink-2);
            max-width: 480px;
            margin: 0 auto 16px;
        }
        a {
            color: var(--oxblood);
            text-decoration: none;
            border-bottom: 1px solid currentColor;
        }
        .footer {
            position: fixed;
            bottom: 32px;
            left: 0;
            right: 0;
            text-align: center;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 10px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--ink-3);
        }
    </style>
</head>
<body>
    <div class="wrap">
        <p class="eyebrow">Maintenance</p>
        <h1>We're tuning things up.</h1>
        <p>{{ $siteName }} is briefly offline while we make improvements. Check back shortly.</p>
        <p style="margin-top: 32px; font-size: 14px; color: var(--ink-3);">If you're an admin, you can <a href="{{ route('login') }}">sign in</a> to manage the site.</p>
    </div>
    <p class="footer">{{ $siteName }} &middot; {{ date('Y') }}</p>
</body>
</html>
