<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $title }}</title></head>
<body style="margin:0;background:#f8fafc;font-family:'Plus Jakarta Sans',sans-serif;color:#0f172a;">
    <main style="max-width:560px;margin:32px auto;padding:24px;background:#fff;border:1px solid #cbd5e1;border-radius:8px;">
        <p style="color:#E00D42;font-weight:700;">BagooPH</p>
        <h1 style="font-size:22px;line-height:1.4;">{{ $title }}</h1>
        <p>Hello {{ $accountName }},</p>
        <p>This update is for your {{ $accountRole }} account.</p>
        <p style="white-space:pre-line;line-height:1.7;">{{ $messageBody }}</p>
        <p style="line-height:1.7;">Sign in to BagooPH and open Account status to see the current result and your next steps. If your account is rejected, suspended or deactivated, you can request a review there.</p>
        <p style="font-size:12px;line-height:1.6;color:#475569;">Each account type has its own approval and activity status. A review request does not restore access automatically.</p>
    </main>
</body>
</html>
