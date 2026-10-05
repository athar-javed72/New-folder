<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Blind Index HMAC Secret Key
    |--------------------------------------------------------------------------
    |
    | Secret key used to compute HMAC-SHA256 blind indexes for searching
    | encrypted identifiers (B-Form, CNIC, Passport). Must be at least 32
    | characters long and kept separate from APP_KEY.
    |
    */

    'blind_index_key' => env('BLIND_INDEX_KEY'),

];
