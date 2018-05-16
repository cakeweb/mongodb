<?php

namespace CakeWeb\MongoDB;

class AggregateResult extends Document
{
	public $data;

	public function hydratePropertyAs(string $propertyName, string $documentClass, bool $jsonSerialize = true)
	{
		$document = new $documentClass();
		$document->hydrate((array)$this->data[$propertyName]);
		$this->data[$propertyName] = $jsonSerialize
			? $document->jsonSerialize()
			: $document;
	}
}