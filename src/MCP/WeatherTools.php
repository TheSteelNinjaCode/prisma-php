<?php

declare(strict_types=1);

namespace PPHP\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use RuntimeException;
use JsonException;

final class WeatherTools
{
    #[McpTool(
        name: 'get-weathers',
        description: 'Returns current temperature for a city'
    )]
    public function getWeather(
        #[Schema(type: 'string', minLength: 1, description: 'City name')]
        string $city
    ): string {
        $http = new Client([
            'timeout'         => 10,
            'connect_timeout' => 8,
            'http_errors'     => true,
            'headers'         => ['Accept' => 'application/json'],
        ]);

        try {
            $geoUrl = 'https://geocoding-api.open-meteo.com/v1/search'
                . '?name=' . rawurlencode($city)
                . '&count=1&language=en&format=json';

            $geoRes = $http->get($geoUrl);
            $geoJson = $geoRes->getBody()->getContents();
            $geo = json_decode($geoJson, true, 512, JSON_THROW_ON_ERROR);

            if (empty($geo['results'][0])) {
                throw new RuntimeException("City \"$city\" not found (geocoding returned no results).");
            }

            $lat = (float) $geo['results'][0]['latitude'];
            $lon = (float) $geo['results'][0]['longitude'];

            $wxUrl = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current_weather=true";

            $wxRes = $http->get($wxUrl);
            $wxJson = $wxRes->getBody()->getContents();
            $wx = json_decode($wxJson, true, 512, JSON_THROW_ON_ERROR);

            $cw = $wx['current_weather'] ?? null;
            if (!is_array($cw)) {
                throw new RuntimeException('No current weather data found in API response.');
            }

            $map = [
                0 => 'Clear sky',
                1 => 'Mainly clear',
                2 => 'Partly cloudy',
                3 => 'Overcast',
                45 => 'Fog',
                48 => 'Depositing rime fog',
                51 => 'Light drizzle',
                53 => 'Moderate drizzle',
                55 => 'Dense drizzle',
                56 => 'Light freezing drizzle',
                57 => 'Dense freezing drizzle',
                61 => 'Slight rain',
                63 => 'Moderate rain',
                65 => 'Heavy rain',
                66 => 'Light freezing rain',
                67 => 'Heavy freezing rain',
                71 => 'Slight snow fall',
                73 => 'Moderate snow fall',
                75 => 'Heavy snow fall',
                77 => 'Snow grains',
                80 => 'Slight rain showers',
                81 => 'Moderate rain showers',
                82 => 'Violent rain showers',
                85 => 'Slight snow showers',
                86 => 'Heavy snow showers',
                95 => 'Thunderstorm',
                96 => 'Thunderstorm with slight hail',
                99 => 'Thunderstorm with heavy hail',
            ];

            $code  = (int)($cw['weathercode'] ?? -1);
            $tempC = $cw['temperature'] ?? null;

            if (!is_numeric($tempC)) {
                throw new RuntimeException('Temperature missing or not numeric in current weather payload.');
            }

            $desc = $map[$code] ?? 'Unknown';
            return "🌡️ {$tempC} °C · {$desc} (code {$code})";
        } catch (GuzzleException $e) {
            throw new RuntimeException('Weather request failed: ' . $e->getMessage(), previous: $e);
        } catch (JsonException $e) {
            throw new RuntimeException('Weather JSON parsing failed: ' . $e->getMessage(), previous: $e);
        }
    }
}
