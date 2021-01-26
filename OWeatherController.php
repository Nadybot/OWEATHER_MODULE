<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE;

use JsonException;
use Nadybot\Core\{
	CommandReply,
	Http,
	HttpResponse,
	SettingManager,
	Text,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'oweather',
 *		accessLevel = 'all',
 *		description = 'View Weather for any location',
 *		help        = 'oweather.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'forecast',
 *		accessLevel = 'all',
 *		description = 'View Weather forecast for any location',
 *		help        = 'oweather.txt'
 *	)
 */
class OWeatherController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Http $http;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"oweather_api_key",
			"The OpenWeatherMap API key",
			"edit",
			"text",
			"None",
			"None",
			"",
			"mod"
		);
	}

	/**
	 * Try to convert a wind degree into a wind direction
	 */
	public function degreeToDirection(float $degree): string {
		$mapping = [
			  0 => "N",
			 22 => "NNE",
			 45 => "NE",
			 67 => "ENE",
			 90 => "E",
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
			32.7 => 'hurricane',
			28.5 => 'violent storm',
			24.5 => 'storm',
			20.8 => 'strong gale',
			17.2 => 'gale',
			13.9 => 'high wind',
			10.8 => 'strong breeze',
			 8.0 => 'fresh breeze',
			 5.5 => 'moderate breeze',
			 3.4 => 'gentle breeze',
			 1.6 => 'light breeze',
			 0.5 => 'light air',
			 0.0 => 'calm',
		];
		foreach ($beaufortScale as $windSpeed => $windStrength) {
			if ($speed >= $windSpeed) {
				return $windStrength;
			}
		}
		return 'unknown';
	}

	/**
	 * Return a link to OpenStreetMap at the given coordinates
	 */
	public function getOSMLink($coords) {
		$zoom = 12; // Zoom is 1 to 20 (full in)
		$lat = number_format($coords["lat"], 4);
		$lon = number_format($coords["lon"], 4);

		return "https://www.openstreetmap.org/#map=$zoom/$lat/$lon";
	}

	public function formatCelsius($degrees) {
		$temp = number_format(abs($degrees), 1);
		if (strlen($temp) === 3) {
			if ($degrees > 0) {
				$temp = "<black>-_<end>$temp";
			} else {
				$temp = "<black>_<end>-$temp";
			}
		} elseif ($degrees > 0) {
			$temp = "<black>-<end>$temp";
		} else {
			$temp = "-$temp";
		}
		return $temp;
	}

	public function getCountryName(string $cc): string {
		if (!function_exists("locale_get_display_region")) {
			return $cc;
		}
		return locale_get_display_region("-$cc", "en");
	}

	public function forecastToString($data) {
		$latString     = $data["city"]["coord"]["lat"] > 0 ? "N".$data["city"]["coord"]["lat"] : "S".(-1 * $data["city"]["coord"]["lat"]);
		$lonString     = $data["city"]["coord"]["lon"] > 0 ? "E".$data["city"]["coord"]["lon"] : "W".(-1 * $data["city"]["coord"]["lon"]);
		$mapCommand    = $this->text->makeChatcmd("OpenStreetMap", "/start ".$this->getOSMLink($data["city"]["coord"]));
		$locName       = $data["city"]["name"];
		$locCC         = $this->getCountryName($data["city"]["country"]);
		$population    = number_format($data["city"]["population"], 0);
		$timezone      = $this->tzSecsToHours($data["city"]["timezone"]);
		$currentTime   = date("l, H:i:s", time() + $data["city"]["timezone"]);

		$blob = "Location: <highlight>$locName<end>, <highlight>$locCC<end><br>" .
			"Timezone: <highlight>UTC ${timezone}<end><br>" .
			"Lat/Lon: <highlight>${latString}° ${lonString}°<end> $mapCommand<br>" .
			"Population: <highlight>${population}<end><br>" .
			"Local time: <highlight>${currentTime}<end><br>" .
			"<br>".
			"All times are UTC ${timezone}.<br>";

		$weatherByDay = [];
		foreach ($data['list'] as $forecast) {
			$day = date("l", $forecast["dt"]+$data["city"]["timezone"]);
			if (!array_key_exists($day, $weatherByDay)) {
				$weatherByDay[$day] = [];
			}
			$weatherByDay[$day][] = $forecast;
		}
		// Remove the last day from the list if we don't have a full forecast
		if (count($weatherByDay[$day]) < 8) {
			unset($weatherByDay[$day]);
		}
		foreach ($weatherByDay as $day => $forecastlist) {
			$blob .= "<br><header2>$day<end><br>";
			foreach ($forecastlist as $forecast) {
				$when          = date("H:i", $forecast["dt"]+$data["city"]["timezone"]);
				$tempC         = $this->formatCelsius($forecast["main"]["temp"]);
				$tempFeelsC    = $this->formatCelsius($forecast["main"]["feels_like"]);
//				$tempF         = number_format($forecast["main"]["temp"] * 1.8 + 32, 1);
//				$tempFeelsF    = number_format($forecast["main"]["feels_like"] * 1.8 + 32, 1);
				$weatherString = $forecast["weather"][0]["description"];
				$blob .= "<tab>$when: <highlight>${tempC}°C<end>, ".
					"feels like <highlight>${tempFeelsC}°C<end>".
//					" (<highlight>${tempF}°F<end>, ".
//					"feels like <highlight>${tempFeelsF}°F<end>)".
//					", <highlight>$weatherString<end>";
					"";
				if (array_key_exists("clouds", $forecast)) {
					$clouds = $forecast["clouds"]["all"];
					if ($clouds < 10) {
						$clouds = "<black>00<end>$clouds";
					} elseif ($clouds < 100) {
						$clouds = "<black>0<end>$clouds";
					}
					$blob .= ", <highlight>${clouds}%<end> clouds";
				}
				if (array_key_exists("rain", $forecast)) {
					$rain = number_format($forecast["rain"]["3h"], 1);
					if (strlen($rain) < 4) {
						$rain = "<black>0<end>$rain";
					}
					$blob .= ", <highlight>${rain}mm<end> rain";
				}
				$blob .= "<br>";
			}
		}
		return $blob;
	}

	/**
	 * Convert the result hash of the API into a blob string
	 */
	public function weatherToString($data): string {
		$latString     = $data["coord"]["lat"] > 0 ? "N".$data["coord"]["lat"] : "S".(-1 * $data["coord"]["lat"]);
		$lonString     = $data["coord"]["lon"] > 0 ? "E".$data["coord"]["lon"] : "W".(-1 * $data["coord"]["lon"]);
		$mapCommand    = $this->text->makeChatcmd("OpenStreetMap", "/start ".$this->getOSMLink($data["coord"]));
		$luString      = date("D, Y-m-d H:i:s", $data["dt"])." UTC";
		$locName       = $data["name"];
		$locCC         = null;
		if (isset($data["sys"]["country"])) {
			$locCC         = $this->getCountryName($data["sys"]["country"]);
		}
		$tempC         = number_format($data["main"]["temp"], 1);
		$tempFeelsC    = number_format($data["main"]["feels_like"], 1);
		$tempF         = number_format($data["main"]["temp"] * 1.8 + 32, 1);
		$tempFeelsF    = number_format($data["main"]["feels_like"] * 1.8 + 32, 1);
		$weatherString = $data["weather"][0]["description"];
		$clouds        = $data["clouds"]["all"];
		$humidity      = $data["main"]["humidity"];
		$pressureHPA   = $data["main"]["pressure"];
		$pressureHG    = number_format($data["main"]["pressure"] * 0.02952997, 2);
		$windStrength  = $this->getWindStrength($data["wind"]["speed"]);
		$windSpeedKMH  = number_format($data["wind"]["speed"] * 3600 / 1000.0, 1);
		$windSpeedMPH  = number_format($data["wind"]["speed"] * 3600 / 1609.3, 1);
		$windDirection = $this->degreeToDirection($data["wind"]["deg"]);
		$timezone      = $this->tzSecsToHours($data["timezone"]);
		$sunRise       = date("H:i:s", $data["sys"]["sunrise"] + $data["timezone"]) . " UTC $timezone";
		$sunSet        = date("H:i:s", $data["sys"]["sunset"] + $data["timezone"]) . " UTC $timezone";
		if (array_key_exists("visibility", $data) && $data["visibility"] > 0) {
			$visibilityKM = number_format($data["visibility"]/1000, 1);
			$visibilityMiles = number_format($data["visibility"]/1609.3, 1);
		}

		$blob = "Last Updated: <highlight>$luString<end>\n" .
			"\n" .
			"Location: <highlight>$locName<end>" . (isset($locCC) ? ", <highlight>$locCC<end>" : "") . "\n" .
			"Timezone: <highlight>UTC $timezone<end>\n" .
			"Lat/Lon: <highlight>${latString}° ${lonString}°<end> $mapCommand\n" .
			"\n" .
			"Currently: <highlight>${tempC}°C<end>".
				" (<highlight>${tempF}°F<end>)".
				", <highlight>$weatherString<end>\n".
			"Feels like: <highlight>${tempFeelsC}°C<end>".
				" (<highlight>${tempFeelsF}°F<end>)\n".
			"Clouds: <highlight>${clouds}%<end>\n" .
			"Humidity: <highlight>${humidity}%<end>\n" .
			"Visibility: <highlight>${visibilityKM} km<end> (<highlight>${visibilityMiles} miles<end>)\n" .
			"Pressure: <highlight>$pressureHPA hPa <end>(<highlight>${pressureHG}\" Hg<end>)\n" .
			"Wind: <highlight>$windStrength<end> - <highlight>$windSpeedKMH km/h ($windSpeedMPH mph)<end> from the <highlight>$windDirection<end>\n" .
			"\n" .
			"Sunrise: <highlight>$sunRise<end>\n" .
			"Sunset: <highlight>$sunSet<end>\n".
			"\n".
			$this->text->makeChatcmd("Forecast for the next 3 days", "/tell <myname> forecast ${locName},${locCC}");

		return $blob;
	}

	public function tzSecsToHours($secs) {
		$prefix = "+";
		if ($secs < 0) {
			$prefix = "-";
		}
		return $prefix . date("H:i", abs($secs));
	}

	/**
	 * Download the weather data from the API, returning
	 * either false for an unknown error, a string with the error message
	 * or a hash with the data.
	 */
	public function downloadWeather($apiKey, $location, $endpoint, $extraArgs, callable $callback) {
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
			->withCallback($callback);
	}

	/**
	 * Download the weather forecast data from the API, returning
	 * either false for an unknown error, a string with the error message
	 * or a hash with the data.
	 */
	public function downloadWeatherForecast($apiKey, $location, callable $callback): void {
		$this->downloadWeather($apiKey, $location, "forecast", ["cnt" => 24], $callback);
	}

	/**
	 * @HandlesCommand("forecast")
	 * @Matches("/^forecast (.+)$/i")
	 */
	public function forecastCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$location = $args[1];

		$apiKey = $this->settingManager->get('oweather_api_key');
		if (strlen($apiKey) !== 32) {
			$sendto->reply("There is either no API key or an invalid one was set.");
			return;
		}
		$this->downloadWeatherForecast(
			$apiKey,
			$location,
			function (HttpResponse $response) use ($sendto): void {
				$this->renderForecastResponse($response, $sendto);
			}
		);
	}

	protected function renderForecastResponse(HttpResponse $response, CommandReply $sendto): void {
		try {
			$data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$sendto->reply("Error decoding weather data.");
			return;
		}
		if (!is_array($data) || !array_key_exists("cod", $data)) {
			$sendto->reply("Unknown error while looking up the weather.");
			return;
		}
		if ($data["cod"] != 200) {
			if (array_key_exists("message", $data)) {
				$sendto->reply("Error looking up the weather: <highlight>{$data['message']}<end>.");
				return;
			}
			$sendto->reply("Unknown error while looking up the weather.");
		}
		if (is_string($data)) {
			$sendto->reply("Error looking up the weather: <highlight>$data<end>.");
			return;
		}
		if (!is_array($data)) {
			$sendto->reply("Unknown error while looking up the weather.");
			return;
		}

		$blob = $this->forecastToString($data);

		$locCC = $this->getCountryName($data["city"]["country"]);
		$msg = $this->text->makeBlob("Weather forecast for ".$data["city"]["name"].", $locCC", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("oweather")
	 * @Matches("/^oweather (.+)$/i")
	 */
	public function weatherCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$location = $args[1];

		$apiKey = $this->settingManager->get('oweather_api_key');
		if (strlen($apiKey) !== 32) {
			$sendto->reply("There is either no API key or an invalid one was set.");
			return;
		}
		$this->downloadWeather(
			$apiKey,
			$location,
			"weather",
			[],
			function(HttpResponse $response) use ($sendto) {
				$this->renderWeatherResponse($response, $sendto);
			}
		);
	}

	protected function renderWeatherResponse(HttpResponse $response, CommandReply $sendto): void {
		try {
			$data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$sendto->reply("Error decoding weather data.");
			return;
		}
		if (!is_array($data) || !array_key_exists("cod", $data)) {
			$sendto->reply("Unknown error while looking up the weather.");
			return;
		}
		if ($data["cod"] != 200) {
			if (array_key_exists("message", $data)) {
				$sendto->reply("Error looking up the weather: <highlight>{$data['message']}<end>.");
				return;
			}
			$sendto->reply("Unknown error while looking up the weather.");
		}
		$tempC = number_format($data["main"]["temp"], 1);
		$weatherString = $data["weather"][0]["description"];
		$cc = null;
		if (isset($data["sys"]["country"])) {
			$cc = $this->getCountryName($data["sys"]["country"]);
		}

		$blob = $this->weatherToString($data);

		$msg = "The weather for <highlight>" . $data["name"] . "<end>".
			(isset($cc) ? ", ${cc}" : "") . " is ".
			"<highlight>${tempC}°C<end> with $weatherString [" . $this->text->makeBlob("Details", $blob) . "]";

		$sendto->reply($msg);
	}
}
