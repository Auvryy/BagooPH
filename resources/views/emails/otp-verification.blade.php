<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BagooPH Verification Code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F8FAFC; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0F172A;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F8FAFC; padding: 40px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 520px; background-color: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 32px 32px 24px 32px; border-bottom: 1px solid #F1F5F9; text-align: center;">
                            <h1 style="margin: 0; font-size: 26px; font-weight: 900; letter-spacing: -0.5px; color: #E00D42;">
                                Bagoo<span style="color: #0F172A;">PH</span>
                            </h1>
                            <p style="margin: 4px 0 0 0; font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: #64748B; font-family: monospace;">
                                Security & Verification Service
                            </p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 32px;">
                            <h2 style="margin: 0 0 12px 0; font-size: 18px; font-weight: 700; color: #0F172A;">
                                @if($purpose === 'password_reset')
                                    Password Reset Verification Code
                                @else
                                    Email Verification Code
                                @endif
                            </h2>
                            <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #475569;">
                                @if($purpose === 'password_reset')
                                    We received a request to reset your BagooPH account password. Use the single-use 6-digit code below to verify your identity:
                                @else
                                    Thank you for signing up with BagooPH. Please use the single-use 6-digit verification code below to confirm your email address:
                                @endif
                            </p>

                            <!-- OTP Code Box -->
                            <div style="background-color: #0F172A; border-radius: 12px; padding: 24px; text-align: center; margin: 0 0 24px 0;">
                                <span style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; font-size: 36px; font-weight: 800; letter-spacing: 10px; color: #FFFFFF; display: inline-block;">
                                    {{ $otpCode }}
                                </span>
                            </div>

                            <!-- Expiry & Security Notice -->
                            <div style="background-color: #FFF1F2; border-left: 4px solid #E00D42; padding: 12px 16px; border-radius: 4px; margin: 0 0 24px 0;">
                                <p style="margin: 0; font-size: 12px; line-height: 1.5; color: #9F1239; font-weight: 600;">
                                    This verification code expires in 10 minutes.
                                </p>
                                <p style="margin: 4px 0 0 0; font-size: 11px; line-height: 1.5; color: #BE123C;">
                                    If you did not make this request, please ignore this email. Do not share this code with anyone. BagooPH support staff will never ask for your verification code.
                                </p>
                            </div>

                            <p style="margin: 0; font-size: 12px; color: #94A3B8; line-height: 1.5;">
                                This is an automated message from the BagooPH Security System.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 32px; background-color: #F8FAFC; border-top: 1px solid #F1F5F9; text-align: center;">
                            <p style="margin: 0; font-size: 11px; color: #94A3B8; font-family: monospace;">
                                &copy; {{ date('Y') }} BagooPH Logistics & Artisan Marketplace. All rights reserved.
                            </p>
                            <p style="margin: 4px 0 0 0; font-size: 11px; color: #94A3B8;">
                                support@bagooph.shop &bull; Manila, Philippines
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
