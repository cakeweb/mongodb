<?php

namespace CakeWeb\MongoDB;

use CakeWeb\Registry;

class Connection
{
	public static function init($host, $database, $authUsername, $authPassword, $authDatabase, $instanceName = '')
	{
		// Determina 'mongoManager'
		$connectionString = "mongodb://{$authUsername}:{$authPassword}@{$host}/{$authDatabase}";
		Registry::set("mongoManager{$instanceName}", new \MongoDB\Driver\Manager($connectionString));

		// Determina 'mongoConfig'
		Registry::set("mongoConfig{$instanceName}", [
			'host' => $host,
			'database' => $database,
			'auth' => [
				'username' => $authUsername,
				'password' => $authPassword,
				'database' => $authDatabase
			]
		]);
	}
}