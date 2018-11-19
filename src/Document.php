<?php

namespace CakeWeb\MongoDB;

use CakeWeb\HelperArray;
use CakeWeb\Exception;

abstract class Document implements \MongoDB\BSON\Persistable, \JsonSerializable
{
	protected $unsetted = [];
	protected $data = [];

	public function __construct(array $data = [])
	{
		foreach($data as $paramName => $paramValue)
		{
			$setter = 'set' . ucfirst($paramName);
			$this->$setter($paramValue);
		}
	}

	public function hydrate(array $data): self
	{
		$this->data = $data;
		return $this;
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
		HelperArray::foreachArray($array, function(&$array, $depth) {
			if(isset($array['$oid']))
			{
				$array = $array['$oid'];
				return false; // se $array deixar de ser um array, deve-se retornar false
			}
			if(isset($array['$date']))
			{
				$dateTime = new \DateTime();
				$dateTime->setTimestamp($array['$date'] / 1000);
				if($dateTime)
				{
					$array = [
						'date' => $dateTime->format('d/m/Y'),
						'time' => $dateTime->format('H:i'),
						'timezone' => $dateTime->getTimezone()->getName()
					];
					return false;
				}
			}
			return true; // se $array continuar a ser um array, deve-se retornar true
		});

		// Troca a chave '_id' por 'id'
		$array = array_reverse($array, true);
		if(isset($array['_id']))
		{
			$array['id'] = $array['_id'];
			unset($array['_id']);
		}
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

		$data = HelperArray::arrayClone($this->data);
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

	final public function getId(bool $asString = false)
	{
		if(empty($this->data['_id']))
		{
			return null;
		}
		return $asString
			? (string)$this->data['_id']
			: $this->data['_id'];
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

	public function setCustomProperty(string $propertyName, $propertyValue): self
	{
		$this->data[$propertyName] = $propertyValue;
		return $this;
	}

	public function getCreated(): ?\DateTime
	{
		if(isset($this->data['_created']))
		{
			return $this->data['_created']->toDateTime();
		}
		if($id = $this->getId())
		{
			$created = new \DateTime();
			$created->setTimestamp($id->getTimestamp());
			return $created;
		}
		return null;
	}

	public function getTimeSinceCreated(): string
	{
		$dataCadastro = $this->getCreated();
		$intervalo = $dataCadastro->diff(new \DateTime());
		$intervaloTempo = '';
		if($intervalo->y > 0)
		{
			$intervaloTempo = $intervalo->y < 2
				? "{$intervalo->y} ano"
				: "{$intervalo->y} anos";
		}
		elseif($intervalo->m > 0)
		{
			$intervaloTempo = $intervalo->m < 2
				? "{$intervalo->m} mês"
				: "{$intervalo->m} meses";
		}
		elseif($intervalo->d > 0)
		{
			$intervaloTempo = $intervalo->d < 2
				? "{$intervalo->d} dia"
				: "{$intervalo->d} dias";
		}
		elseif($intervalo->h > 0)
		{
			$intervaloTempo = $intervalo->h < 2
				? "{$intervalo->h} hora"
				: "{$intervalo->h} horas";
		}
		else
		{
			$intervaloTempo = $intervalo->s < 2
				? "{$intervalo->s} segundo"
				: "{$intervalo->s} segundos";
		}
		return $intervaloTempo;
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

	public function getRawData(): array
	{
		return $this->data;
	}

	public function getUnsetted(): array
	{
		return $this->unsetted;
	}

	public function unsetAll(array $propertyNames): self
	{
		foreach($propertyNames as $propertyName)
		{
			$this->unset($propertyName);
		}
		return $this;
	}

	public function unset(string $propertyName): self
	{
		unset($this->data[$propertyName]);
		$this->unsetted[$propertyName] = 1;
		return $this;
	}

	public function isEmpty(string $something, int $minLength = 2): bool
	{
		return mb_strlen(trim($something)) < $minLength;
	}
}