<?php

return [
    // Shared secret guarding /api/* and the legacy NestJS-compat surface
    // (frozen v1 contract). Sent by the theme as X-API-Secret / Bearer.
    'secret' => env('API_SECRET'),
];
