<?php

namespace CakeWeb\MongoDB;

use CakeWeb\Exception;
use CakeWeb\HelperArray;

abstract class Document implements \MongoDB\BSON\Persistable, \JsonSerializable
{
    protected $unsetted = [];
    protected $data = [];
    protected $i18nPropNames = null;
    protected $i18nPropValues = [];

    public function __construct(array $data = [], bool $useSetters = true)
    {
        foreach($data as $key => $value)
        {
            $setter = 'set' . preg_replace_callback('/(^|\_)(.{1})/', function($matches) {
                return strtoupper($matches[2]);
            }, $key);
            if(method_exists($this, $setter) && $useSetters)
            {
                $this->$setter($value);
            }
            else
            {
                $this->data[$key] = $value;
            }
        }
    }

    protected function _getUniqueId(): string
    {
        return $this->getId(true);
    }

    private function generateUniqueId(string $slug, int $n): string
    {
        return ($n === 1)
            ? $slug
            : "{$slug}-{$n}";
    }

    public function getUniqueId(bool $camelCase = false): string
    {
        if(!isset($this->data['unique_id']))
        {
            $slug = (new \CakeWeb\Filter\Slug)->filter($this->_getUniqueId());
            for(
                $n = 1;
                $this->getCollection()->findOne([
                    '_id' => ['$ne' => $this->getId()],
                    'unique_id' => $this->generateUniqueId($slug, $n)
                ]);
                $n++
            ) {}
            $this->data['unique_id'] = $this->generateUniqueId($slug, $n);
            $this->getCollection()->updateOne([
                '_id' => $this->getId()
            ], [
                '$set' => [
                    'unique_id' => $this->data['unique_id']
                ]
            ]);
        }
        return $camelCase
            ? preg_replace_callback('/(\-)(.{1})/', function($matches) {
                return strtoupper($matches[2]);
            }, $this->data['unique_id'])
            : $this->data['unique_id'];
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

        if($this->i18nPropNames)
        {
            $lang = \CakeWeb\I18n::getLang();
            foreach($this->i18nPropNames as $name)
            {
                if(isset($data[$name]) && is_string($data[$name]))
                {
                    if(!isset($this->i18nPropValues[$name]))
                    {
                        $this->i18nPropValues[$name] = (object)[];
                    }
                    $this->i18nPropValues[$name]->$lang = $data[$name];
                    $data[$name] = $this->i18nPropValues[$name];
                }
            }
        }

        return $data;
    }

    final public function bsonUnserialize(array $data)
    {
        if($this->i18nPropNames)
        {
            $lang = \CakeWeb\I18n::getLang();
            foreach($this->i18nPropNames as $name)
            {
                if(isset($data[$name]) && is_object($data[$name]))
                {
                    $this->i18nPropValues[$name] = $data[$name];
                    $data[$name] = $data[$name]->$lang ?? reset($data[$name]);
                }
            }
        }
        $this->data = $data;
    }

    final public function setId(\MongoDB\BSON\ObjectId $id): self
    {
        $this->data['_id'] = $id;
        return $this;
    }

    final public function getId(bool $asString = false)
    {
        if(empty($this->data['_id']))
        {
            return null;
        }
        if($asString)
        {
            return (string)$this->data['_id'];
        }
        if(!$this->data['_id'] instanceof \MongoDB\BSON\ObjectId)
        {
            return new \MongoDB\BSON\ObjectId($this->data['_id']);
        }
        return $this->data['_id'];
    }

    final public function getCollection(): Collection
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

    public function save(): self
    {
        $this->getCollection()->save($this);
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->data['deleted'] ?? false;
    }

    public function delete()
    {
        $collection = $this->getCollection();
        if($collection->softDelete)
        {
            $this->data['deleted'] = true;
            $collection->save($this);
        }
        else
        {
            $collection->delete($this);
        }
        return $this;
    }

    public function getRawData(): array
    {
        return $this->data;
    }

    public function getUnsetted(): array
    {
        foreach($this->unsetted as $propertyName => $int)
        {
            if(isset($this->data[$propertyName]))
            {
                $className = get_called_class();
                throw new Exception("A propriedade \"{$propertyName}\" existe no \$this->data da classe {$className} e por isso não pode ser excluída. Tente chamar a função unset(\$this->data['{$propertyName}']); dentro da Document.", 'CAKE-MONGO-INVALID-OPERATION');
            }
        }
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
