<?php

return [

    /*
    | Static bearer token every request must present. Read from the
    | environment only; an empty value rejects all requests (fail closed).
    */
    'api_token' => env('API_TOKEN', ''),

    /*
    | Attempts for an operation that hits a deadlock (40P01) or a
    | serialization failure (40001) before the error is surfaced.
    */
    'max_attempts' => (int) env('LEDGER_MAX_ATTEMPTS', 5),

];
