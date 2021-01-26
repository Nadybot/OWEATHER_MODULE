# OWEATHER_MODULE

A Nadybot weather module using the OpenWeather API

## Installation

In order to use the module, you need to [acquire an account](https://home.openweathermap.org/users/sign_up) on the OpenWeather API - free or paid.

Save the API token with `!settings save oweather_api_key <your token>`

## Usage

To search for weather data based on City:
* `!oweather 'city name'`

To search for weather data based on City, country:
* `!oweather 'city name, country code'`

To search for weather data based on zip code and country code:
* `!oweather 'zip, country code'`

*Note: if the city returned is not the one you wanted, try using the zip search.*
