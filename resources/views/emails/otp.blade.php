{{-- Platform-authored OTP email. Inline CSS is allowed in email bodies only. --}}
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f5f5f3;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:480px;margin:32px auto;background:#ffffff;border-radius:7px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);">
    <div style="padding:28px 28px 8px;">
      <p style="margin:0 0 6px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#6b7280;">Mills</p>
      <h1 style="margin:0;font-size:20px;font-weight:700;color:#111827;">{{ __('otp.email.heading') }}</h1>
    </div>
    <div style="padding:8px 28px 24px;">
      <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#374151;">{{ __('otp.email.intro') }}</p>
      <div style="text-align:center;margin:8px 0 16px;">
        <span style="display:inline-block;font-size:32px;font-weight:900;letter-spacing:8px;color:#111827;background:#f4efe7;border-radius:7px;padding:14px 22px;">{{ $code }}</span>
      </div>
      <p style="margin:0;font-size:12px;color:#9ca3af;">{{ __('otp.email.expiry', ['minutes' => $ttlMinutes]) }}</p>
    </div>
  </div>
</body>
</html>
