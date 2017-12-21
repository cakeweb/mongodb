<?php

namespace CakeWeb\MongoDB;

use CakeWeb\Iterator;
use CakeWeb\Exception;

abstract class Document implements \MongoDB\BSON\Persistable, \JsonSerializable
{
	protected $data = [];

	public function __construct(array $data = [])
	{
		foreach($data as $paramName => $paramValue)
		{
			$setter = 'set' . ucfirst($paramName);
			$this->$setter($paramValue);
		}
	}

	private function jsonRecursiveSerialize($var, $encode = true)
	{
		if(is_iterable($var))
		{
			foreach($var as &$_var)
			{
				$_var = ($_var instanceof JsonSerializable)
					? $_var->jsonSerialize()
					: $this->jsonRecursiveSerialize($_var, false);
			}
		}
		return $encode
			? json_encode($var)
			: $var;
	}

	public function jsonSerialize()
	{
		$data = $this->jsonRecursiveSerialize($this->data, false);
		$bson = \MongoDB\BSON\fromPHP($data);
		$json = \MongoDB\BSON\toJSON($bson);
		$array = json_decode($json, true);

		// Converte ['$oid' => '...'] para '...'
		Iterator::foreachArray($array, function(&$array, $depth) {
			if(isset($array['$oid']))
			{
				$array = $array['$oid'];
				return false; // se $array deixar de ser um array, deve-se retornar false
			}
			return true; // se $array continuar a ser um array, deve-se retornar true
		});

		// Troca a chave '_id' por 'id'
		$array = array_reverse($array, true);
		$array['id'] = $array['_id'];
		unset($array['_id']);
		return array_reverse($array, true);
	}

	final public function bsonSerialize()
	{
		// _created
		$dateTime = new \MongoDB\BSON\UTCDateTime(time() * 1000);
		if(!isset($this->data['_created']))
		{
			$this->data['_created'] = $dateTime;
		}

		// _updated
		$this->data['_updated'] = $dateTime;

		$this->data['__pclass'] = get_called_class();

		$data = Iterator::arrayClone($this->data);
		ksort($data, SORT_NATURAL);
		return $data;
	}

	final public function bsonUnserialize(array $data)
	{
		$this->data = $data;
	}

	final public function setId(\MongoDB\BSON\ObjectID $id)
	{
		$this->data['_id'] = $id;
	}

	final public function getId()
	{
		return isset($this->data['_id'])
			? $this->data['_id']
			: null;
	}

	final public function getCollection()
	{
		// Verifica se a constante COLLECTION_CLASS foi definida
		$className = get_called_class();
		if(!defined("{$className}::COLLECTION_CLASS"))
		{
			throw new Exception("A constante {$className}::COLLECTION_CLASS precisa ser definida.", 'CAKE-MONGO-MISS-COLLECTION');
		}

		$collectionClass = static::COLLECTION_CLASS;
		return $collectionClass::getInstance();
	}

	public function getCustomProperty(string $propertyName)
	{
		return $this->data[$propertyName] ?? null;
	}

	public function save()
	{
		$this->getCollection()->save($this);
		return $this;
	}

	public function delete()
	{
		$this->getCollection()->delete($this);
		return $this;
	}
}