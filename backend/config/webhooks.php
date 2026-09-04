<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Outbound webhook events
    |--------------------------------------------------------------------------
    |
    | The fixed set of event names a company may subscribe a partner webhook to. Keeping this as
    | an allow-list (rather than accepting any string) means a typo in a subscription can never
    | silently swallow real events, and WebhookDispatcher::dispatch() call sites stay the single
    | source of truth for what actually fires. Deliberately starts small — only events with a real
    | dispatch() call site are listed; add more here as new call sites adopt WebhookDispatcher.
    */
    'events' => [
        'booking.confirmed',
        'settlement.paid',
    ],
];
