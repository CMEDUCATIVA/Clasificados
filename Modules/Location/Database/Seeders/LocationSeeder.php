<?php

namespace Modules\Location\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Modules\Location\Models\City;
use Modules\Location\Models\Country;
use Modules\Location\Models\District;
use Tapp\FilamentCountryCodeField\Enums\CountriesEnum;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $worldDataset = $this->worldDataset();

        if ($worldDataset !== null) {
            $this->seedFromWorldDataset($worldDataset);

            return;
        }

        foreach ($this->countries() as $country) {
            Country::updateOrCreate(
                ['code' => $country['code']],
                [
                    'name' => $country['name'],
                    'phone_code' => $country['phone_code'],
                    'is_active' => true,
                ]
            );
        }

        $turkey = Country::query()->where('code', 'TR')->first();

        if (! $turkey) {
            return;
        }

        $turkeyCities = $this->turkeyCities();

        foreach ($turkeyCities as $city) {
            City::updateOrCreate(
                ['country_id' => (int) $turkey->id, 'name' => $city],
                ['is_active' => true]
            );
        }

        City::query()
            ->where('country_id', (int) $turkey->id)
            ->whereNotIn('name', $turkeyCities)
            ->delete();
    }

    private function seedFromWorldDataset(array $dataset): void
    {
        foreach ($dataset as $countryData) {
            if (! is_array($countryData)) {
                continue;
            }

            $countryCode = strtoupper(trim((string) ($countryData['iso2'] ?? $countryData['code'] ?? '')));
            $countryName = trim((string) ($countryData['name'] ?? ''));

            if ($countryCode === '' || $countryName === '') {
                continue;
            }

            $country = Country::query()->updateOrCreate(
                ['code' => substr($countryCode, 0, 3)],
                [
                    'name' => $countryName,
                    'phone_code' => $this->normalizePhoneCode((string) ($countryData['phonecode'] ?? $countryData['phone_code'] ?? '')),
                    'is_active' => true,
                ]
            );

            $states = is_array($countryData['states'] ?? null) ? $countryData['states'] : [];

            foreach ($states as $stateData) {
                if (! is_array($stateData)) {
                    continue;
                }

                $districtName = trim((string) ($stateData['name'] ?? ''));
                $cities = is_array($stateData['cities'] ?? null) ? $stateData['cities'] : [];

                if ($cities === [] && $districtName !== '') {
                    $cities = [['name' => $districtName]];
                }

                foreach ($cities as $cityData) {
                    $cityName = is_array($cityData)
                        ? trim((string) ($cityData['name'] ?? ''))
                        : trim((string) $cityData);

                    if ($cityName === '') {
                        continue;
                    }

                    $city = City::query()->updateOrCreate(
                        [
                            'country_id' => (int) $country->id,
                            'name' => $cityName,
                        ],
                        ['is_active' => true]
                    );

                    if ($districtName === '') {
                        continue;
                    }

                    District::query()->updateOrCreate(
                        [
                            'city_id' => (int) $city->id,
                            'name' => $districtName,
                        ],
                        ['is_active' => true]
                    );
                }
            }
        }
    }

    private function worldDataset(): ?array
    {
        return $this->downloadWorldDataset();
    }

    private function downloadWorldDataset(): ?array
    {
        $urls = config('location.seed.dataset_urls', []);

        if (! is_array($urls)) {
            return null;
        }

        foreach ($urls as $url) {
            $sourceUrl = trim((string) $url);

            if ($sourceUrl === '') {
                continue;
            }

            try {
                $response = Http::timeout((int) config('location.seed.dataset_timeout_seconds', 180))
                    ->retry(2, 1000)
                    ->get($sourceUrl);

                if (! $response->successful()) {
                    continue;
                }

                $decoded = $this->decodeDataset($response->body(), $sourceUrl);

                if ($decoded !== null) {
                    return $decoded;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function decodeDataset(string $payload, string $source): ?array
    {
        $isGzip = str_ends_with(strtolower($source), '.gz');
        $content = $isGzip ? gzdecode($payload) : $payload;

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return null;
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        $first = $decoded[0] ?? null;

        if (! is_array($first)) {
            return null;
        }

        if (! isset($first['states']) && ! isset($first['iso2']) && ! isset($first['code'])) {
            return null;
        }

        return $decoded;
    }

    private function countries(): array
    {
        $countries = [];

        foreach (CountriesEnum::cases() as $countryEnum) {
            $value = $countryEnum->value;
            $phoneCode = $this->normalizePhoneCode($countryEnum->getCountryCode());

            if ($value === 'us_ca') {
                $countries['US'] = [
                    'code' => 'US',
                    'name' => 'United States',
                    'phone_code' => $phoneCode,
                ];
                $countries['CA'] = [
                    'code' => 'CA',
                    'name' => 'Canada',
                    'phone_code' => $phoneCode,
                ];

                continue;
            }

            if ($value === 'ru_kz') {
                $countries['RU'] = [
                    'code' => 'RU',
                    'name' => 'Russia',
                    'phone_code' => $phoneCode,
                ];
                $countries['KZ'] = [
                    'code' => 'KZ',
                    'name' => 'Kazakhstan',
                    'phone_code' => $phoneCode,
                ];

                continue;
            }

            $key = 'filament-country-code-field::countries.'.$value;
            $labelEn = trim((string) trans($key, [], 'en'));

            $name = $labelEn !== '' && $labelEn !== $key ? $labelEn : strtoupper($value);

            $iso2 = strtoupper(explode('_', $value)[0] ?? $value);

            $countries[$iso2] = [
                'code' => $iso2,
                'name' => $name,
                'phone_code' => $phoneCode,
            ];
        }

        return collect($countries)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function normalizePhoneCode(string $phoneCode): string
    {
        $normalized = trim(explode(',', $phoneCode)[0]);
        $normalized = str_replace(' ', '', $normalized);

        if ($normalized === '') {
            return '';
        }

        return substr($normalized, 0, 10);
    }

    private function turkeyCities(): array
    {
        return [
            'Adana',
            'Adiyaman',
            'Afyonkarahisar',
            'Agri',
            'Aksaray',
            'Amasya',
            'Ankara',
            'Antalya',
            'Ardahan',
            'Artvin',
            'Aydin',
            'Balikesir',
            'Bartin',
            'Batman',
            'Bayburt',
            'Bilecik',
            'Bingol',
            'Bitlis',
            'Bolu',
            'Burdur',
            'Bursa',
            'Canakkale',
            'Cankiri',
            'Corum',
            'Denizli',
            'Diyarbakir',
            'Duzce',
            'Edirne',
            'Elazig',
            'Erzincan',
            'Erzurum',
            'Eskisehir',
            'Gaziantep',
            'Giresun',
            'Gumushane',
            'Hakkari',
            'Hatay',
            'Igdir',
            'Isparta',
            'Istanbul',
            'Izmir',
            'Kahramanmaras',
            'Karabuk',
            'Karaman',
            'Kars',
            'Kastamonu',
            'Kayseri',
            'Kilis',
            'Kirikkale',
            'Kirklareli',
            'Kirsehir',
            'Kocaeli',
            'Konya',
            'Kutahya',
            'Malatya',
            'Manisa',
            'Mardin',
            'Mersin',
            'Mugla',
            'Mus',
            'Nevsehir',
            'Nigde',
            'Ordu',
            'Osmaniye',
            'Rize',
            'Sakarya',
            'Samsun',
            'Siirt',
            'Sinop',
            'Sivas',
            'Sanliurfa',
            'Sirnak',
            'Tekirdag',
            'Tokat',
            'Trabzon',
            'Tunceli',
            'Usak',
            'Van',
            'Yalova',
            'Yozgat',
            'Zonguldak',
        ];
    }
}
