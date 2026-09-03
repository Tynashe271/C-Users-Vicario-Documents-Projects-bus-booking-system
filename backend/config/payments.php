<?php

$remote = static fn (string $prefix): array => ['initiate_url' => env("{$prefix}_INITIATE_URL"), 'refund_url' => env("{$prefix}_REFUND_URL"), 'api_key' => env("{$prefix}_API_KEY"), 'webhook_secret' => env("{$prefix}_WEBHOOK_SECRET")];

return ['providers' => [
    'demo' => ['demo' => true],
    'ecocash' => $remote('ECOCASH'), 'onemoney' => $remote('ONEMONEY'), 'innbucks' => $remote('INNBUCKS'), 'paynow' => $remote('PAYNOW'),
    'visa' => $remote('CARD'), 'mastercard' => $remote('CARD'), 'bank_transfer' => ['instructions' => env('BANK_TRANSFER_INSTRUCTIONS')],
    'pos' => ['instructions' => 'Pay at the selected point of sale.'], 'cash_branch' => ['instructions' => 'Pay at an operator branch.'],
    'cash_agent' => ['instructions' => 'Pay an authorised booking agent.'], 'company_wallet' => [], 'passenger_wallet' => [],
    'corporate_account' => [], 'voucher' => [], 'gift_card' => [],
]];
