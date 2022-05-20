<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

use DateTimeZone;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Http,
	HttpResponse,
	ModuleInstance,
	SettingManager,
	Text,
	Util,
};
use Nadybot\User\Modules\OWEATHER_MODULE\Model\{
	Coordinates,
	Weather,
	Forecast,
	ForecastWeather,
};
use Safe\DateTime;
use Safe\Exceptions\JsonException;
use Throwable;

use function Safe\date;
use function Safe\json_decode;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command:     'oweather',
		accessLevel: 'guest',
		description: 'View Weather for any location',
	),
	NCA\DefineCommand(
		command:     'forecast',
		accessLevel: 'guest',
		description: 'View Weather forecast for any location',
	)
]
class OWeatherController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Http $http;

	/** TheOpenWeatherMap API key */
	#[NCA\Setting\Text(options: [
		"None"
	])]
	public string $oweatherApiKey = "None";

	/**
	 * Try to convert a wind degree into a wind direction
	 */
	public function degreeToDirection(int $degree): string {
		$mapping = [
			0   => "N",
			22  => "NNE",
			45  => "NE",
			67  => "ENE",
			90  => "E",
			112 => "ESE",
			135 => "SE",
			157 => "SSE",
			180 => "S",
			202 => "SSW",
			225 => "SW",
			247 => "WSW",
			270 => "W",
			292 => "WNW",
			315 => "NW",
			337 => "NNW",
			360 => "N",
		];
		$current = "unknown";
		$currentDiff = 360;
		foreach ($mapping as $mapDeg => $mapDir) {
			if (abs($degree-$mapDeg) < $currentDiff) {
				$current = $mapDir;
				$currentDiff = abs($degree-$mapDeg);
			}
		}
		return $current;
	}

	/**
	 * Convert the windspeed in m/s into the wind's strength according to beaufort
	 */
	public function getWindStrength(float $speed): string {
		$beaufortScale = [
			'32.7' => 'hurricane',
			'28.5' => 'violent storm',
			'24.5' => 'storm',
			'20.8' => 'strong gale',
			'17.2' => 'gale',
			'13.9' => 'high wind',
			'10.8' => 'strong breeze',
			'8.0'  => 'fresh breeze',
			'5.5'  => 'moderate breeze',
			'3.4'  => 'gentle breeze',
			'1.6'  => 'light breeze',
			'0.5'  => 'light air',
			'0.0'  => 'calm',
		];
		foreach ($beaufortScale as $windSpeed => $windStrength) {
			if ($speed >= (float)$windSpeed) {
				return $windStrength;
			}
		}
		return 'unknown';
	}

	/**
	 * Return a link to OpenStreetMap at the given coordinates
	 */
	public function getOSMLink(Coordinates $coords): string {
		$zoom = 12; // Zoom is 1 to 20 (full in)
		$lat = number_format($coords->lat, 4);
		$lon = number_format($coords->lon, 4);

		return "https://www.openstreetmap.org/#map={$zoom}/{$lat}/{$lon}";
	}

	/**
	 * @return array<string>
	 * @phpstan-return array{string,string}
	 */
	public function formatCelsius(float $degrees): array {
		$temp = number_format(abs($degrees), 1);
		if (strlen($temp) === 3) {
			if ($degrees > 0) {
				return ["-_", $temp];
			}
			return ["_", "-$temp"];
		} elseif ($degrees > 0) {
			return ["-", $temp];
		}
		return ["", "-$temp"];
	}

	public function getCountryName(string $cc): string {
		if (!function_exists("locale_get_display_region")) {
			return $cc;
		}
		$result = locale_get_display_region("-$cc", "en");
		if ($result === false) {
			return $cc;
		}
		return $result;
	}

	public function forecastToString(Forecast $forecast): string {
		$latString     = $forecast->city->coord->lat > 0
			? "N".$forecast->city->coord->lat
			: "S".(-1 * $forecast->city->coord->lat);
		$lonString     = $forecast->city->coord->lon > 0
			? "E".$forecast->city->coord->lon
			: "W".(-1 * $forecast->city->coord->lon);
		$mapCommand    = $this->text->makeChatcmd("OpenStreetMap", "/start ".$this->getOSMLink($forecast->city->coord));
		$locName       = $forecast->city->name;
		$locCC         = $this->getCountryName($forecast->city->country);
		$population    = number_format($forecast->city->population, 0);
		$timezone      = $this->tzSecsToHours($forecast->city->timezone);
		$currentTime   = (new DateTime("now", new DateTimeZone("UTC")))
			->setTimestamp(time() + $forecast->city->timezone)
			->format("l, H:i:s");

		$blob = "Location: <highlight>$locName<end>, <highlight>$locCC<end>\n".
			"Timezone: <highlight>UTC${timezone}<end>\n".
			"Lat/Lon: <highlight>${latString}° ${lonString}°<end> $mapCommand\n".
			"Population: <highlight>${population}<end>\n".
			"Local time: <highlight>${currentTime}<end>\n".
			"\n".
			"All times are UTC${timezone}.\n";

		/** @var array<string,ForecastWeather[]> */
		$weatherByDay = [];
		foreach ($forecast->list as $step) {
			$day = (new DateTime("now", new DateTimeZone("UTC")))
				->setTimestamp($step->dt + $forecast->city->timezone)
				->format("l");
			if (!array_key_exists($day, $weatherByDay)) {
				$weatherByDay[$day] = [];
			}
			$weatherByDay[$day][] = $step;
		}
		if (!isset($day)) {
			$blob .= "\nCurrently, there is no weather forecast available.";
			return $blob;
		}
		// Remove the last day from the list if we don't have a full forecast
		if (count($weatherByDay[$day]) < 8) {
			unset($weatherByDay[$day]);
		}
		/** @var array<string,ForecastWeather[]> $weatherByDay */
		foreach ($weatherByDay as $day => $forecastlist) {
			$blob .= "\n<header2>{$day}<end>\n";
			foreach ($forecastlist as $step) {
				$when = (new DateTime("now", new DateTimeZone("UTC")))
					->setTimestamp($step->dt + $forecast->city->timezone)
					->format("H:i");
				[$tempCFill, $tempC] = $this->formatCelsius($step->main->temp);
				[$tempFeelsCFill, $tempFeelsC] = $this->formatCelsius($step->main->feels_like);
				if (strlen($tempCFill)) {
					$tempCFill = "<black>{$tempCFill}<end>";
				}
				if (strlen($tempFeelsCFill)) {
					$tempFeelsCFill = "<black>{$tempFeelsCFill}<end>";
				}
				$blob .= "<tab>$when: {$tempCFill}<highlight>${tempC}°C<end>, ".
					"feels like {$tempFeelsCFill}<highlight>${tempFeelsC}°C<end>".
					"";
				if (isset($forecast->clouds)) {
					$clouds = $step->clouds["all"] ?? 0;
					if ($clouds < 10) {
						$clouds = "<black>00<end>{$clouds}";
					} elseif ($clouds < 100) {
						$clouds = "<black>0<end>{$clouds}";
					}
					$blob .= ", <highlight>{$clouds}%<end> clouds";
				}
				if (isset($step->rain)) {
					$rain = number_format($step->rain["3h"], 1);
					if (strlen($rain) < 4) {
						$rain = "<black>0<end>{$rain}";
					}
					$blob .= ", <highlight>{$rain}mm<end> rain";
				}
				$blob .= "\n";
			}
		}
		return $blob;
	}

	/**
	 * Convert the result hash of the API into a blob string
	 */
	public function weatherToString(Weather $weather): string {
		$latString     = $weather->coord->lat > 0
			? "N" . $weather->coord->lat
			: "S" . (-1 * $weather->coord->lat);
		$lonString     = $weather->coord->lon > 0
			? "E" . $weather->coord->lon
			: "W" . (-1 * $weather->coord->lon);
		$mapCommand    = $this->text->makeChatcmd("OpenStreetMap", "/start ".$this->getOSMLink($weather->coord));
		$luString      = $this->util->date($weather->dt);
		$locName       = $weather->name;
		$locCC         = $this->getCountryName($weather->sys->country);
		$tempC         = number_format($weather->main->temp, 1);
		$tempFeelsC    = number_format($weather->main->feels_like, 1);
		$tempF         = number_format($weather->main->temp * 1.8 + 32, 1);
		$tempFeelsF    = number_format($weather->main->feels_like * 1.8 + 32, 1);
		$weatherString = $weather->weather[0]->description;
		$clouds        = $weather->clouds["all"] ?? "clear sky";
		$humidity      = $weather->main->humidity;
		$pressureHPA   = $weather->main->pressure;
		$pressureHG    = number_format($weather->main->pressure * 0.02952997, 2);
		$windStrength  = $this->getWindStrength($weather->wind->speed);
		$windSpeedKMH  = number_format($weather->wind->speed * 3600 / 1000.0, 1);
		$windSpeedMPH  = number_format($weather->wind->speed * 3600 / 1609.3, 1);
		$windDirection = $this->degreeToDirection($weather->wind->deg);
		$timezone      = $this->tzSecsToHours($weather->timezone);
		$sunRise       = (new DateTime("now", new DateTimeZone("UTC")))
			->setTimestamp($weather->sys->sunrise + $weather->timezone)
			->format("H:i:s") . " UTC{$timezone}";
		$sunSet        = (new DateTime("now", new DateTimeZone("UTC")))
			->setTimestamp($weather->sys->sunset + $weather->timezone)
			->format("H:i:s") . " UTC{$timezone}";
		if (isset($weather->visibility) && $weather->visibility > 0) {
			$visibilityKM = number_format($weather->visibility/1000, 1);
			$visibilityMiles = number_format($weather->visibility/1609.3, 1);
		}

		$blob = "Last Updated: <highlight>$luString<end>\n".
			"\n".
			"Location: <highlight>${locName}<end>, <highlight>${locCC}<end>\n".
			"Timezone: <highlight>UTC${timezone}<end>\n".
			"Lat/Lon: <highlight>${latString}° ${lonString}°<end> ${mapCommand}\n".
			"\n".
			"Currently: <highlight>${tempC}°C<end>".
				" (<highlight>${tempF}°F<end>)".
				", <highlight>${weatherString}<end>\n".
			"Feels like: <highlight>${tempFeelsC}°C<end>".
				" (<highlight>${tempFeelsF}°F<end>)\n".
			"Clouds: <highlight>${clouds}%<end>\n".
			"Humidity: <highlight>${humidity}%<end>\n".
			((isset($visibilityKM) && isset($visibilityMiles))
				? "Visibility: <highlight>${visibilityKM} km<end> (<highlight>${visibilityMiles} miles<end>)\n"
				: ""
			).
			"Pressure: <highlight>{$pressureHPA} hPa <end>(<highlight>${pressureHG}\" Hg<end>)\n".
			"Wind: <highlight>{$windStrength}<end> - <highlight>{$windSpeedKMH} km/h ({$windSpeedMPH} mph)<end> from the <highlight>{$windDirection}<end>\n".
			"\n".
			"Sunrise: <highlight>{$sunRise}<end>\n".
			"Sunset: <highlight>{$sunSet}<end>\n".
			"\n".
			$this->text->makeChatcmd("Forecast for the next 3 days", "/tell <myname> forecast ${locName},${locCC}");

		return $blob;
	}

	public function tzSecsToHours(int $secs): string {
		$prefix = "+";
		if ($secs < 0) {
			$prefix = "-";
		}
		return $prefix . (new DateTime("now", new DateTimeZone("UTC")))
			->setTimestamp(abs($secs))->format("H:i");
	}

	/**
	 * Download the weather data from the API, returning
	 * either false for an unknown error, a string with the error message
	 * or a hash with the data.
	 *
	 * @param array<string,int|string> $extraArgs
	 */
	public function downloadWeather(string $apiKey, string $location, string $endpoint, array $extraArgs, callable $callback): void {
		$this->http->get("http://api.openweathermap.org/data/2.5/${endpoint}")
			->withQueryParams(
				array_merge(
					[
						"q"     => $location,
						"appid" => $apiKey,
						"units" => "metric",
						"mode"  => "json"
					],
					$extraArgs
				)
			)
			->withHeader("Content-Type", "application/json")
			->withTimeout(10)
			->withCallback($callback);
	}

	/**
	 * Get the weather forecast for &lt;location&gt;
	 */
	#[NCA\HandlesCommand("forecast")]
	#[NCA\Help\Example("<symbol>forecast Hamburg")]
	#[NCA\Help\Example("<symbol>forecast Hamburg, US")]
	#[NCA\Help\Example("<symbol>forecast 30629, de", "to search by ZIP")]
	#[NCA\Help\Group("oweather")]
	public function forecastCommand(CmdContext $context, string $location): void {
		$apiKey = $this->oweatherApiKey;
		if (strlen($apiKey) !== 32) {
			$context->reply("There is either no API key or an invalid one was set.");
			return;
		}
		$this->downloadWeather(
			$apiKey,
			$location,
			"forecast",
			["cnt" => 24],
			function (HttpResponse $response) use ($context): void {
				$this->parseForecast($response, $context);
			}
		);
	}

	protected function parseForecast(HttpResponse $response, CmdContext $context): void {
		if (isset($response->error) || !isset($response->body)) {
			$context->reply("Error looking up the weather.");
			return;
		}
		try {
			$data = json_decode($response->body, true);
			$forecast = new Forecast($data);
		} catch (JsonException) {
			$context->reply("Error parsing weather data.");
			return;
		} catch (Throwable $e) {
			if (isset($data) && (int)$data["cod"] !== 200 && isset($data["message"])) {
				$context->reply(
					"Error looking up the weather: ".
					"<highlight>" . $data["message"] . "<end>."
				);
			} else {
				$context->reply("Error parsing weather data.");
			}
			return;
		}
		$blob = $this->forecastToString($forecast);
		$blob = preg_replace(
			"/<highlight><black>([^<]+)<end>([^<]+)<end>/",
			'<black>$1<end><highlight>$2<end>',
			$blob
		);

		$locCC = $this->getCountryName($forecast->city->country);
		$msg = $this->text->makeBlob("Weather forecast for {$forecast->city->name}, {$locCC}", $blob);

		$context->reply($msg);
	}

	/**
	 * Get the current weather for &lt;location&gt;
	 */
	#[NCA\HandlesCommand("oweather")]
	#[NCA\Help\Group("oweather")]
	#[NCA\Help\Example("<symbol>oweather Hamburg")]
	#[NCA\Help\Example("<symbol>oweather Hamburg, US")]
	#[NCA\Help\Example("<symbol>oweather 30629, de", "to search by ZIP")]
	public function weatherCommand(CmdContext $context, string $location): void {
		$apiKey = $this->oweatherApiKey;
		if (strlen($apiKey) != 32) {
			$context->reply("There is either no API key or an invalid one was set.");
			return;
		}
		$this->downloadWeather(
			$apiKey,
			$location,
			"weather",
			[],
			function (HttpResponse $response) use ($context): void {
				$this->displayWeather($response, $context);
			}
		);
	}

	protected function displayWeather(HttpResponse $response, CmdContext $context): void {
		if (isset($response->error) || !isset($response->body)) {
			$context->reply("Error looking up the weather.");
			return;
		}
		try {
			$data = json_decode($response->body, true);
			$weather = new Weather($data);
		} catch (JsonException) {
			$context->reply("Error parsing weather data.");
			return;
		} catch (Throwable $e) {
			if (isset($data) && (int)$data["cod"] !== 200 && isset($data["message"])) {
				$context->reply(
					"Error looking up the weather: ".
					"<highlight>" . $data["message"] . "<end>."
				);
			} else {
				$context->reply("Error parsing weather data.");
			}
			return;
		}
		$tempC = number_format($weather->main->temp, 1);
		$weatherString = $weather->weather[0]->description;
		$cc = $this->getCountryName($weather->sys->country);

		$blob = $this->weatherToString($weather);

		$msg = $this->text->blobWrap(
			"The weather for <highlight>{$weather->name}<end>, ${cc} is ".
			"<highlight>${tempC}°C<end> with {$weatherString} [",
			$this->text->makeBlob("Details", $blob),
			"]"
		);

		$context->reply($msg);
	}
}
