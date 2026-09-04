<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Distance between two points. Uses a configured mapping/geocoding provider (real road distance)
 * when one is set; otherwise falls back to a haversine great-circle calculation — no API key
 * required, always available, and close enough for an estimated route distance until an operator
 * configures a real provider.
 */
class MappingService
{
    public function distanceKm(float $originLat, float $originLng, float $destinationLat, float $destinationLng): float
    {
        $config = config('integrations.mapping');
        if (filled($config['url'] ?? null)) {
            try {
                $distance = Http::withToken((string) ($config['token'] ?? ''))->timeout(10)
                    ->get($config['url'], ['origin' => "{$originLat},{$originLng}", 'destination' => "{$destinationLat},{$destinationLng}"])
                    ->throw()->json('distance_km');
                if (is_numeric($distance)) {
                    return round((float) $distance, 2);
                }
            } catch (Throwable) {
                // Fall through to the haversine estimate below.
            }
        }

        return $this->haversine($originLat, $originLng, $destinationLat, $destinationLng);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);
        $a = sin($deltaLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;

        return round($earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
}
