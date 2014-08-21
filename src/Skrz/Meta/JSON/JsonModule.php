<?php
namespace Skrz\Meta\JSON;

use Nette\PhpGenerator\ClassType;
use Skrz\Meta\AbstractMetaSpec;
use Skrz\Meta\AbstractModule;
use Skrz\Meta\MetaException;
use Skrz\Meta\MetaSpecMatcher;
use Skrz\Meta\PHP\PhpArrayOffset;
use Skrz\Meta\PHP\PhpModule;
use Skrz\Meta\Reflection\Type;
use Skrz\Meta\Transient;

class JsonModule extends AbstractModule
{

	public function onAdd(AbstractMetaSpec $spec, MetaSpecMatcher $matcher)
	{
		// depends on PhpModule
		$present = false;
		foreach ($matcher->getModules() as $module) {
			if ($module instanceof PhpModule) {
				$module->addDefaultGroup("json:" . JsonProperty::DEFAULT_GROUP);
				$present = true;
				break;
			}
		}

		if (!$present) {
			throw new MetaException(
				"JsonModule depends on PhpModule. Please add PhpModule first."
			);
		}
	}

	public function onBeforeGenerate(AbstractMetaSpec $spec, MetaSpecMatcher $matcher, Type $type)
	{
		foreach ($type->getProperties() as $property) {
			if ($property->hasAnnotation(Transient::class)) {
				continue;
			}

			$annotations = $property->getAnnotations();

			if (!$property->hasAnnotation(JsonProperty::class)) {
				if (!$property->hasAnnotation(PhpArrayOffset::class)) {
					$annotations[] = $arrayOffset = new PhpArrayOffset();
					$arrayOffset->offset = $property->getName();
				}

				$annotations[] = $jsonProperty = new JsonProperty();
				$jsonProperty->name = $property->getName();

				$annotations[] = $arrayOffset = new PhpArrayOffset();
				$arrayOffset->offset = $property->getName();
				$arrayOffset->group = "json:" . JsonProperty::DEFAULT_GROUP;

			} else {
				foreach ($property->getAnnotations(JsonProperty::class) as $jsonProperty) {
					/** @var JsonProperty $jsonProperty */
					$annotations[] = $arrayOffset = new PhpArrayOffset();
					$arrayOffset->offset = $jsonProperty->name;
					$arrayOffset->group = "json:" . $jsonProperty->group;
				}
			}

			$property->setAnnotations($annotations);
		}
	}

	public function onGenerate(AbstractMetaSpec $spec, MetaSpecMatcher $matcher, Type $type, ClassType $class)
	{
		$ns = $class->getNamespace();

		$ns->addUse(JsonMetaInterface::class);
		$ns->addUse($type->getName(), null, $typeAlias);
		$class->addImplement(JsonMetaInterface::class);

		// fromJson()
		$fromJson = $class->addMethod("fromJson");
		$fromJson->setStatic(true);
		$fromJson->addParameter("json");
		$fromJson->addParameter("group")->setOptional(true);
		$fromJson->addParameter("object")->setOptional(true);
		$fromJson
			->addDocument("Creates \\{$type->getName()} from JSON array / JSON serialized string")
			->addDocument("")
			->addDocument("@param array|string \$json")
			->addDocument("@param string \$group")
			->addDocument("@param {$typeAlias} \$object")
			->addDocument("")
			->addDocument("@throws \\InvalidArgumentException")
			->addDocument("")
			->addDocument("@return {$typeAlias}");

		$fromJson
			->addBody("if (\$object === null) {")
			->addBody("\t\$object = new {$typeAlias}();")
			->addBody("} elseif (!(\$object instanceof {$typeAlias})) {")
			->addBody("\tthrow new \\InvalidArgumentException('You have to pass object of class {$type->getName()}.');")
			->addBody("}")
			->addBody("");

		$fromJson
			->addBody("if (is_array(\$json)) {")
			->addBody("\t// ok, nothing to do here")
			->addBody("} elseif (is_string(\$json)) {")
			->addBody("\t\$decoded = json_decode(\$json, true);")
			->addBody("\tif (\$decoded === null && \$json !== '' && strcasecmp(\$json, 'null')) {")
			->addBody("\t\tthrow new \\InvalidArgumentException('Could not decode given JSON: ' . \$json . '.');")
			->addBody("\t}")
			->addBody("\t\$json = \$decoded;")
			->addBody("} else {")
			->addBody("\tthrow new \\InvalidArgumentException('Expected array, or string, ' . gettype(\$json) . ' given.');")
			->addBody("}")
			->addBody("");

		$fromJson
			->addBody("return self::fromObject(\$json, 'json:' . \$group, \$object);");

		// toJson()
		$toJson = $class->addMethod("toJson");
		$toJson->setStatic(true);
		$toJson->addParameter("object");
		$toJson->addParameter("group")->setOptional(true);
		$toJson
			->addDocument("Serializes \\{$type->getName()} to JSON array")
			->addDocument("")
			->addDocument("@param {$typeAlias} \$object")
			->addDocument("@param string \$group")
			->addDocument("")
			->addDocument("@throws \\InvalidArgumentException")
			->addDocument("")
			->addDocument("@return array");

		$toJson
			->addBody("return self::toObject(\$object, 'json:' . \$group);");

		// toJsonString()
		$toJsonString = $class->addMethod("toJsonString");
		$toJsonString->setStatic(true);
		$toJsonString->addParameter("object");
		$toJsonString->addParameter("group")->setOptional(true);
		$toJsonString
			->addDocument("Serializes \\{$type->getName()} to JSON string")
			->addDocument("")
			->addDocument("@param {$typeAlias} \$object")
			->addDocument("@param string \$group")
			->addDocument("")
			->addDocument("@throws \\InvalidArgumentException")
			->addDocument("")
			->addDocument("@return array");

		$toJsonString->addBody("return json_encode((object)self::toJson(\$object, \$group));");

		// toJsonStringPretty()
		$toJsonStringPretty = $class->addMethod("toJsonStringPretty");
		$toJsonStringPretty->setStatic(true);
		$toJsonStringPretty->addParameter("object");
		$toJsonStringPretty->addParameter("group")->setOptional(true);
		$toJsonStringPretty
			->addDocument("Serializes \\{$type->getName()} to JSON pretty string")
			->addDocument("")
			->addDocument("@param {$typeAlias} \$object")
			->addDocument("@param string \$group")
			->addDocument("")
			->addDocument("@throws \\InvalidArgumentException")
			->addDocument("")
			->addDocument("@return array");

		$toJsonStringPretty->addBody("return json_encode((object)self::toJson(\$object, \$group), JSON_PRETTY_PRINT);");
	}

}