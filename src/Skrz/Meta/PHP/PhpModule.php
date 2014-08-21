<?php
namespace Skrz\Meta\PHP;

use Nette\PhpGenerator\ClassType;
use Skrz\Meta\AbstractMetaSpec;
use Skrz\Meta\AbstractModule;
use Skrz\Meta\MetaException;
use Skrz\Meta\MetaSpecMatcher;
use Skrz\Meta\Reflection\ArrayType;
use Skrz\Meta\Reflection\MixedType;
use Skrz\Meta\Reflection\ScalarType;
use Skrz\Meta\Reflection\Type;
use Skrz\Meta\Transient;

class PhpModule extends AbstractModule
{

	private $defaultGroups = array(null);

	public function addDefaultGroup($group)
	{
		if (!in_array($group, $this->defaultGroups, true)) {
			$this->defaultGroups[] = $group;
		}
	}

	public function onAdd(AbstractMetaSpec $spec, MetaSpecMatcher $matcher)
	{
		// nothing to do
	}

	public function onBeforeGenerate(AbstractMetaSpec $spec, MetaSpecMatcher $matcher, Type $type)
	{
		foreach ($type->getProperties() as $property) {
			if ($property->hasAnnotation(Transient::class)) {
				continue;
			}

			if ($property->isPrivate()) {
				throw new MetaException(
					"Private property '{$type->getName()}::\${$property->getName()}'. " .
					"Either make the property protected/public if you need to process it, " .
					"or mark it using @Transient annotation."
				);
			}

			if (get_class($property->getType()) === MixedType::class) {
				throw new MetaException(
					"Property {$type->getName()}::\${$property->getName()} of type mixed. " .
					"Either add @var annotation with non-mixed type, " .
					"or mark it using @Transient annotation."
				);
			}

			if (!$property->hasAnnotation(PhpArrayOffset::class)) {
				$annotations = $property->getAnnotations();

				$annotations[] = $arrayOffset = new PhpArrayOffset();
				$arrayOffset->offset = $property->getName();

				$property->setAnnotations($annotations);
			}
		}
	}

