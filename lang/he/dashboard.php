<?php

return [
    'customers' => 'לקוחות',
    'active_subscriptions' => 'מנויים פעילים',
    'charges_30d' => 'חיובים מוצלחים (30 ימים)',

    // KPI cards
    'processed_revenue' => 'הכנסות שנגבו (30 ימים)',
    'charges_count' => ':count חיובים',
    'active_subscribers' => 'מנויים פעילים',
    'paused_count' => ':count מושהים',
    'new_subscribers' => 'מנויים חדשים (30 ימים)',
    'churned_subscribers' => 'נטישות (30 ימים)',
    'failed_charges' => ':count חיובים נכשלו',
    'vs_previous' => 'מול התקופה הקודמת',

    // Upcoming
    'upcoming_heading' => 'חיובים קרובים',
    'overdue' => 'באיחור',
    'due_today' => 'היום',
    'next_7_days' => '7 הימים הקרובים',
    'next_30_days' => '30 הימים הקרובים',
    'charges_pending' => ':count חיובים',
    'blocked_card' => ':count חסומים (דרוש כרטיס)',
    'include_blocked' => 'כולל ממתינים לעדכון כרטיס',
    'scope_billable' => 'ניתנים לחיוב (יש כרטיס PayMe)',
    'scope_all' => 'כל המנויים הפעילים (כולל ממתינים לכרטיס)',
    'active_subscribers_billable' => 'מנויים ניתנים לחיוב',
    'active_subscribers_all' => 'כל המנויים הפעילים',
    'include_blocked_note' => 'מוצג הפוטנציאל: כולל :count מנויים פעילים שממתינים לעדכון כרטיס. הסכומים האלה לא ייגבו עד שהלקוחות יזינו כרטיס.',
    'blocked_amount' => ':count ללא סכום',

    // Upcoming orders table
    'upcoming_orders' => 'ההזמנות הקרובות',
    'charge_date' => 'תאריך חיוב',
    'charge_day' => 'הזמנות ליום מסוים',
    'amount' => 'סכום',
    'amount_missing' => 'לא ידוע',
    'total' => 'סה"כ',
    'open' => 'פתח',
    'overdue_by' => 'באיחור :days ימים',
    'no_upcoming' => 'אין חיובים קרובים',
    'no_upcoming_help' => 'מנוי ייכנס לכאן כשהוא פעיל, עם אמצעי תשלום ועם סכום ידוע.',

    // System status
    'health_heading' => 'מצב המערכת',
    'health_description' => 'מה באמת רץ — לא מה שאמור לרוץ.',
    'health_all_ok' => 'הכל תקין',
    'health_attention' => 'דרושה התייחסות',
    'health_configured' => 'מוגדר',
    'health_not_configured' => 'לא מוגדר',

    'health_billing' => 'חיוב חוזר (CRON)',
    'health_billing_ran' => 'רץ :when',
    'health_billing_at' => 'ריצה אחרונה: :time',
    'health_billing_never' => 'מעולם לא רץ',
    'health_billing_never_help' => 'ה-scheduler לא פועל. צור ב-Railway שירות עם PROCESS=scheduler — בלעדיו אף לקוח לא יחויב.',
    'health_billing_off' => 'החיובים מושבתים',

    'health_worker' => 'מבצע החיובים (תור)',
    'health_worker_ok' => 'התור מתרוקן כרגיל (:count ממתינים)',
    'health_worker_stuck' => ':count משימות ממתינות בתור יותר מ-10 דקות',
    'health_worker_stuck_help' => 'אם המספר יורד ברענון — ה-worker מדביק צבר וההתראה תיעלם לבד. אם הוא לא זז, ה-worker מת ואף לקוח לא מחויב: ודא שב-Railway קיים שירות PROCESS=worker, שהוא רץ, ושמדיניות ההפעלה היא ALWAYS.',
    'health_worker_failed' => ':count חיובים נכשלו ב-24 השעות האחרונות',
    'health_worker_failed_help' => 'החיובים נוסו וזרקו שגיאה. בדוק את טבלת failed_jobs לפני שהם ינוסו שוב.',
    'health_worker_failed_other' => ':count משימות רקע נכשלו ב-24 השעות האחרונות (לא חיובים)',
    'health_worker_failed_other_help' => 'סנכרון מול Shopify, מיילים או webhooks — לא חיוב, ושום כסף לא נתקע. בטבלת failed_jobs עמודת queue אומרת מה נפל.',

    'health_behind' => 'מנויים שנעצרו',
    'health_behind_ok' => 'אין — כל המנויים בלוח הזמנים',
    'health_behind_count' => ':count מנויים בפיגור של יותר ממחזור',
    'health_behind_help' => 'לא מחויבים בכוונה — חיוב אוטומטי היה גובה את כל המחזורים שהוחמצו ברצף. פתח כל מנוי והזז את תאריך החיוב הבא קדימה, או בטל אותו.',

    'health_payments' => 'חיובים תקועים',
    'health_payments_ok' => 'אין חיובים ללא תשובה',
    'health_payments_stuck' => ':count חיובים ללא תשובה מ-PayMe',
    'health_payments_stuck_help' => 'כסף במצב לא ידוע — המנוי חסום לחיוב עד לבירור. הרץ mills:reconcile-payments.',

    'health_shopify' => 'חיבור Shopify',
    'health_shopify_off' => 'לא מחובר',
    'health_shopify_off_help' => 'הגדרות ← "חבר מחדש את Shopify". בלי זה אין סנכרון מוצרים ואין יצירת הזמנות.',

    'health_payme' => 'סליקה (PayMe)',
    'health_payme_help' => 'הגדרות ← PayMe. בלי זה אי אפשר לגבות כסף.',

    'health_sms' => 'SMS (019)',
    'health_sms_help' => 'הגדרות ← SMS. בלי זה לא יישלח קוד אימות להתחברות.',

    'health_recent_runs' => 'ריצות אחרונות',
    'health_last_run' => 'ריצה אחרונה',
    'health_no_runs' => 'לא נרשמה אף ריצה — ה-scheduler לא פועל.',

    'cardcom_heading' => 'ממתינים להסרה מ-Cardcom',
    'cardcom_description' => 'הלקוחות האלה שמרו כרטיס ועברו להיות מחויבים אצלנו — אבל החיוב הישן שלהם ב-Cardcom מוסר רק ידנית, ועד אז הם מחויבים פעמיים. אשר כל אחד רק אחרי שהסרת אותו בפועל ב-Cardcom.',
    'cardcom_confirm' => 'הוסר מ-Cardcom',
    'cardcom_confirmed' => 'נרשם. הלקוח ירד מרשימת החיוב הכפול.',
];
