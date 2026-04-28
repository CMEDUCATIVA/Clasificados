<?php

return [
    'seed' => [
        'prefer_remote' => (bool) env('LOCATION_SEED_PREFER_REMOTE', true),
        'memory_limit' => env('LOCATION_SEED_MEMORY_LIMIT', '1024M'),
        'dataset_local_path' => env(
            'LOCATION_SEED_DATASET_LOCAL_PATH',
            'location/countries+states+cities.json'
        ),
        'dataset_timeout_seconds' => (int) env('LOCATION_SEED_DATASET_TIMEOUT_SECONDS', 180),
        'dataset_urls' => array_values(array_filter([
            env('LOCATION_SEED_DATASET_URL_1', 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/json/countries+states+cities.json'),
            env('LOCATION_SEED_DATASET_URL_2', 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/json/countries+states.json'),
            env('LOCATION_SEED_DATASET_URL_3', 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/json/countries.json'),
        ])),
    ],
];
