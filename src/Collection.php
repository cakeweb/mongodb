<?php

namespace CakeWeb\MongoDB;

use CakeWeb\Registry;
use CakeWeb\HttpStatusCode;
use CakeWeb\Exception;

abstract class Collection extends \MongoDB\Collection
{
	final public static function getInstance()
	{
		$className = get_called_class();
		if(!Registry::get($className))
		{
			// Obtém o 1º argumento do construtor
			if(!$mongoManager = Registry::get('mongoManager'))
			{
				throw new Exception("{$className}::getInstance() não localizou 'mongoManager' no Registry.", 'CAKE-MONGO-UNINITIALIZED');
			}

			// Obtém o 2º argumento do construtor
			if(!$mongoConfig = Registry::get('mongoConfig'))
			{
				throw new Exception("{$className}::getInstance() não localizou 'mongoConfig' no Registry.", 'CAKE-MONGO-UNINITIALIZED');
			}

			// Obtém o 3º argumento do construtor
			if(!defined("{$className}::COLLECTION_NAME"))
			{
				throw new Exception("A constante {$className}::COLLECTION_NAME precisa ser definida.", 'CAKE-MONGO-MISS-COLLECTION-NAME');
			}

			// Obtém o 4º argumento do construtor
			if(!defined("{$className}::DOCUMENT_CLASS"))
			{
				throw new Exception("A constante {$className}::DOCUMENT_CLASS precisa ser definida.", 'CAKE-MONGO-MISS-DOCUMENT');
			}

			Registry::set($className, new $className($mongoManager, $mongoConfig['database'], $className::COLLECTION_NAME, ['typeMap' => ['root' => $className::DOCUMENT_CLASS]]));
		}
		return Registry::get($className);
	}

	final public function save(Document $document)
	{
		// Verifica se o Document passado pertence à collection
		$className = get_called_class();
		$documentClass = $className::DOCUMENT_CLASS;
		if(!$document instanceof $documentClass)
		{
			throw new Exception("{$className}::save() só pode ser usado com um objeto do tipo {$documentClass}.", 'CAKE-MONGO-INVALID-DOCUMENT');
		}

		$documentId = $document->getId();
		$documentBson = $document->bsonSerialize();
		if($documentId)
		{
			$operations = ['$set' => $documentBson];

			$unsetted = $document->getUnsetted();
			if(!empty($unsetted))
			{
				$operations['$unset'] = $unsetted;
			}

			$this->updateOne(['_id' => $documentId], $operations);
		}
		else
		{
			$insertOneResult = $this->insertOne($documentBson);
			$document->setId($insertOneResult->getInsertedId());
		}

		return $this;
	}

	final public function delete(Document $document)
	{
		// Verifica se o Document passado pertence à collection
		$className = get_called_class();
		$documentClass = $className::DOCUMENT_CLASS;
		if(!$document instanceof $documentClass)
		{
			throw new Exception("{$className}::delete() só pode ser usado com um objeto do tipo {$documentClass}.", 'CAKE-MONGO-INVALID-DOCUMENT');
		}

		$documentId = $document->getId();
		$this->deleteOne(['_id' => $documentId]);

		return $this;
	}

	final public function newDocument(array $data = [])
	{
		$documentClass = static::DOCUMENT_CLASS;
		return new $documentClass($data);
	}

	final public function findById($id)
	{
		if(!$id instanceof \MongoDB\BSON\ObjectID)
		{
			$id = new \MongoDB\BSON\ObjectID($id);
		}
		return $this->findOne(['_id' => $id]);
	}

	final public function findOne($filter = [], array $options = [])
	{
		try
		{
			$result = parent::findOne($filter, $options);
		}
		catch(\Error $e)
		{
			if($e->getMessage() == 'Argument 3 passed to MongoDB\\Driver\\Server::executeQuery() must be an instance of MongoDB\\Driver\\ReadPreference or null, array given')
			{
				HttpStatusCode::set('INTERNAL_SERVER_ERROR');
				throw new Exception('É necessário atualizar a extensão mongodb para a versão 1.4 ou superior.', 'CAKE-SERVER-OUTDATED');
			}
			else
			{
				throw $e;
			}
		}
		return $result;
	}

	final public function aggregate(array $pipeline, array $options = [], bool $useAggregateResult = false)
	{
		if($useAggregateResult)
		{
			$options['typeMap'] = ['root' => '\CakeWeb\MongoDB\AggregateResult'];
		}
		return parent::aggregate($pipeline, $options);
	}

	protected function _findAndPaginate(array $filters): ?array
	{
		return $this->selectIds($filters);
	}

	// Esse método foi deixado no código-fonte para retrocompatibilidade
	// Novas implementações devem sobrescrever apenas o método _findAndPaginate
	public function selectIds(array $filters): ?array
	{
		return null;
	}

