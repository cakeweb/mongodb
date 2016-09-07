# cakeweb/mongodb

Instalação: ```composer require cakeweb/mongodb```

Exemplo de uso:
```php
use CakeWeb\MongoDB\Connection;
use CakeWeb\MongoDB\Collection;
use CakeWeb\MongoDB\Document;

class Usuarios extends Collection
{
	const COLLECTION_NAME = 'usuarios';
	const DOCUMENT_CLASS = 'Usuario';
}

class Usuario extends Document
{
	const COLLECTION_CLASS = 'Usuarios';

	public function setNome($nome)
	{
		$this->data['nome'] = $nome;
		return $this;
	}
}

try
{
	Connection::init('127.0.0.1:27017', 'data-db', 'user', 'pass', 'auth-db');

	// Collection
	$usuarios = Usuarios::getInstance();

	// Cadastra um Document na Collection
	$usuario = $usuarios->newDocument(); // ou $usuario = new Usuario();
	$usuario->setNome('Novo usuário');
	$usuario->save();
	// para obter o id recém-gerado: $usuario->getId();

	// Atualiza um Document da Collection
	$usuario = $usuarios->findOne(['_id' => new MongoDB\BSON\ObjectID('57ca3b4bc4105c277800435b')]);
	if($usuario)
	{
		$usuario->setNome('Novo nome');
		$usuario->save();
	}
}
catch(Exception $e)
{
	echo $e->getMessage();
}
```
