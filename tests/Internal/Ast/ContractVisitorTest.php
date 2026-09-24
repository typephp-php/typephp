<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use TypePHP\Internal\Ast\ContractVisitor;
use TypePHP\Internal\Ast\TypePHPPrinter;
use TypePHP\Internal\Util\Config;

describe('ContractVisitor AST Transformation Unit Tests', function () {
    beforeEach(function () {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new TypePHPPrinter();
    });

    test('transforms compound assignment operators (*=, +=, -=) into checkVariable wrappers', function () {
        $code = <<<'PHP'
<?php
/** @var positive-int $x */
$x = 10;
$x *= 2;
$x += 5;
$x -= 3;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkVariable($x * 2, \'positive-int\', \'x\'')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkVariable($x + 5, \'positive-int\', \'x\'')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkVariable($x - 3, \'positive-int\', \'x\'')
        ;
    });

    test('transforms increment and decrement operators (++, --) into checkVariable wrappers', function () {
        $code = <<<'PHP'
<?php
/** @var positive-int $val */
$val = 1;
$val++;
--$val;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkVariable($val + 1, \'positive-int\', \'val\'')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkVariable($val - 1, \'positive-int\', \'val\'')
        ;
    });

    test('transforms direct property and static property assignments into checkProperty wrappers', function () {
        $code = <<<'PHP'
<?php
class TestProp {
    public function set() {
        $this->count = 2;
        self::$staticCount = 10;
        static::$staticCount2 = 20;
    }
}
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkProperty(2, $this, \'count\'')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkProperty(10, self::class, \'staticCount\'')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkProperty(20, static::class, \'staticCount2\'')
        ;
    });

    test('transforms property compound assignments into checkProperty wrappers', function () {
        $code = <<<'PHP'
<?php
$this->count *= 2;
self::$staticCount += 10;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkProperty($this->count * 2, $this, \'count\'')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkProperty(self::$staticCount + 10, self::class, \'staticCount\'')
        ;
    });

    test('transforms clone expressions into prepareClone and cloneInstance wrappers', function () {
        $code = <<<'PHP'
<?php
$copy = clone $original;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::cloneInstance')
            ->and($transformed)->toContain('RuntimeTypeChecker::prepareClone($original)')
        ;
    });

    test('transforms array and list destructuring assignments (list() and []) with inline @var', function () {
        $code = <<<'PHP'
<?php
/**
 * @var positive-int $id
 * @var non-empty-string $name
 */
[$id, [$name]] = $data;

/**
 * @var float $score
 */
list($score) = $metrics;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkVariable($id, \'positive-int\', \'id\'')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkVariable($name, \'non-empty-string\', \'name\'')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkVariable($score, \'float\', \'score\'')
        ;
    });

    test('transforms return statements with unnamed inline @var', function () {
        $code = <<<'PHP'
<?php
function testReturn() {
    /** @var non-empty-string */
    return 'hello';
}
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkVariable(\'hello\', \'non-empty-string\', \'return\'')
            ->and($transformed)->toContain('typephpVal = \TypePHP\Internal\RuntimeTypeChecker::checkVariable')
        ;
    });

    test('extracts @var from foreach loops and enforces types on loop variables', function () {
        $code = <<<'PHP'
<?php
/** @var positive-int $item */
foreach ($data as $item) {
    $item = -5; // This assignment should be wrapped!
}
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkVariable(-5, \'positive-int\', \'item\'');
    });

    test('resolves correct caller and $this context in instance methods, static methods, and global functions', function () {
        $code = <<<'PHP'
<?php
namespace App\Tests;

class ContextTest {
    public function instanceMethod() {
        /** @var int $a */
        $a = 1;
        
        $closure = function() {
            /** @var int $c */
            $c = 1;
        };
    }

    public static function staticMethod() {
        /** @var int $b */
        $b = 1;
        
        $arrow = static fn() => true;
    }
}

function globalFunc() {
    /** @var int $g */
    $g = 1;
}

$anon = new class() {
    public function anonMethod() {
        /** @var int $anonVar */
        $anonVar = 1;
    }
};
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain("'App\\Tests\\ContextTest::instanceMethod', \$this");
        expect($transformed)->toContain("'App\\Tests\\ContextTest::staticMethod', null");
        expect($transformed)->toContain("'App\\Tests\\globalFunc', null");
        expect($transformed)->toContain("'App\\Tests\\ContextTest::instanceMethod', \$this");
        expect($transformed)->toContain("__CLASS__ . '::anonMethod', \$this");
    });

    test('tracks all scope boundaries correctly (if, while, for, try) without leaking variables', function () {
        $code = <<<'PHP'
<?php
/** @var int $outer */
$outer = 1;

if (true) {
    /** @var string $outer */
    $outer = 'str';
} elseif (false) {
    /** @var float $outer */
    $outer = 1.5;
} else {
    /** @var bool $outer */
    $outer = false;
}

while (false) {
    /** @var array $outer */
    $outer = [];
}

do {
    /** @var object $outer */
    $outer = new stdClass();
} while (false);

for ($i = 0; $i < 1; $i++) {
    /** @var resource $outer */
    $outer = fopen('php://memory', 'r');
}

try {
    /** @var mixed $outer */
    $outer = null;
} catch (Exception $e) {
    /** @var callable $outer */
    $outer = 'strlen';
}

$outer = 2; // Should revert to 'int' from the outermost scope
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkVariable(2, \'int\', \'outer\'');
    });

    test('tracks interfaces, traits, and enums safely', function () {
        $code = <<<'PHP'
<?php
interface MyInterface {}
trait MyTrait {
    public function traitMethod() {
        /** @var int $t */
        $t = 1;
    }
}
enum MyEnum {
    case A;
    public function enumMethod() {
        /** @var int $e */
        $e = 1;
    }
}
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('RuntimeTypeChecker::checkVariable(1, \'int\', \'t\', __FILE__, \'MyTrait::traitMethod\', $this)')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkVariable(1, \'int\', \'e\', __FILE__, \'MyEnum::enumMethod\', $this)')
        ;
    });

    test('leaves untyped variable assignments and operations untouched', function () {
        $code = <<<'PHP'
<?php
$untyped = 10;
$untyped *= 2;
$untyped++;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkVariable');
    });

    test('transforms dynamic class static property compound assignments and inc/dec expressions', function () {
        $code = <<<'PHP'
<?php
$className::$staticCount += 5;
($getObj())::$staticCount++;
$className::$staticCount2--;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain("RuntimeTypeChecker::checkProperty(\$className::\$staticCount + 5, \$className, 'staticCount'")
            ->and($transformed)->toContain("RuntimeTypeChecker::checkProperty(\$getObj()::\$staticCount + 1, \$getObj(), 'staticCount'")
            ->and($transformed)->toContain("RuntimeTypeChecker::checkProperty(\$className::\$staticCount2 - 1, \$className, 'staticCount2'")
        ;
    });

    test('bypasses already wrapped clone nodes and unsupported assign op AST nodes', function () {
        $cloneNode = new \PhpParser\Node\Expr\Clone_(new \PhpParser\Node\Expr\Variable('orig'));
        $cloneNode->setAttribute('typephp_wrapped', true);

        $visitor = new ContractVisitor();
        expect($visitor->leaveNode($cloneNode))->toBeNull();

        $customAssignOp = new class (
            new \PhpParser\Node\Expr\Variable('x'),
            new \PhpParser\Node\Scalar\LNumber(1)
        ) extends \PhpParser\Node\Expr\AssignOp {
            public function getType(): string
            {
                return 'Expr_CustomAssignOp';
            }

            public function getSubNodeNames(): array
            {
                return ['var', 'expr'];
            }
        };

        expect($visitor->leaveNode($customAssignOp))->toBeNull();
    });

    test('transforms dynamic class static property read expressions into checkStaticProperty calls', function () {
        $code = <<<'PHP'
<?php
$val = $className::$staticProperty;
$val2 = ($getObj())::$staticProperty;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain("RuntimeTypeChecker::checkStaticProperty(\$className, 'staticProperty', \$className::\$staticProperty")
            ->and($transformed)->toContain("RuntimeTypeChecker::checkStaticProperty(\$getObj(), 'staticProperty', \$getObj()::\$staticProperty")
        ;
    });

    test('skips class property defaults processing when inline properties are disabled in config', function () {
        Config::set(['inline_vars' => ['properties' => false]]);

        $code = <<<'PHP'
<?php
class DisabledPropClass {
    /** @var int */
    public int $count = 10;
}
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkProperty');
    });

    test('handles bare return statements and malformed @var return docblocks gracefully', function () {
        $code = <<<'PHP'
<?php
function testBareReturn() {
    /** @var string */
    return;
}

function testMalformedVarReturn() {
    /** @var */
    return 42;
}
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('return;')
            ->and($transformed)->toContain('return 42;')
        ;
    });

    test('handles top-level bare return statements without expressions', function () {
        $code = <<<'PHP'
<?php
/** @var string */
return;
PHP;

        $stmts = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ContractVisitor());
        $newStmts = $traverser->traverse($stmts);

        $transformed = $this->printer->prettyPrint($newStmts);

        expect($transformed)->toContain('return;');
    });
});
