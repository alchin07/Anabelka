<?php

return [
    'default_provider' => 'openai',
    'providers' => [
        'openai' => [
            'api_key' => 'PASTE_OPENAI_API_KEY_HERE',
            'model' => 'gpt-5.6-luna'
        ],
        'gemini' => [
            'api_key' => 'PASTE_GEMINI_API_KEY_HERE',
            'model' => 'gemini-3.7-flash'
        ],
        'deepl' => [
            'api_key' => 'PASTE_DEEPL_API_KEY_HERE',
            'endpoint' => 'https://api.deepl.com/v2/translate'
        ]
    ]
];
