<?php

namespace CakeWeb\MongoDB;

class Transcoder
{
    public static function bsonEncode($input): string
    {
        $jsonSerializable = ($input instanceof \JsonSerializable || is_array($input));
        if(!$jsonSerializable)
        {
            throw new \Exception('bsonEncode() needs JsonSerializable or array as input');
        }
        $bson = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        // ObjectId
        $bson = preg_replace_callback('/\{\s+\"\$oid\"\: \"(.{24})\"\s+\}/', function($matches) {
            return "ObjectId(\"{$matches[1]}\")";
        }, $bson);

        // NumberInt
        $bson = preg_replace_callback('/^(\s+\".+\"\: )(\d+)(\,|$)/m', function($matches) {
            return "{$matches[1]}NumberInt({$matches[2]}){$matches[3]}";
        }, $bson);

        // ISODate
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $bson = preg_replace_callback('/\{\s+\"\$date\"\: \{\s+\"\$numberLong\"\: \"(\d+)\"\s+\}\s+\}/', function($matches) use($timezone) {
            $dateTime = \DateTime::createFromFormat('U', $matches[1] / 1000);
            $dateTime->setTimeZone($timezone);
            $isoDate = $dateTime->format('Y-m-d\TH:i:s.000O'); // 2018-01-15T18:30:15.000-0300
            return "ISODate(\"{$isoDate}\")";
        }, $bson);

        return $bson;
    }

    public static function varExport($var, bool $return = false, int $tabs = 0): string
    {
        if(is_array($var))
        {
            $i = 0;
            $toImplode = [];
            $openIndent = str_repeat("\t", $tabs + 1);
            $closeIndent = str_repeat("\t", $tabs);
            foreach($var as $key => $value)
            {
                $valueString = self::varExport($value, true, $tabs + 1);
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

    public static function mongoToPhp(string $mongo): void
    {
        $openPosition = strpos($mongo, '[');
        if($openPosition === false)
        {
            return;
        }
        $closePosition = strrpos($mongo, ']');
        if($closePosition === false)
        {
            return;
        }
        $mongoString = mb_substr($mongo, $openPosition, $closePosition - $openPosition + 1);
        $mongoString = preg_replace('/ObjectId\((.+?)\)/', '{"$oid":$1}', $mongoString);
        $mongoString = preg_replace('/(^\s*)([^\"\{\[\}\s]{1,})\:/m', '$1"$2":', $mongoString);
        $mongoArray = json_decode($mongoString, true);
        if(!is_array($mongoArray))
        {
            die("Fail to decode JSON <pre>{$mongoString}</pre> as PHP array.");
        }
        $php = "\$pipeline = [];";
        foreach($mongoArray as $i => $stage)
        {
            $n = $i + 1;
            $fn = array_keys($stage)[0];
            $array = self::varExport($stage[$fn], true);
            $php .= <<<PHP


// Stage {$n}
\$pipeline[] = ['{$fn}' => {$array}];
PHP;
        }
        $php .= <<<PHP


\$results = \$this->aggregate(\$pipeline, [], true)->toArray();
PHP;
        die('<pre>' . $php . '</pre>');
    }

    public static function phpToMongo(array $php, string $collectionName = 'collection_name'): void
    {
        $aggregateJson = self::bsonEncode($php);
        $mongo = "db.getCollection(\"{$collectionName}\").aggregate({$aggregateJson});";
        die('<pre>' . $mongo . '</pre>');
    }
}
