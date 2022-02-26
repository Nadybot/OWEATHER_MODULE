<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;
use Spatie\DataTransferObject\DataTransferObject;

class Weather extends DataTransferObject {
	public Coordinates $coord;

	/** @var ShortWeather[] */
	#[CastWith(ArrayCaster::class, itemType: ShortWeather::class)]
	public array $weather;

	public string $base;
	public TempData $main;
	public ?int $visibility=null;
	public Wind $wind;
	/** @var array<string,int> */
	public array $clouds;
	public int $dt;
	public Sys $sys;
	public int $timezone;
	public int $id;
	public string $name;
	public int $cod;
}
