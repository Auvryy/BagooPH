<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;color:#0f172a;font-family:'Plus Jakarta Sans',sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dbe2ea;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="padding:22px 28px;background:#111827;border-bottom:4px solid #e00d42;">
                            <p style="margin:0;color:#ffffff;font-size:19px;font-weight:800;letter-spacing:.02em;">BagooPH</p>
                            <p style="margin:5px 0 0;color:#cbd5e1;font-size:12px;">Account application update</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 28px 12px;">
                            <p style="margin:0 0 8px;color:#64748b;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">{{ $accountRole }} account</p>
                            <h1 style="margin:0;color:#0f172a;font-size:25px;line-height:1.3;">{{ $title }}</h1>
                            <p style="margin:20px 0 0;font-size:15px;line-height:1.6;">Hello {{ $accountName }},</p>
                            <p style="margin:8px 0 0;color:#475569;font-size:14px;line-height:1.6;">Here is an update about your BagooPH {{ $accountRole }} account.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px 8px;">
                            <div style="padding:16px 18px;background:#fff1f4;border:1px solid #fecdd8;border-radius:12px;color:#334155;font-size:14px;line-height:1.7;white-space:pre-line;">{{ $messageBody }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px 28px;">
                            <p style="margin:0 0 18px;color:#475569;font-size:13px;line-height:1.6;">Sign in if asked to see your current result and next steps. Account, shop, company, and rider restrictions are reviewed separately; an approval does not automatically remove a separate restriction.</p>
                            <table role="presentation" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-radius:9px;background:#e00d42;">
                                        <a href="{{ $statusUrl }}" style="display:inline-block;padding:13px 18px;border:1px solid #e00d42;border-radius:9px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">View account status</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:18px 0 0;color:#64748b;font-size:13px;line-height:1.6;">You can also <a href="{{ $homeUrl }}" style="color:#c20836;font-weight:700;text-decoration:underline;">visit BagooPH</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;border-top:1px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:11px;line-height:1.6;">
                            This message was sent to the verified contact address on your account. A review request does not restore access automatically.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
