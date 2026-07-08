# Mills v2 — תוכנית העבודה המלאה / Rebuild Plan

> **מה בונים, במשפט אחד:** אפליקציית שופיפיי אמיתית (Custom App) בפרויקט נפרד, על הארכיטקטורה
> של PayPlus Subscriptions, שבה ה־DB שלנו הוא מקור האמת היחיד — עם חיוב חוזר אמין, כניסה ב־OTP,
> עברית/אנגלית, והזמנות שנכנסות לשופיפיי תחת ה־Channel של האפליקציה.

Inputs: `docs/SYSTEM-MAP.md` (v1 map + **the frozen HTTP contract §3**), the RECHARGE blueprint
(`Projects\תוסף RECHAREG לPAYPLUS` — implemented: Ledger, IdempotencyKey, HasGuardedStatus,
scheduler, OAuth app layer, product sync, email engine, lang/en+he), and the production engine
(`Projects\פייפלוס חשבונית` — edge-case oracle + the working **Sales Channel extension**).

---

## 1. החלטות סגורות / Locked decisions

> **בעברית:** כל השאלות הפתוחות נסגרו. אין יותר "נחליט אחר כך" על הדברים האלה.

| # | החלטה | Decision |
|---|---|---|
| D1 | פרויקט נפרד, ריפו נפרד, Railway נפרד; v1 חי וללא שינוי עד המעבר | Separate repo + Railway env; v1 untouched during the build |
| D2 | Laravel 12 + Filament + Postgres + Redis | Same stack as v1/RECHARGE |
| D3 | מבנה מודול של RECHARGE בלי multi-tenancy | `app/Domain/Billing` + `app/Modules/MillsSubscriptions`, no `shop_id` |
| D4 | ליבת כסף מ־RECHARGE: ledger לפני חיוב, מפתחות idempotency, מכונת מצבים שמורה, retry \u200F[4,24,72] שעות | Money core ported from RECHARGE |
| D5 | PayMe מאחורי חוזה gateway אחיד | `PayMeGateway::chargeWithReference(...)`, internals from v1 (they work) |
| D6 | כרטיס אחד ללקוח בטבלת `payment_methods` (buyer_key מוצפן) | Replaces v1's BillingLog-mining |
| D7 | חוזה ה־API קפוא — אותם נתיבים, אותם שמות שדות, אותם סטטוסים (`active/pending/disable`) | The theme never notices the swap |
| D8 | ה־OTP מנפיק את אותו פורמט טוקן של v1 | Existing storefront endpoints work unchanged |
| D9 | שלושה שירותי Railway: web / worker / scheduler | No backgrounded scheduler children |
| D10 | **חיבור לשופיפיי כאפליקציה אמיתית** — OAuth + טוקן offline מוצפן + הרחבת Sales Channel | Partner-Dashboard app (custom distribution), like RECHARGE |
| D11 | Metaobjects של v1 מוקפאים במעבר, לא נמחקים | Rollback safety |
| D12 | **SMTP מנוהל מהפאנל** — מסך הגדרות + כפתור בדיקת שליחה, סיסמה מוצפנת | Admin-managed mail settings |
| D13 | **SMS = \u200F019** — ממשק `SmsSender` עכשיו, adapter כשיהיו פרטים | Email (SMTP) first |
| D14 | **עדכון כתובת נדחף חזרה לשופיפיי** — ה־DB שלנו הבעלים, שופיפיי מקבל עדכון (למשלוחים) | Compensating push, never blocks |
| D15 | **ביטול מנוי תמיד מיידי** | No end-of-period anywhere |
| D16 | **מוצרים + מדיה נמשכים ל־cache מקומי** (כולל תמונות) — אף נתיב חם לא קורא לשופיפיי חי | products/product_variants incl. image CDN URLs |
| D17 | **הזמנות נכנסות תחת ה־Channel של האפליקציה** — הרחבת Sales Channel בשם `mills-subscriptions` + `source_name` תואם | Engine precedent (RECHARGE's source_name alone is NOT enough) |

## 2. מה נשאר בשופיפיי / מה עובר אלינו

> **בעברית:** הטבלה שחשוב להכיר בעל־פה. העמודה הימנית נעלמת מהעולם אחרי הייבוא.

| נשאר בשופיפיי (דרך האפליקציה) | עובר ל־DB שלנו (מקור אמת יחיד) | נמחק לגמרי |
|---|---|---|
| מוצרים, וריאנטים, תמונות (מקור; אצלנו cache) | לקוחות + כתובות (עם דחיפה חזרה) | Metaobjects: `customer_subscriptions`, `dog`, `dog_quiz` |
| Checkout והזמנות (תחת Channel של האפליקציה) | מנויים, כלבים, טעמים, תוספים | Metafields: `custom.my_dogs`, `custom.customer_subscriptions` |
| Webhooks (orders, products, uninstall) | אמצעי תשלום (buyer_key מוצפן) | ה־JSON בשדה note של לקוחות iCount (אחרי ייבוא) |
| טיוטת "ההזמנה הבאה" (תצוגה מקדימה בלבד) | Ledger חיובים, Timeline, OTP, הגדרות מייל | כל מנגנון הסנכרון של v1 (`app/Snapshots`, פקודות Sync) |

## 3. אבני היסוד הארכיטקטוניות

> **בעברית:** חמשת העקרונות שהופכים את המערכת לאמינה. מפורטים במלואם ב־`ARCHITECTURE.md`.

1. **Ledger לפני חיוב** — שורת `pending` נכתבת לפני כל קריאה ל־PayMe, בתוך טרנזקציה עם נעילה.
   שורת `succeeded` על אותו מפתח = לעולם לא מחייבים שוב (חומת idempotency ב־4 שכבות).
2. **מכונות מצבים שמורות** — שינוי סטטוס רק דרך `transitionTo()`; כל מעבר נרשם ב־Timeline.
   ביטול = מיידי (D15).
3. **Scheduler אמיתי** — שירות ייעודי, בחירת חיובים בחלון (`next_charge_at <= now`) עם השלמה
   אוטומטית של פספוסים, retry \u200F[4,24,72] שעות, heartbeat + דף Observability. בלי מתג cache
   (הלקח מ־v1); כיבוי רק ב־`BILLING_KILL_SWITCH` מפורש.
4. **אפליקציית שופיפיי אמיתית** — OAuth install → טוקן offline מוצפן; webhooks נרשמים אוטומטית;
   הזמנות חיוב חוזר נוצרות ב־`orderCreate` עם טרנזקציית sale פנימית (`gateway:'manual',
   source:'external'` — הכסף כבר עבר ב־PayMe) ו־`source_name = 'mills-subscriptions'` ⇒
   מופיעות תחת **Channel של האפליקציה**. טיוטה נשמרת רק כתצוגת "ההזמנה הבאה" (חוזה `/me`).
5. **Cache מוצרים ומדיה** — `products` + `product_variants` עם `image_url` (CDN של שופיפיי),
   מתעדכן ב־webhooks + ריענון לילי + כפתור ידני. השאלון, הפאנל וה־`/me` קוראים רק מה־cache.

## 4. חומת ה־iCount ועדכון כרטיס

> **בעברית:** לקוח ותיק (iCount) שנכנס לאזור האישי מקבל את דרישת עדכון הכרטיס — בדיוק כמו היום.
> אחרי עדכון מוצלח: נוצרת שורת `payment_methods`, **כל** המנויים שלו עוברים ל־payme, והוא נכנס
> כמנוי מלא. באג ה"נשאר תקוע" של v1 נעלם מהמבנה עצמו (כרטיס אחד ללקוח, לא לפי מנוי).

## 5. OTP — כניסה לאזור האישי

> **בעברית:** שלב ראשון מייל (SMTP מהפאנל), שלב שני SMS דרך 019. הטוקן שמונפק זהה לפורמט של
> היום — האתר לא מרגיש. הטוקן מה־Liquid ממשיך לעבוד במקביל עד שנחליט לכבות.

`POST /storefront/auth/otp/request {email}` → קוד 6 ספרות (hash, \u200F10 דק', rate-limit) →
`POST /storefront/auth/otp/verify` → טוקן storefront סטנדרטי. ערוץ `sms` (019) מאחורי חוזה
`SmsSender` — נבנה כשיגיעו פרטי החשבון (D13).

## 6. פאנל הניהול (Filament, עיצוב tabuzzco מ־v1)

> **בעברית:** ניהול ברור מהיום הראשון — לקוחות, מנויים, חיובים, מוצרים, והגדרות מייל.

- **Customers** — רשימה + כרטיס לקוח: כלבים, מנויים, אמצעי תשלום, Timeline, כפתור preview.
- **Subscriptions** — רשימה + פעולות סטטוס שמורות, "חייב עכשיו", עריכת מועד חיוב הבא.
- **PaymentLedger** — היסטוריית כסף לקריאה בלבד. **Dogs**, **Products** (עם תמונות מה־cache).
- **Pages:** Dashboard, Observability (בריאות scheduler/queue/חיובים), **Mail settings**
  (עורך תבניות + **הגדרות SMTP + בדיקת שליחה** — D12), Shopify connection (סטטוס OAuth,
  webhooks, כפתור ריענון מוצרים), App settings.
- הכול `__()` עם מראה he/en מלאה.

## 7. ייבוא (חד-פעמי, idempotent, ניתן להרצה חוזרת)

> **בעברית:** שישה צעדים, כולם `--dry-run` כברירת מחדל, עם דוח אימות בסוף.

catalog-check → import-customers → import-subscriptions (כולל משיכה חיה של הטעמים
`selected_variants`/`addons_products` שחסרים במראה של v1!) → import-legacy-notes
(iCount → מנויים במצב `needs_card_update`) → import-billing-history (billing_logs →
payment_ledger; שורות עדכון-כרטיס → payment_methods) → **import-verify** (השוואת ספירות,
דוח יתומים, דגימת `/me` מול `last_me_payload` של v1).

## 8. שלבי הביצוע / Phases

> **בעברית:** כל שלב עם רשימת מסירה ושער מעבר. לא מדלגים על שערים.

### Phase 0 — ייצוב v1 (עכשיו, בלי קוד)
- [ ] `/admin/billing-scheduler`: heartbeat ירוק? "Enable scheduled ticks" דלוק?
- [ ] הרצת "Run billing now" ובדיקת התוצאות ב־billing_logs
- **שער:** חיובים זורמים ב־v1 בזמן שבונים את v2.

### Phase 1 — שלד + אפליקציית שופיפיי
- [ ] Laravel + Filament + מבנה המודול + CI (pint, phpunit)
- [ ] Railway: web / worker / scheduler + Postgres + Redis
- [ ] **יצירת האפליקציה ב־Partner Dashboard** (custom distribution) — צעד ידני מודרך של אביעד
- [ ] OAuth install/callback → טוקן מוצפן ב־`shopify_connection`
- [ ] רישום webhooks אוטומטי + middleware אימות fail-closed
- [ ] **פריסת הרחבת ה־Sales Channel** (`mills-subscriptions`) ב־`shopify app deploy`
- **שער:** האפליקציה מותקנת, webhooks נרשמו, והזמנת בדיקה מופיעה תחת ה־Channel שלה.

### Phase 2 — ליבת הכסף
- [ ] סכימה מלאה + מודלים + מכונות מצבים + Ledger + IdempotencyKey + PayMeGateway
- [ ] בדיקות: חיוב כפול נחסם, מעבר לא-חוקי זורק, ledger-לפני-gateway
- **שער:** בדיקות הכסף ירוקות.

### Phase 3 — Cache מוצרים + ייבוא
- [ ] ProductSyncService: backfill, webhooks, ריענון לילי, כפתור ידני; תמונות נשמרות
- [ ] צינור הייבוא המלא (§7) מול שופיפיי production (קריאה בלבד)
- **שער:** `import-verify` נקי — ספירות תואמות, אפס יתומים.

### Phase 4 — זהות Endpoints
- [ ] כל 4 המשטחים (api / legacy / storefront / payme-web) מוגשים מה־DB
- [ ] בדיקות חוזה לפי SYSTEM-MAP §3 + harness השוואת `/me` מול v1
- **שער:** אפס הבדלים בדגימה; תבנית staging עובדת מול v2.

### Phase 5 — מנוע החיוב
- [ ] dispatch-due כל 5 דק' + ChargeJob + Orchestrator + backoff
- [ ] **הזמנות ב־`orderCreate` + טרנזקציה פנימית + שיוך Channel** (D17); טיוטת preview לסייקל הבא
- [ ] עדכון כרטיס → payment_methods → הפעלת כל מנויי הלקוח
- [ ] Observability + heartbeats
- **שער:** מנוי בדיקה עובר סייקל מלא (חיוב → הזמנה תחת ה־Channel → קידום מועד → טיוטה חדשה); מסלול retry מוכח.

### Phase 6 — OTP + פאנל
- [ ] OTP מייל (SMTP מהפאנל — D12) + מסך התחברות בתבנית
- [ ] ממשק `SmsSender` \u200F(019 — D13)
- [ ] כל משאבי הפאנל (§6) כולל Mail settings + test-send
- [ ] דחיפת כתובת לשופיפיי (D14)
- [ ] ביטול מיידי בכל המסלולים (D15)
- **שער:** התחברות OTP מהתבנית עובדת; ניהול מלא בפאנל.

### Phase 7 — i18n + ליטוש
- [ ] מראה he/en מלאה, בדיקות RTL, תבניות מייל בעברית
- **שער:** אפס מפתחות חסרים ב־HE.

### Phase 8 — מעבר (Cutover)
- [ ] ייבוא דלתא → הקפאת כתיבות v1 → דלתא אחרונה → החלפת `data-mills-api-base` בתבנית
  (אותם secrets) → ניטור 48 שעות → v1 לקריאה בלבד
- **חזרה לאחור:** החלפת ה־URL חזרה. v1 לא נגוע; Metaobjects מוקפאים ולא נמחקים (D11).

## 9. סיכונים ומענים

1. **קוראי-endpoint נסתרים** → הרצת צל + diff על access-log של v1.
2. **סחף נתונים בזמן הבנייה** → ייבוא דלתא לפי provenance במעבר.
3. **סטיית חיוב PayMe** → gateway מועתק אחד-לאחד + kill switch + ניטור פר-חיוב בסייקלים הראשונים.
4. **חשיפת API_SECRET בשאלון (ירושה מ־v1)** → נשמר בינתיים לחוזה; Phase 7 מוסיף טוקן ייעודי
   לשאלון והתבנית תעבור בהמשך.
5. **נעילת OTP** → מסלול הטוקן מה־Liquid נשאר תקף עד ש־OTP מוכח.
6. **הרחבת ה־Channel דורשת אפליקציית Partner Dashboard** → צעד ידני חד-פעמי של אביעד, עם
   הוראות מודרכות (ARCHITECTURE §10).

## 10. צעדים ידניים של אביעד (מודרכים, חד-פעמיים)

1. יצירת האפליקציה ב־Partner Dashboard → \u200F`SHOPIFY_API_KEY/SECRET`.
2. התקנה דרך `/shopify/install`.
3. אישור `shopify app deploy` להרחבת ה־Channel.
4. הזנת פרטי SMTP בפאנל (Mail settings → test-send).
5. פרטי 019 כשמתחילים SMS.
