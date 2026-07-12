<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>עדכון אמצעי תשלום</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif; background: #fafafa;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 12px; padding: 40px 32px; text-align: center;
                box-shadow: 0 2px 16px rgba(0,0,0,.08); max-width: 420px; }
        .icon { font-size: 44px; line-height: 1; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 8px; color: #111; }
        p { color: #666; margin: 0; font-size: 15px; }
        .ok { color: #0a7c42; }
        .err { color: #c0392b; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon {{ $ok ? 'ok' : 'err' }}">{{ $ok ? '✓' : '✕' }}</div>
        <h1 class="{{ $ok ? 'ok' : 'err' }}">{{ $ok ? 'עודכן בהצלחה' : 'העדכון נכשל' }}</h1>
        <p>{{ $message }}</p>
    </div>

    <script>
        // Tell the opener (the theme's personal area) how it went, then close.
        try {
            var payload = { type: 'mills:card-update', ok: @json($ok) };
            if (window.opener) { window.opener.postMessage(payload, '*'); }
            if (window.parent && window.parent !== window) { window.parent.postMessage(payload, '*'); }
        } catch (e) {}

        @if ($ok)
            setTimeout(function () { if (window.opener) { window.close(); } }, 2500);
        @endif
    </script>
</body>
</html>
