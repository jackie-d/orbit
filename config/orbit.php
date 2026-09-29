<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contact photos
    |--------------------------------------------------------------------------
    |
    | Photos are stored on an object store (S3 / MinIO) in Kubernetes so web
    | pods stay stateless. Locally and in tests the "public" disk is fine.
    |
    */

    'photos' => [
        'disk' => env('ORBIT_PHOTO_DISK', env('FILESYSTEM_DISK', 'public')),
        'max_kb' => (int) env('ORBIT_PHOTO_MAX_KB', 5120),
        'max_dimension' => (int) env('ORBIT_PHOTO_MAX_DIMENSION', 1024),
        'thumb_dimension' => (int) env('ORBIT_PHOTO_THUMB_DIMENSION', 256),
        'url_ttl_minutes' => (int) env('ORBIT_PHOTO_URL_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Soft-deleted contacts and interactions are permanently removed by the
    | scheduled "orbit:prune-deleted" command after this many days.
    |
    */

    'prune_after_days' => (int) env('ORBIT_PRUNE_AFTER_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    */

    'exports' => [
        'max_per_page' => 500,
        'rate_limit_per_minute' => (int) env('ORBIT_EXPORT_RATE_LIMIT', 60),
    ],

];
