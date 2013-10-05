<?php

/*
 +--------------------------------------------------------------------------+
 | Zephir Language                                                          |
 +--------------------------------------------------------------------------+
 | Copyright (c) 2013 Zephir Team and contributors                          |
 +--------------------------------------------------------------------------+
 | This source file is subject the MIT license, that is bundled with        |
 | this package in the file LICENSE, and is available through the           |
 | world-wide-web at the following url:                                     |
 | http://zephir-lang.com/license.html                                      |
 |                                                                          |
 | If you did not receive a copy of the MIT license and are unable          |
 | to obtain it through the world-wide-web, please send a note to           |
 | license@zephir-lang.com so we can mail you a copy immediately.           |
 +--------------------------------------------------------------------------+
*/

/**
 * ReturnStatement
 *
 * Return statement is used to assign variables
 */
class ReturnStatement
{
	protected $_statement;

	public function __construct($statement)
	{
		$this->_statement = $statement;
	}

	/**
	 *
	 *
	 * @param \CompilationContext $compilationContext
	 */
	public function compile(CompilationContext $compilationContext)
	{

		$statement = $this->_statement;

		$codePrinter = $compilationContext->codePrinter;

		if (isset($statement['expr'])) {

			$currentMethod = $compilationContext->currentMethod;

			if ($currentMethod->isConstructor()) {
				throw new CompilerException("Constructors cannot return values", $statement['expr']);
			}

			if ($currentMethod->isVoid()) {
				throw new CompilerException("Method is marked as 'void' and it must not return any value", $statement['expr']);
			}

			/**
			 * Use return member for properties on this
			 */
			if ($statement['expr']['type'] == 'property-access') {
				if ($statement['expr']['left']['type'] == 'variable') {
					if ($statement['expr']['left']['value'] == 'this') {
						if ($statement['expr']['right']['type'] == 'variable') {

							/**
							 * If the property is accessed on 'this', we check if the property does exist
							 */
							$property = $statement['expr']['right']['value'];
							$classDefinition = $compilationContext->classDefinition;
							if (!$classDefinition->hasProperty($property)) {
								throw new CompilerException("Class '" . $classDefinition->getCompleteName() . "' does not have a property called: '" . $property . "'", $statement['expr']['right']);
							}

							$compilationContext->headersManager->add('kernel/object');
							$codePrinter->output('RETURN_MEMBER(this_ptr, "' . $property . '");');
							return;
						}
					}
				}
			}

			$variable = $compilationContext->symbolTable->getVariable('return_value');

			$expr = new Expression($statement['expr']);
			$expr->setExpectReturn(true, $variable);
			$expr->setReadOnly(true);
			$resolvedExpr = $expr->compile($compilationContext);

			/**
			 * Here we check if the variable returns a compatible type according to its type hints
			 */
			$expectedValid = false;
			$returnTypes = $currentMethod->getReturnTypes();
			if (is_array($returnTypes)) {
				if ($resolvedExpr->getType() == 'variable') {
					$symbolVariable = $compilationContext->symbolTable->getVariableForRead($resolvedExpr->getCode(), $compilationContext, $statement['expr']);
				}
				foreach ($returnTypes as $returnType => $returnDefinition) {
					if ($resolvedExpr->getType() == $returnType) {
						$expectedValid = true;
						break;
					} else {
						if ($resolvedExpr->getType() == 'variable') {
							//if ($returnType)
						}
						echo $returnType, ' ', $resolvedExpr->getType(), PHP_EOL;
					}
				}
			}

			$classTypes = $currentMethod->getReturnClassTypes();
			if ($classTypes) {
				/*switch ($resolvedExpr->getType()) {
					case 'int':
					case 'uint':
					case 'long':
					case 'char':
					case 'uchar':
					case 'bool':
					case 'double':
					case 'string':
						$compilationContext->logger->warning("Method's return hint specifies return an object of class/interface: " .
							$classType['value'] . ' but method returns: ' . $resolvedExpr->getType(), "invalid-return-type", $statement['expr']);
				}*/
			}

			switch ($resolvedExpr->getType()) {
				case 'null':
					$codePrinter->output('RETURN_MM_NULL();');
					break;
				case 'int':
				case 'uint':
				case 'long':
				case 'char':
				case 'uchar':
					$codePrinter->output('RETURN_MM_LONG(' . $resolvedExpr->getCode() . ');');
					break;
				case 'bool':
					$codePrinter->output('RETURN_MM_BOOL(' . $resolvedExpr->getBooleanCode() . ');');
					break;
				case 'double':
					$codePrinter->output('RETURN_MM_DOUBLE(' . $resolvedExpr->getCode() . ');');
					break;
				case 'string':
					$codePrinter->output('RETURN_MM_STRING("' . $resolvedExpr->getCode() . '", 1);');
					break;
				case 'variable':
					if (!isset($symbolVariable)) {
						$symbolVariable = $compilationContext->symbolTable->getVariableForRead($resolvedExpr->getCode(), $compilationContext, $statement['expr']);
					}
					switch ($symbolVariable->getType()) {
						case 'int':
						case 'uint':
						case 'long':
						case 'char':
						case 'uchar':
							$codePrinter->output('RETURN_MM_LONG(' . $symbolVariable->getName() . ');');
							break;
						case 'double':
							$codePrinter->output('RETURN_MM_DOUBLE(' . $symbolVariable->getName() . ');');
							break;
						case 'string':
							$codePrinter->output('RETURN_CTOR(' . $resolvedExpr->getCode() . ');');
							break;
						case 'bool':
							$codePrinter->output('RETURN_MM_BOOL(' . $symbolVariable->getName() . ');');
							break;
						case 'variable':
							if ($symbolVariable->getName() == 'this_ptr') {
								$codePrinter->output('RETURN_THIS();');
							} else {
								if ($symbolVariable->getName() != 'return_value') {
									if ($symbolVariable->isLocalOnly()) {
										$codePrinter->output('RETURN_LCTOR(' . $symbolVariable->getName() . ');');
									} else {
										if (!$symbolVariable->isMemoryTracked()) {
											$codePrinter->output('RETURN_CTOR(' . $symbolVariable->getName() . ');');
										} else {
											$codePrinter->output('RETURN_CCTOR(' . $symbolVariable->getName() . ');');
										}
									}
								} else {
									$codePrinter->output('RETURN_MM();');
								}
							}
							break;
						default:
							throw new CompilerException("Cannot return variable '" . $symbolVariable->getType() . "'", $statement['expr']);
					}
					break;
				default:
					throw new CompilerException("Cannot return '" . $resolvedExpr->getType() . "'", $statement['expr']);
			}

			return;
		}

		/**
		 * Return without an expression
		 */
		$codePrinter->output('RETURN_MM_NULL();');
	}

}