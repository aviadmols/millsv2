@php
    /** @var bool $sessionAlive */
    /** @var string $sessionId */
    /** @var ?int $subscriptionId */
    /** @var array{api_key:string, test_mode:bool} $hostedFields */
    /** @var string $submitUrl */
    /** @var ?\App\Models\Customer $customer */
    $apiKey = (string) ($hostedFields['api_key'] ?? '');
    $testMode = (bool) ($hostedFields['test_mode'] ?? false);
    $configured = $sessionAlive && $apiKey !== '';
@endphp
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<title>עדכון כרטיס אשראי</title>
<meta name="referrer" content="no-referrer">
<meta name="viewport" content="width=device-width,initial-scale=1">
{{-- The v1 card-update page, ported verbatim (dist/mills-admin payme-form.blade.php):
     same fields, same layout, same SDK bootstrapping. The card inputs are PayMe-hosted
     iframes — card data never enters this app. No sale is generated and nothing is
     charged: the browser tokenises the card and POSTs only the token back to us. --}}
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    html, body { direction: rtl; }
    body { font-family: Heebo, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; padding: 24px; background: #ffffff; color: #111827; }
    .wrap { max-width: 880px; margin: 0 auto; }
    .err-box { padding: 16px; border-radius: 12px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 14px; }
    label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin: 14px 0 6px; }
    input[type=text], input[type=email], input[type=tel] { width: 100%; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 15px; background: #fff; color: #111827; }
    input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.18); }
    .field { width: 100%; height: 46px; min-height: 46px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 10px; background: #fff; overflow: hidden; }
    .field iframe { display: block; width: 100% !important; height: 100% !important; min-height: 0 !important; border: 0 !important; }
    .field.is-focused { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.18); }
    .field.is-invalid { border-color: #dc2626; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    button { width: 100%; padding: 14px 20px; border: 0; border-radius: 12px; background: #111827; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 24px; }
    button:disabled { background: #9ca3af; cursor: not-allowed; }
    button:hover:not(:disabled) { background: #1f2937; }
    .err { color: #b91c1c; font-size: 13px; margin-top: 10px; min-height: 18px; }
    .ok { color: #047857; font-size: 14px; margin-top: 12px; font-weight: 600; }
    .is-hidden { display: none !important; }
    iframe[type="primary"] { width: 1px !important; height: 1px !important; min-height: 0 !important; }
    @media (max-width: 640px) {
        body { padding: 16px; }
        .row { grid-template-columns: 1fr; gap: 0; }
    }
</style>
</head>
<body>
<div class="wrap">
    @if (! $sessionAlive)
        <div class="err-box">
            <strong>פג תוקף הקישור.</strong>
            <p style="margin: 6px 0 0;">יש להתחיל את עדכון אמצעי התשלום מחדש מהאזור האישי, או לבקש קישור חדש.</p>
        </div>
    @elseif ($apiKey === '')
        <div class="err-box">
            <strong>PayMe Hosted Fields לא מוגדר.</strong>
            <p style="margin: 6px 0 0;">יש להזין את מפתח ה-Hosted Fields בעמוד ההגדרות של מערכת הניהול.</p>
        </div>
    @else
        <form id="payme-form" autocomplete="off" novalidate>
            <div id="payme-form-fields">
                <div class="row">
                    <div>
                        <label for="payer-first-name">שם פרטי</label>
                        <input type="text" id="payer-first-name" name="payerFirstName" value="{{ $customer?->first_name }}" required />
                    </div>
                    <div>
                        <label for="payer-last-name">שם משפחה</label>
                        <input type="text" id="payer-last-name" name="payerLastName" value="{{ $customer?->last_name }}" required />
                    </div>
                </div>

                <div class="row">
                    <div>
                        <label for="payer-email">אימייל</label>
                        <input type="email" id="payer-email" name="payerEmail" value="{{ $customer?->email }}" required />
                    </div>
                    <div>
                        <label for="payer-phone">טלפון</label>
                        <input type="tel" id="payer-phone" name="payerPhone" value="{{ $customer?->phone }}" required />
                    </div>
                </div>

                <label for="payer-social-id">מספר תעודת זהות</label>
                <input type="text" id="payer-social-id" name="payerSocialId" inputmode="numeric" required />

                <label for="card-number">מספר כרטיס</label>
                <div id="card-number" class="field"></div>

                <div class="row">
                    <div>
                        <label for="card-expiration">תוקף (MM/YY)</label>
                        <div id="card-expiration" class="field"></div>
                    </div>
                    <div>
                        <label for="card-cvc">CVC</label>
                        <div id="card-cvc" class="field"></div>
                    </div>
                </div>
            </div>

            <div id="form-error" class="err" role="alert"></div>
            <div id="form-success" class="ok" role="status"></div>

            <button type="submit" id="submit-btn" disabled>טוען...</button>
        </form>
    @endif
</div>

@if ($configured)
    <script src="https://cdn.payme.io/hf/v1/hostedfields.js"></script>
    <script>
        (function () {
            var API_KEY = @json($apiKey);
            var TEST_MODE = @json($testMode);
            var SESSION_ID = @json($sessionId);
            var SUBSCRIPTION_ID = @json($subscriptionId);
            var SUBMIT_URL = @json($submitUrl);

            var btn = document.getElementById('submit-btn');
            var form = document.getElementById('payme-form');
            var fieldsWrap = document.getElementById('payme-form-fields');
            var errBox = document.getElementById('form-error');
            var okBox = document.getElementById('form-success');

            function showError(message) { errBox.textContent = message || ''; okBox.textContent = ''; }
            function showOk(message) { okBox.textContent = message || ''; errBox.textContent = ''; }
            function notifyParent(payload) {
                try {
                    if (window.parent && window.parent !== window) {
                        // Both shapes: 'payme-card-updated' is what the v1 admin modal expects,
                        // 'mills.payme.success' is what the theme's dashboard modal listens for.
                        window.parent.postMessage(Object.assign({ type: 'payme-card-updated' }, payload), '*');
                        if (payload && payload.ok) {
                            window.parent.postMessage(Object.assign({ type: 'mills.payme.success' }, payload), '*');
                        }
                    }
                } catch (err) { console.error('[payme-form] postMessage failed', err); }
            }

            if (typeof window.PayMe === 'undefined' || typeof window.PayMe.create !== 'function') {
                showError('טעינת PayMe נכשלה. יש לבדוק את החיבור ולנסות שוב.');
                return;
            }

            // Resolve a possibly-thenable value as a Promise.
            function asPromise(value) {
                return Promise.resolve(value);
            }

            // PayMe Hosted Fields — keep options minimal. Older builds reject any
            // unrecognised options (e.g. `style`) by freezing the options object,
            // which surfaces as "Cannot add property style, object is not extensible".
            var FIELD_NAMES = {
                cardNumber: ['cardNumber', 'cardNumberField'],
                cardExpiration: ['cardExpiration', 'expiryField', 'cardExpirationField'],
                cvc: ['cvc', 'cvcField'],
            };

            function tryCreate(fieldsApi, candidates, options) {
                var lastError = null;
                for (var i = 0; i < candidates.length; i++) {
                    try {
                        var field = fieldsApi.create(candidates[i], options);
                        if (field) { return field; }
                    } catch (err) {
                        lastError = err;
                    }
                }
                if (lastError) { throw lastError; }
                throw new Error('Could not create field for ' + candidates.join(' / '));
            }

            asPromise(window.PayMe.create(API_KEY, { testMode: !! TEST_MODE }))
                .then(function (instance) {
                    var fieldsApi = (typeof instance.hostedFields === 'function')
                        ? instance.hostedFields()
                        : instance;
                    return asPromise(fieldsApi).then(function (fields) {
                        return { instance: instance, fields: fields };
                    });
                })
                .then(function (ctx) {
                    var fields = ctx.fields;
                    var instance = ctx.instance;

                    var cardNumber = tryCreate(fields, FIELD_NAMES.cardNumber, { placeholder: '4580 0000 0000 0000' });
                    var cardExpiration = tryCreate(fields, FIELD_NAMES.cardExpiration, { placeholder: 'MM/YY' });
                    var cvc = tryCreate(fields, FIELD_NAMES.cvc, { placeholder: '123' });

                    return Promise.all([
                        asPromise(cardNumber.mount('#card-number')).then(function () { return cardNumber; }),
                        asPromise(cardExpiration.mount('#card-expiration')).then(function () { return cardExpiration; }),
                        asPromise(cvc.mount('#card-cvc')).then(function () { return cvc; }),
                    ]).then(function (mounted) {
                        return { instance: instance, fields: fields, cardNumber: mounted[0], cardExpiration: mounted[1], cvc: mounted[2] };
                    });
                })
                .then(function (ctx) {
                    [['#card-number', ctx.cardNumber], ['#card-expiration', ctx.cardExpiration], ['#card-cvc', ctx.cvc]].forEach(function (pair) {
                        var el = document.querySelector(pair[0]);
                        var field = pair[1];
                        if (! field || typeof field.on !== 'function') return;
                        try { field.on('focus', function () { el.classList.add('is-focused'); }); } catch (e) {}
                        try { field.on('blur', function () { el.classList.remove('is-focused'); }); } catch (e) {}
                        try {
                            field.on('change', function (e) {
                                if (e && e.error) { el.classList.add('is-invalid'); }
                                else { el.classList.remove('is-invalid'); }
                            });
                        } catch (e) {}
                    });

                    btn.disabled = false;
                    btn.textContent = 'שמירת כרטיס חדש';

                    form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        showError('');
                        showOk('');
                        btn.disabled = true;
                        btn.textContent = 'שומר...';

                        var fd = new FormData(form);
                        var payerFirstName = (fd.get('payerFirstName') || '').toString().trim();
                        var payerLastName = (fd.get('payerLastName') || '').toString().trim();
                        var payerEmail = (fd.get('payerEmail') || '').toString().trim();
                        var payerPhone = (fd.get('payerPhone') || '').toString().trim();
                        var payerSocialId = (fd.get('payerSocialId') || '').toString().trim();

                        if (! payerFirstName || ! payerLastName || ! payerEmail || ! payerPhone || ! payerSocialId) {
                            showError('יש למלא את כל פרטי המשלם לפני שמירת הכרטיס.');
                            btn.disabled = false;
                            btn.textContent = 'שמירת כרטיס חדש';
                            return;
                        }

                        // PayMe validates amount.value as a decimal-string sum.
                        // A small non-zero validation value avoids the zero-value
                        // "$value must be a correct amount sum" tokenize error.
                        // Tokenisation only — nothing is charged.
                        var saleData = {
                            payerFirstName: payerFirstName,
                            payerLastName: payerLastName,
                            payerEmail: payerEmail,
                            payerPhone: payerPhone,
                            payerSocialId: payerSocialId,
                            total: { label: 'בדיקת כרטיס', amount: { currency: 'ILS', value: '0.10' } },
                        };

                        var tokenizeFn = (typeof ctx.fields.tokenize === 'function')
                            ? ctx.fields.tokenize.bind(ctx.fields)
                            : (typeof ctx.instance.tokenize === 'function' ? ctx.instance.tokenize.bind(ctx.instance) : null);

                        if (! tokenizeFn) {
                            showError('חסרה פונקציית tokenize ב־PayMe. יש לבדוק את גרסת ה־SDK.');
                            btn.disabled = false;
                            btn.textContent = 'שמירת כרטיס חדש';
                            return;
                        }

                        Promise.resolve(tokenizeFn(saleData))
                            .then(function (result) {
                                if (! result || (result.error && ! result.token && ! result.buyer_key && ! result.buyerKey && ! result.payment_id && ! result.paymentId)) {
                                    var msg = (result && result.error && result.error.message) || 'PayMe דחתה את הכרטיס. יש לבדוק את הפרטים ולנסות שוב.';
                                    throw new Error(msg);
                                }
                                var token = result.token || result.buyer_key || result.buyerKey || result.payment_id || result.paymentId || '';
                                var maskedCard = result.card_last4 ? ('•••• ' + result.card_last4) : (result.last4 ? '•••• ' + result.last4 : '');
                                if (! maskedCard && result.card && result.card.cardMask) { maskedCard = result.card.cardMask; }
                                if (! token) { throw new Error('PayMe לא החזירה טוקן תשלום. יש לנסות שוב.'); }

                                return fetch(SUBMIT_URL, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    body: JSON.stringify({
                                        session_id: SESSION_ID,
                                        token: token,
                                        masked_card: maskedCard,
                                    }),
                                }).then(function (resp) {
                                    return resp.json().then(function (json) { return { status: resp.status, body: json }; });
                                }).then(function (env) {
                                    if (! env.body || env.body.ok !== true) {
                                        throw new Error((env.body && env.body.message) || 'שמירת הכרטיס החדש נכשלה.');
                                    }
                                    if (fieldsWrap) { fieldsWrap.classList.add('is-hidden'); }
                                    showOk('הכרטיס נשמר בהצלחה. החיוב הבא יתבצע מהכרטיס החדש.');
                                    btn.classList.add('is-hidden');
                                    btn.textContent = 'נשמר';
                                    notifyParent({
                                        ok: true,
                                        subscriptionId: env.body.subscription_id || SUBSCRIPTION_ID,
                                        message: env.body.message || 'אמצעי התשלום עודכן.',
                                    });
                                });
                            })
                            .catch(function (err) {
                                console.error('[payme-form] tokenize failed', err);
                                showError((err && err.message) || 'שמירת הכרטיס נכשלה.');
                                btn.disabled = false;
                                btn.textContent = 'שמירת כרטיס חדש';
                                notifyParent({
                                    ok: false,
                                    subscriptionId: SUBSCRIPTION_ID,
                                    message: (err && err.message) || 'שמירת הכרטיס נכשלה.',
                                });
                            });
                    });
                })
                .catch(function (err) {
                    console.error('[payme-form] PayMe.create failed', err);
                    showError('אתחול שדות PayMe נכשל: ' + ((err && err.message) || err));
                });
        })();
    </script>
@endif
</body>
</html>
