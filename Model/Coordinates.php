<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\DataTransferObject;

class Coordinates extends DataTransferObject {
	public float $lon;
	public float $lat;
}
