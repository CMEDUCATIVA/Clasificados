<?php

return [
    'seed' => [
        'dataset_local_path' => env(
            'LOCATION_SEED_DATASET_LOCAL_PATH',
            'location/countries-states-cities-database-master/json/countries+states+cities.json'
        ),
        'dataset_timeout_seconds' => (int) env('LOCATION_SEED_DATASET_TIMEOUT_SECONDS', 180),
        'dataset_urls' => array_values(array_filter([
            env('LOCATION_SEED_DATASET_URL_1', ''),
            env('LOCATION_SEED_DATASET_URL_2', ''),
            env('LOCATION_SEED_DATASET_URL_3', ''),
        ])),
    ],
];
