<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximale Dateigröße
    |--------------------------------------------------------------------------
    |
    | Obergrenze pro Datei in Bytes. Der Standard liegt bei 5 GB, deutlich
    | über dem, was sich per E-Mail versenden lässt.
    |
    */

    'max_bytes' => (int) env('UPLOAD_MAX_BYTES', 5 * 1024 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Stückgröße
    |--------------------------------------------------------------------------
    |
    | Dateien werden in Stücken übertragen, damit PHP- und Webserver-Limits
    | nicht die gesamte Datei auf einmal annehmen müssen. Liegt das PHP-Limit
    | darunter, wird die Stückgröße automatisch verkleinert.
    |
    */

    'chunk_bytes' => (int) env('UPLOAD_CHUNK_BYTES', 8 * 1024 * 1024),

];
