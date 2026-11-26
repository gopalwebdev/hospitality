<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Code Length
    |--------------------------------------------------------------------------
    |
    | How many digits each sign-in code carries. Longer codes are harder to
    | guess but more tedious to type; six is the usual compromise.
    |
    */

    'length' => (int) env('OTP_LENGTH', 6),

    /*
    |--------------------------------------------------------------------------
    | Lifetime
    |--------------------------------------------------------------------------
    |
    | The number of minutes a code stays usable after it is issued.
    |
    */

    'ttl' => (int) env('OTP_TTL', 10),

    /*
    |--------------------------------------------------------------------------
    | Maximum Attempts
    |--------------------------------------------------------------------------
    |
    | How many wrong guesses a single code tolerates before it is burnt. This
    | is what keeps a short numeric code out of reach of brute force.
    |
    */

    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Request Limits
    |--------------------------------------------------------------------------
    |
    | How many codes one address may ask for within "window" seconds, and how
    | long it must wait between requests. These are keyed on the address rather
    | than on an account, so the limit behaves identically whether or not an
    | account exists and gives nothing away.
    |
    */

    'max_sends' => (int) env('OTP_MAX_SENDS', 20),

    'resend_cooldown' => (int) env('OTP_RESEND_COOLDOWN', 120),

    'send_window' => (int) env('OTP_SEND_WINDOW', 600),

    /*
    |--------------------------------------------------------------------------
    | Delivery Log Channel
    |--------------------------------------------------------------------------
    |
    | Codes are emailed. Enabling "log_codes" additionally writes each code in
    | the clear to the channel below, which is convenient while developing and
    | a plain credential leak anywhere else. It defaults to off on purpose.
    |
    */

    'log_codes' => (bool) env('OTP_LOG_CODES', false),

    'log_channel' => env('OTP_LOG_CHANNEL', 'otp'),

];
