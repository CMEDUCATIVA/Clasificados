<?php

return [
    'seed' => [
        'memory_limit' => env('LOCATION_SEED_MEMORY_LIMIT', '1024M'),
        'minimum_countries' => (int) env('LOCATION_SEED_MINIMUM_COUNTRIES', 200),
        'dataset_local_path' => env(
            'LOCATION_SEED_DATASET_LOCAL_PATH',
            'location/countries+states+cities.json'
        ),
        'dataset_timeout_seconds' => (int) env('LOCATION_SEED_DATASET_TIMEOUT_SECONDS', 180),
    ],
];

