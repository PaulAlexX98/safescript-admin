<?php

return [
    'enabled' => (bool) env('ADMIN_PERFORMANCE_LOGGING', false),
    'slow_query_ms' => (float) env('ADMIN_SLOW_QUERY_MS', 200),
];
