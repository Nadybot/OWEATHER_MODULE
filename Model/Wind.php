<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\DataTransferObject;

class Wind extends DataTransferObject {
	public float $speed;
	public int $deg;
}
