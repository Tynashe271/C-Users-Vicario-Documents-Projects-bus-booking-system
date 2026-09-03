<?php

return [
    'sms' => ['url' => env('SMS_GATEWAY_URL'), 'token' => env('SMS_GATEWAY_TOKEN')],
    'whatsapp' => ['url' => env('WHATSAPP_GATEWAY_URL'), 'token' => env('WHATSAPP_GATEWAY_TOKEN')],
    'push' => ['url' => env('PUSH_GATEWAY_URL'), 'token' => env('PUSH_GATEWAY_TOKEN')],
];