	final public function findAndPaginate(?array $filters = null, ?array $sort = null, ?int $page = null, ?int $perPage = null, ?array $pipeline = null): array
	{
		$query = \Flight::request()->query;
		if(is_null($filters))
		{
			// Obtém todos os filtros
			$filters = empty($query->filter) || $query->filter == '{}'
				? []
				: json_decode($query->filter, true);
		}
		if(is_null($sort))
		{
			// Obtém a ordenação desejada
			$sort = empty($query->sort) || $query->sort == '{}'
				? []
				: json_decode($query->sort, true);
		}
		if(is_null($page))
		{
			// Obtém a página desejada
			$page = (int)$query->page ?: 1;
		}
		if(is_null($perPage))
		{
			// Obtém a quantidade desejada
			$perPage = (int)$query->perPage ?: 12;
		}

		// Obtém todos os IDs que satisfazem os $filters informados
		$hasFilters = !empty($filters);
		if(isset($filters['search']) && $filters['search'] == '' && count($filters) == 1)
		{
			$hasFilters = false;
		}
		if($hasFilters)
		{
			if(!$ids = $this->_findAndPaginate($filters))
			{
				return [
					'currentPageItems' => [],
					'totalItems'=> 0,
					'perPage'=> $perPage,
					'page' => $page
				];
			}
			$match = [
				'_id' => [
					'$in' => $ids
				]
			];
			$totalItems = count($ids);
		}
		else
		{
			$match = [];
			$totalItems = $this->count();
		}

		// Obtém apenas os itens da página atual
		$sort = array_map('intval', $sort);
		if(!array_key_exists('_id', $sort))
		{
			$sort['_id'] = 1;
		}
		$skip = ($page - 1) * $perPage;
		if($pipeline)
		{
			if($hasFilters)
			{
				array_unshift($pipeline, [
					'$match' => $match
				]);
			}
			array_push($pipeline, [
				'$sort' => $sort
			], [
				'$limit' => $skip + $perPage // When a $sort immediately precedes a $limit in the pipeline, the $sort operation only maintains the top n results as it progresses, where n is the specified limit, and MongoDB only needs to store n items in memory.
			], [
				'$skip' => $skip
			]);
			$currentPageItems = $this->aggregate($pipeline)->toArray();
		}
		else
		{
			$currentPageItems = $this->find($match, [
				'sort' => $sort,
				'skip' => $skip,
				'limit' => $perPage
			])->toArray();
		}

		// O CakePaginator exige um retorno com as propriedades:
		// - currentPageItems
		// - totalItems
		// - perPage
		// - page
		return [
			'currentPageItems' => $currentPageItems,
			'totalItems'=> $totalItems,
			'perPage'=> $perPage,
			'page' => $page
		];
	}

	final public function varExport($var, bool $return = false, int $tabs = 0)
	{
		if(is_array($var))
		{
			$i = 0;
			$toImplode = [];
			$openIndent = str_repeat("\t", $tabs + 1);
			$closeIndent = str_repeat("\t", $tabs);
			foreach($var as $key => $value)
			{
				$valueString = $this->varExport($value, true, $tabs + 1);
				if($i === $key)
				{
					$toImplode[] = $openIndent . $valueString;
				}
				else
				{
					$toImplode[] = $openIndent . var_export($key, true) . ' => ' . $valueString;
				}
				$i++;
			}
			$code = '[' . PHP_EOL . implode(',' . PHP_EOL, $toImplode) . PHP_EOL . $closeIndent . ']';
			if($return)
			{
				return $code;
			}
			else
			{
				echo $code;
			}
		}
		elseif($return && is_null($var))
		{
			return 'null';
		}
		else
		{
			return var_export($var, $return);
		}
	}

	final public function mongoToPhp(string $mongo)
	{
		$openPosition = strpos($mongo, '[');
		if($openPosition === false)
		{
			return null;
		}
		$closePosition = strrpos($mongo, ']');
		if($closePosition === false)
		{
			return null;
		}
		$mongo = mb_substr($mongo, $openPosition, $closePosition - $openPosition + 1);
		$mongo = preg_replace('/ObjectId\((.+?)\)/', '{"$oid":$1}', $mongo);
		$mongo = json_decode($mongo, true);
		$php = "\$pipeline = [];";
		foreach($mongo as $i => $stage)
		{
			$n = $i + 1;
			$fn = array_keys($stage)[0];
			$array = $this->varExport($stage[$fn], true);
			$php .= <<<PHP


// Stage {$n}
\$pipeline[] = ['{$fn}' => {$array}];
PHP;
		}
		$php .= <<<PHP


\$aggregate = \$this->aggregate(\$pipeline, [], true);
PHP;
		die('<pre>' . $php . '</pre>');
	}

	final public function phpToMongo(array $php)
	{
		$className = get_called_class();
		$collectionName = $className::COLLECTION_NAME;
		$aggregateJson = json_encode($php, JSON_PRETTY_PRINT);
		$mongo = "db.getCollection(\"{$collectionName}\").aggregate({$aggregateJson});";
		die('<pre>' . $mongo . '</pre>');
	}
}