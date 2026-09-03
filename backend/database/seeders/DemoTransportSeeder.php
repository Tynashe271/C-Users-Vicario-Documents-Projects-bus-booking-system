<?php

namespace Database\Seeders;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use Illuminate\Database\Seeder;

class DemoTransportSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->updateOrCreate(
            ['slug' => 'mufambi-express'],
            [
                'name' => 'Mufambi Express',
                'registration_number' => 'DEMO-OPERATOR-001',
                'status' => 'active',
                'currency' => 'USD',
                'settings' => [
                    'tax_rate' => 0,
                    'commission_rate' => 5,
                    'booking_service_fee' => 1,
                    'optional_services' => [],
                ],
            ],
        );

        $terminals = collect([
            ['name' => 'Roadport Terminal', 'city' => 'Harare', 'country' => 'ZW', 'latitude' => -17.8252, 'longitude' => 31.0335],
            ['name' => 'Intercity Terminal', 'city' => 'Bulawayo', 'country' => 'ZW', 'latitude' => -20.1325, 'longitude' => 28.6265],
            ['name' => 'Fourth Street Terminal', 'city' => 'Mutare', 'country' => 'ZW', 'latitude' => -18.9707, 'longitude' => 32.6709],
        ])->mapWithKeys(function (array $attributes): array {
            $terminal = Terminal::query()->updateOrCreate(
                ['name' => $attributes['name'], 'city' => $attributes['city']],
                $attributes,
            );

            return [$attributes['city'] => $terminal];
        });

        $bus = Bus::query()->updateOrCreate(
            ['registration_number' => 'MUF-001'],
            [
                'company_id' => $company->id,
                'model' => 'Scania Touring',
                'class' => 'luxury',
                'seat_capacity' => 20,
                'status' => 'available',
                'amenities' => ['wifi', 'charging_ports', 'air_conditioning', 'reclining_seats'],
            ],
        );

        foreach (range(1, 10) as $row) {
            foreach (['A', 'B'] as $position) {
                Seat::query()->firstOrCreate(
                    ['bus_id' => $bus->id, 'number' => $row.$position],
                    ['type' => $row <= 2 ? 'premium' : 'standard'],
                );
            }
        }

        $routeDefinitions = [
            ['origin' => 'Harare', 'destination' => 'Bulawayo', 'distance' => 440, 'duration' => 360, 'fare' => 30],
            ['origin' => 'Bulawayo', 'destination' => 'Harare', 'distance' => 440, 'duration' => 360, 'fare' => 30],
            ['origin' => 'Harare', 'destination' => 'Mutare', 'distance' => 265, 'duration' => 240, 'fare' => 22],
            ['origin' => 'Mutare', 'destination' => 'Harare', 'distance' => 265, 'duration' => 240, 'fare' => 22],
        ];

        foreach ($routeDefinitions as $routeDefinition) {
            $route = TransportRoute::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'origin_terminal_id' => $terminals[$routeDefinition['origin']]->id,
                    'destination_terminal_id' => $terminals[$routeDefinition['destination']]->id,
                ],
                [
                    'name' => $routeDefinition['origin'].' to '.$routeDefinition['destination'],
                    'distance_km' => $routeDefinition['distance'],
                    'duration_minutes' => $routeDefinition['duration'],
                    'active' => true,
                ],
            );

            foreach (range(1, 7) as $daysFromNow) {
                $departure = now()->addDays($daysFromNow)->setTime(8, 0)->startOfMinute();

                Trip::query()->updateOrCreate(
                    ['route_id' => $route->id, 'departs_at' => $departure],
                    [
                        'company_id' => $company->id,
                        'bus_id' => $bus->id,
                        'arrives_at' => $departure->copy()->addMinutes($routeDefinition['duration']),
                        'base_fare' => $routeDefinition['fare'],
                        'currency' => 'USD',
                        'status' => 'available',
                    ],
                );
            }
        }
    }
}
