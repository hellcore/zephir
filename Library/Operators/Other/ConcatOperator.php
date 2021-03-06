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
 * ConcatOperator
 *
 * Perform concatenations and optimizations
 */
class ConcatOperator extends BaseOperator
{

	public function compile($expression, CompilationContext $compilationContext)
	{

		if (!isset($expression['left'])) {
			throw new Exception("Missing left part of the expression");
		}

		if (!isset($expression['right'])) {
			throw new Exception("Missing right part of the expression");
		}

		$compilationContext->headersManager->add('kernel/concat');

		$leftExpr = new Expression($expression['left']);
		switch ($expression['left']['type']) {
			case 'array-access':
			case 'property-access':
				$leftExpr->setReadOnly(true);
				break;
			default:
				$leftExpr->setReadOnly($this->_readOnly);
				break;
		}
		$left = $leftExpr->compile($compilationContext);

		if ($left->getType() == 'variable') {
			$variableLeft = $compilationContext->symbolTable->getVariableForRead($left->getCode(), $compilationContext, $expression['right']);
		}

		$rightExpr = new Expression($expression['right']);
		switch ($expression['left']['type']) {
			case 'array-access':
			case 'property-access':
				$rightExpr->setReadOnly(true);
				break;
			default:
				$rightExpr->setReadOnly($this->_readOnly);
				break;
		}
		$right = $rightExpr->compile($compilationContext);

		if ($right->getType() == 'variable') {
			$variableLeft = $compilationContext->symbolTable->getVariableForRead($right->getCode(), $compilationContext, $expression['right']);
		}

		$expected = $this->getExpectedComplexLiteral($compilationContext, $expression);

		if ($left->getType() == 'string' && $right->getType() == 'variable') {
			$compilationContext->codePrinter->output('ZEPHIR_CONCAT_SV(' . $expected->getName() . ', "' . $left->getCode() . '", ' . $right->getCode() . ');');
		}

		if ($left->getType() == 'variable' && $right->getType() == 'string') {
			$compilationContext->codePrinter->output('ZEPHIR_CONCAT_VS(' . $expected->getName() . ', ' . $left->getCode() . ', "' . $right->getCode() . '");');
		}

		if ($left->getType() == 'variable' && $right->getType() == 'variable') {
			$compilationContext->codePrinter->output('concat_function(' . $expected->getName() . ', ' . $left->getCode() . ', ' . $right->getCode() . ' TSRMLS_CC);');
		}

		$expected->setDynamicTypes('string');

		return new CompiledExpression('variable', $expected->getName(), $expression);
	}

}
