<?php

return [
    'api_key' => env('AI_API_KEY'),
    'base_url' => rtrim(env('AI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
    'model' => env('AI_TEXT_MODEL', 'gemini-3.5-flash-lite'),
    'timeout' => (int) env('AI_TIMEOUT_SECONDS', 20),
];
