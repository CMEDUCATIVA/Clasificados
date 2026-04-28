<?php

return [
    'seed' => [
        'dataset_local_path' => env('LOCATION_SEED_DATASET_LOCAL_PATH', ''),
        'dataset_timeout_seconds' => (int) env('LOCATION_SEED_DATASET_TIMEOUT_SECONDS', 180),
        'dataset_urls' => array_values(array_filter([
            env('LOCATION_SEED_DATASET_URL_1', 'https://github.com/dr5hn/countries-states-cities-database/releases/latest/download/json-country-state-city.json.gz'),
            env('LOCATION_SEED_DATASET_URL_2', 'https://github.com/dr5hn/countries-states-cities-database/releases/latest/download/json-country-state-city.json'),
            env('LOCATION_SEED_DATASET_URL_3', 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/json/countries+states+cities.json'),
        ])),
    ],
];
