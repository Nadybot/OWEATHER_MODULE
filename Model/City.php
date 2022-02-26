<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\DataTransferObject;

class City extends DataTransferObject {
	public int $id;
	public string $name;
	public Coordinates $coord;
	public string $country;
	public int $population;
	public int $timezone;
	public int $sunrise;
	public int $sunset;
}
