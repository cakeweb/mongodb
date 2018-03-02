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
			$this->updateOne(
				['_id' => $documentId],
				['$set' => $documentBson]
			);
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
}