<?php

return [
    'title' => 'תנועות תשלום',
    'singular' => 'תנועת תשלום',

    'subscription' => 'מנוי',
    'customer' => 'לקוח',
    'payment_method' => 'אמצעי תשלום',
    'context' => 'סוג',
    'idempotency_key' => 'מפתח ייחודי',
    'status' => 'סטטוס',
    'amount' => 'סכום',
    'currency' => 'מטבע',
    'payme_transaction_id' => 'מזהה עסקה ב-PayMe',
    'shopify_order_id' => 'הזמנה בשופיפיי',
    'draft_order_id' => 'טיוטת הזמנה',
    'failure_code' => 'קוד כשל',
    'failure_message' => 'הודעת כשל',
    'raw_response' => 'תשובת הסולק (ממוסכת)',
    'executed_at' => 'בוצע',
    'created_at' => 'נוצר',
    'updated_at' => 'עודכן',

    'refund' => 'החזר ללקוח',
    'refund_heading' => 'החזר כספי ללקוח',
    'refund_help' => 'ההחזר מתבצע ב-PayMe — שם הכסף באמת נמצא. החזר בשופיפיי לבד לא מחזיר ללקוח כלום. נותר להחזר מהחיוב הזה: :amount. הפעולה תעדכן את יומן התשלומים ותסמן גם את ההזמנה בשופיפיי כמוחזרת.',
    'refund_submit' => 'בצע החזר ב-PayMe',
    'refund_amount' => 'סכום להחזר',
    'refund_amount_help' => 'ברירת המחדל היא כל היתרה. אפשר להקטין להחזר חלקי.',
    'refund_reason' => 'סיבה (אופציונלי)',
    'refund_reason_help' => 'תופיע בטיים-ליין של המנוי ובהערת ההחזר בשופיפיי.',
    'refund_done' => ':amount הוחזרו ללקוח. ההחזר בוצע ב-PayMe, נרשם ביומן וסומן בשופיפיי.',
    'refund_done_part' => ':amount הוחזרו ללקוח (החזר חלקי). היתרה עדיין ניתנת להחזר.',
    'refund_failed' => 'ההחזר לא בוצע',

    // Reasons a refund cannot be issued — each names the actual obstacle.
    'refund_not_succeeded' => 'אפשר להחזיר רק חיוב שהצליח. חיוב ממתין או כושל לא גבה כלום, וחיוב שהוחזר כבר הוחזר.',
    'refund_not_a_charge' => 'השורה הזו אינה חיוב של מנוי (למשל אימות כרטיס) ואין מה להחזיר ממנה.',
    'refund_no_sale_id' => 'אין לשורה הזו מזהה עסקה של PayMe, ולכן לא ניתן להצביע ל-PayMe על מה להחזיר. בצע את ההחזר ישירות בממשק PayMe.',
    'refund_amount_out_of_range' => 'הסכום חייב להיות בין אגורה אחת ליתרה שטרם הוחזרה.',
    'refund_payme_refused' => 'PayMe סירב לבצע את ההחזר. הפרטים נרשמו ביומן המערכת — בדוק שם, ואם צריך בצע את ההחזר ידנית בממשק PayMe.',
];
