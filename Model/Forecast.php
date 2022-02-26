<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;
use Spatie\DataTransferObject\DataTransferObject;

class Forecast extends DataTransferObject {
	public int $cod;
	public int $cnt;
	/** @var ForecastWeather[] */
	#[CastWith(ArrayCaster::class, itemType: ForecastWeather::class)]
	public array $list;
	public City $city;
}
