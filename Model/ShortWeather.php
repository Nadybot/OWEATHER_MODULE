<?php declare(strict_types=1);

namespace Nadybot\User\Modules\OWEATHER_MODULE\Model;

use Spatie\DataTransferObject\DataTransferObject;

class ShortWeather extends DataTransferObject {
	public int $id;
	public string $main;
	public string $description;
	public string $icon;
}