	public function onGenerate(AbstractMetaSpec $spec, MetaSpecMatcher $matcher, Type $type, ClassType $class)
	{
		$groups = array();
		$i = 0;

		foreach ($this->defaultGroups as $defaultGroup) {
			$groups[$defaultGroup] = 1 << $i++;
		}

		$ns = $class->getNamespace();

		$ns->addUse(PhpMetaInterface::class);
		$ns->addUse($type->getName(), null, $typeAlias);
		$class->addImplement(PhpMetaInterface::class);

		// get groups
		foreach ($type->getProperties() as $property) {
			$propertyGroups = array();
			foreach ($property->getAnnotations(PhpArrayOffset::class) as $arrayOffset) {
				/** @var PhpArrayOffset $arrayOffset */

				if (isset($propertyGroups[$arrayOffset->group])) {
					throw new MetaException("Property {$type->getName()}::\${$property->getName()} has more @PhpArrayOffset annotations referencing one group.");
				}

				$propertyGroups[$arrayOffset->group] = true;

				if (!isset($groups[$arrayOffset->group])) {
					$groups[$arrayOffset->group] = 1 << $i++;
				}
			}
		}

		$groupsProperty = $class->addProperty("groups");
		$groupsProperty->setStatic(true)->setValue($groups)->setVisibility("private");
		$groupsProperty
			->addDocument("Mapping from group name to group ID for fromArray() and toArray()")
			->addDocument("")
			->addDocument("@var string[]");

		foreach (array("Array", "Object") as $what) {
			// from*() method
			$from = $class->addMethod("from{$what}");
			$from->setStatic(true);
			$from->addParameter("input");
			$from->addParameter("group")->setOptional(true);
			$from->addParameter("object")->setOptional(true);

			$from
				->addDocument("Creates \\{$type->getName()} object from array")
				->addDocument("")
				->addDocument("@param " . strtolower($what) . " \$input")
				->addDocument("@param string \$group")
				->addDocument("@param {$typeAlias} \$object")
				->addDocument("")
				->addDocument("@throws \\InvalidArgumentException")
				->addDocument("")
				->addDocument("@return {$typeAlias}");

			if ($what === "Object") {
				$from->addBody("\$input = (array)\$input;\n");
			}

			$from
				->addBody("if (\$object === null) {")
				->addBody("\t\$object = new {$typeAlias}();")
				->addBody("} elseif (!(\$object instanceof {$typeAlias})) {")
				->addBody("\tthrow new \\InvalidArgumentException('You have to pass object of class {$type->getName()}.');")
				->addBody("}")
				->addBody("");

			// TODO: more groups - include/exclude
			$from
				->addBody("if (!isset(self::\$groups[\$group])) {")
				->addBody("\tthrow new \\InvalidArgumentException('Group \\'' . \$group . '\\' not supported for ' . " . var_export($type->getName(), true) . " . '.');")
				->addBody("} else {")
				->addBody("\t\$id = self::\$groups[\$group];")
				->addBody("}")
				->addBody("");

			foreach ($type->getProperties() as $property) {
				foreach ($property->getAnnotations(PhpArrayOffset::class) as $arrayOffset) {
					/** @var PhpArrayOffset $arrayOffset */
					$groupId = $groups[$arrayOffset->group];
					$arrayPath = "\$input[" . var_export($arrayOffset->offset, true) . "]";
					$objectPath = "\$object->{$property->getName()}";
					$from->addBody("if ((\$id & {$groupId}) > 0 && isset({$arrayPath})) {"); // FIXME: group group IDs by offset

					$baseType = $property->getType();
					$indent = "\t";
					$before = "";
					$after = "";
					for ($i = 0; $baseType instanceof ArrayType; ++$i) {
						$arrayType = $baseType;
						$baseType = $arrayType->getBaseType();


						$before .= "{$indent}if (!(isset({$objectPath}) && is_array({$objectPath}))) {\n";
						$before .= "{$indent}\t{$objectPath} = array();\n";
						$before .= "{$indent}}\n";
						$before .= "{$indent}foreach ((array){$arrayPath} as \$k{$i} => \$v{$i}) {\n";
						$after = "{$indent}}\n" . $after;
						$indent .= "\t";
						$arrayPath = "\$v{$i}";
						$objectPath .= "[\$k{$i}]";
					}

					if (!empty($before)) {
						$from->addBody(rtrim($before));
					}

					if ($baseType instanceof ScalarType) {
						$from->addBody("{$indent}{$objectPath} = {$arrayPath};");

					} elseif ($baseType instanceof Type) {
						$propertyTypeMetaClassName = $spec->createMetaClassName($baseType);
						$ns->addUse($propertyTypeMetaClassName, null, $propertyTypeMetaClassNameAlias);
						$from->addBody(
							"{$indent}{$objectPath} = {$propertyTypeMetaClassNameAlias}::from{$what}(" .
							"{$arrayPath}, " .
							"\$group, " .
							"isset({$objectPath}) ? {$objectPath} : null" .
							");"
						);

					} else {
						throw new MetaException("Unsupported property type " . get_class($baseType) . ".");
					}

					if (!empty($after)) {
						$from->addBody(rtrim($after));
					}

					$from
						->addBody("}");
				}

				$from->addBody("");
			}

			$from->addBody("return " . ($what === "Object" ? "(object)" : "") . "\$object;");

			// to*() method
			$to = $class->addMethod("to{$what}");
			$to->setStatic(true);
			$to->addParameter("object");
			$to->addParameter("group")->setOptional(true);

			$to
				->addDocument("Serializes \\{$type->getName()} to array")
				->addDocument("")
				->addDocument("@param {$typeAlias} \$object")
				->addDocument("@param string \$group")
				->addDocument("")
				->addDocument("@throws \\InvalidArgumentException")
				->addDocument("")
				->addDocument("@return array");

			$to
				->addBody("if (!(\$object instanceof {$typeAlias})) {")
				->addBody("\tthrow new \\InvalidArgumentException('You have to pass object of class {$type->getName()}.');")
				->addBody("}")
				->addBody("");

			// TODO: more groups - include/exclude
			$to
				->addBody("if (!isset(self::\$groups[\$group])) {")
				->addBody("\tthrow new \\InvalidArgumentException('Group \\'' . \$group . '\\' not supported for ' . " . var_export($type->getName(), true) . " . '.');")
				->addBody("} else {")
				->addBody("\t\$id = self::\$groups[\$group];")
				->addBody("}")
				->addBody("");

			$to
				->addBody("\$input = array();")
				->addBody("");

			foreach ($type->getProperties() as $property) {
				foreach ($property->getAnnotations(PhpArrayOffset::class) as $arrayOffset) {
					/** @var PhpArrayOffset $arrayOffset */
					$groupId = $groups[$arrayOffset->group];
					$to->addBody("if ((\$id & {$groupId}) > 0) {"); // FIXME: group group IDs by offset

					$objectPath = "\$object->{$property->getName()}";
					$arrayPath = "\$input[" . var_export($arrayOffset->offset, true) . "]";
					$baseType = $property->getType();
					$indent = "\t";
					$before = "";
					$after = "";
					for ($i = 0; $baseType instanceof ArrayType; ++$i) {
						$arrayType = $baseType;
						$baseType = $arrayType->getBaseType();

						$before .= "{$indent}if (!(isset({$arrayPath}) && is_array({$arrayPath}))) {\n";
						$before .= "{$indent}\t{$arrayPath} = array();\n";
						$before .= "{$indent}}\n";
						$before .= "{$indent}foreach ((array){$objectPath} as \$k{$i} => \$v{$i}) {\n";
						$after = "{$indent}}\n" . $after;
						$indent .= "\t";
						$arrayPath .= "[\$k{$i}]";
						$objectPath = "\$v{$i}";
					}

					if (!empty($before)) {
						$to->addBody(rtrim($before));
					}

					if ($baseType instanceof ScalarType) {
						$to->addBody("{$indent}{$arrayPath} = {$objectPath};");

					} elseif ($baseType instanceof Type) {
						$propertyTypeMetaClassName = $spec->createMetaClassName($baseType);
						$ns->addUse($propertyTypeMetaClassName, null, $propertyTypeMetaClassNameAlias);
						$to->addBody(
							"{$indent}{$arrayPath} = {$propertyTypeMetaClassNameAlias}::to{$what}(" .
							"{$objectPath}, " .
							"\$group" .
							");"
						);

					} else {
						throw new MetaException("Unsupported property type " . get_class($baseType) . ".");
					}

					if (!empty($after)) {
						$to->addBody(rtrim($after));
					}

					$to->addBody("}");
				}

				$to->addBody("");
			}

			$to->addBody("return " . ($what === "Object" ? "(object)" : "") . "\$input;");
		}
	}

}