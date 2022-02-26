<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;
use Spatie\DataTransferObject\DataTransferObject;

class ForecastWeather extends DataTransferObject {
	public int $dt;
	public TempData $main;
	/** @var ShortWeather[] */
	#[CastWith(ArrayCaster::class, itemType: ShortWeather::class)]
	public array $weather;
	/** @var array<string,int> */
	public array $clouds;
	public Wind $wind;
	public ?int $visibility = null;
	public int $pop;
	/** @var null|array<string,float> */
	public ?array $rain = null;
}
