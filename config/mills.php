<?php

return [
    /*
     | System logging (system_logs). The clear, simple operation log written via
     | App\Models\SystemLog. Rows older than retention_days are deleted daily by
     | the logs:prune command (scheduled in routes/console.php).
     */
    'logging' => [
        'retention_days' => (int) env('LOG_RETENTION_DAYS', 60),
    ],
];
