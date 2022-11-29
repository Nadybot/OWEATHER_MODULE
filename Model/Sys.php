<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\DataTransferObject;

class Sys extends DataTransferObject {
	public ?int $type;
	public ?int $id;
	public string $country;
	public int $sunrise;
	public int $sunset;
}
