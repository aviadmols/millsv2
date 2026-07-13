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
    'blocked_amount' => ':count ללא סכום',

    // Upcoming orders table
    'upcoming_orders' => 'ההזמנות הקרובות',
    'charge_date' => 'תאריך חיוב',
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
    'health_no_runs' => 'לא נרשמה אף ריצה — ה-scheduler לא פועל.',
];
