<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\DataTransferObject;

class TempData extends DataTransferObject {
	public float $temp;
	public float $feels_like;
	public float $temp_min;
	public float $temp_max;
	public int $pressure;
	public int $humidity;
}
