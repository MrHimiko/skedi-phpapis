<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Exec\SingleSelectExecutor;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\TokenType;

class ToCharFunction extends FunctionNode
{
    private Node $dateExpression;
    private Node $formatString;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->dateExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->formatString = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            "TO_CHAR(%s, %s)",
            $this->dateExpression->dispatch($sqlWalker),
            $this->formatString->dispatch($sqlWalker)
        );
    }

    public function getExecutor($queryComponentManager): SingleSelectExecutor
    {
        return new SingleSelectExecutor($this);
    }
}
