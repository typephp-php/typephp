<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Tests\Fixtures\Generics\InlineTraitUseService;
use TypePHP\Tests\Fixtures\Services\BaseService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\ConstKeyContainer;
use TypePHP\Tests\Fixtures\Types\OffsetAccessContainer;
use TypePHP\Tests\Fixtures\Types\UserApi;

describe('SpecialTypeResolver Unit Tests', function () {
    describe('checkThisIdentity', function () {
        test('accepts identical $this instance when return type is $this', function () {
            $service = new UserService();
            $thisNode = new ThisTypeNode();

            $err = SpecialTypeResolver::checkThisIdentity($thisNode, $service, $service, 'testFunc');

            expect($err)->toBeNull();
        });

        test('returns ErrorMessage when returned object is not the same $this instance', function () {
            $service1 = new UserService();
            $service2 = new UserService();
            $thisNode = new ThisTypeNode();

            $err = SpecialTypeResolver::checkThisIdentity($thisNode, $service2, $service1, 'testFunc');

            expect($err)->toBeInstanceOf(ErrorMessage::class)
                ->and($err->getMessage())->toContain('must be $this instance')
            ;
        });

        test('ignores non-this return types cleanly', function () {
            $service = new UserService();
            $intNode = new IdentifierTypeNode('int');

            $err = SpecialTypeResolver::checkThisIdentity($intNode, 100, $service, 'testFunc');

            expect($err)->toBeNull();
        });
    });

    describe('resolve: Special Identifier Keywords (self, parent, static, $this)', function () {
        test('resolves self to declaring class FQCN', function () {
            $ref = new ReflectionMethod(UserService::class, 'find');
            $node = new IdentifierTypeNode('self');

            $resolved = SpecialTypeResolver::resolve($node, $ref);

            expect($resolved)->toBeInstanceOf(IdentifierTypeNode::class)
                ->and($resolved->name)->toBe(UserService::class)
            ;
        });

        test('resolves parent to parent class FQCN', function () {
            $ref = new ReflectionMethod(UserService::class, 'find');
            $node = new IdentifierTypeNode('parent');

            $resolved = SpecialTypeResolver::resolve($node, $ref);

            expect($resolved)->toBeInstanceOf(IdentifierTypeNode::class)
                ->and($resolved->name)->toBe(BaseService::class)
            ;
        });

        test('resolves $this and static to concrete calling instance class when thisObj is provided', function () {
            $service = new UserService();
            $ref = new ReflectionMethod(UserService::class, 'find');

            $thisNode = new IdentifierTypeNode('$this');
            $staticNode = new IdentifierTypeNode('static');

            expect(SpecialTypeResolver::resolve($thisNode, $ref, $service)->name)->toBe(UserService::class)
                ->and(SpecialTypeResolver::resolve($staticNode, $ref, $service)->name)->toBe(UserService::class)
            ;
        });

        test('resolves static to calling class in string method context (Class::method)', function () {
            $staticNode = new IdentifierTypeNode('static');
            $context = UserService::class . '::find';

            $resolved = SpecialTypeResolver::resolve($staticNode, $context);

            expect($resolved->name)->toBe(UserService::class);
        });

        test('leaves built-in scalar and pseudo-type keywords untouched', function () {
            $ref = new ReflectionMethod(UserService::class, 'find');
            $primitives = ['int', 'string', 'bool', 'float', 'array', 'mixed', 'void', 'positive-int', 'non-empty-string'];

            foreach ($primitives as $primitive) {
                $node = new IdentifierTypeNode($primitive);
                $resolved = SpecialTypeResolver::resolve($node, $ref);

                expect($resolved->name)->toBe($primitive);
            }
        });
    });

    describe('resolve: Class Constants (ConstTypeNode)', function () {
        test('resolves self::CONST and parent::CONST in ConstTypeNode', function () {
            $ref = new ReflectionMethod(ConstKeyContainer::class, 'process');

            $selfConst = new ConstTypeNode(new ConstFetchNode('self', 'KEY_ID'));
            $resolvedSelf = SpecialTypeResolver::resolve($selfConst, $ref);
            expect($resolvedSelf->constExpr->className)->toBe(ConstKeyContainer::class);

            $parentConst = new ConstTypeNode(new ConstFetchNode('parent', 'SOME_CONST'));
            $resolvedParent = SpecialTypeResolver::resolve($parentConst, $ref);
            expect($resolvedParent)->toBeInstanceOf(ConstTypeNode::class);
        });
    });

    describe('resolve: Offset Access (T[K])', function () {
        test('resolves offset on array shape and class constant array', function () {
            $ref = new ReflectionMethod(OffsetAccessContainer::class, 'setUserId');

            // 1. Array Shape Offset: array{id: positive-int}['id'] -> positive-int
            $shapeNode = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('positive-int')),
            ]);
            $offsetNode = new OffsetAccessTypeNode($shapeNode, new IdentifierTypeNode('id'));

            $resolved = SpecialTypeResolver::resolve($offsetNode, $ref);
            expect((string) $resolved)->toBe('positive-int');

            // 2. Class Constant Offset: OffsetAccessContainer::CONFIG_MAP['mysql'] -> literal 'PDO\MySQL\Driver'
            $constArray = new ConstTypeNode(new ConstFetchNode(OffsetAccessContainer::class, 'CONFIG_MAP'));
            $constOffset = new OffsetAccessTypeNode($constArray, new IdentifierTypeNode('mysql'));

            $resolvedConst = SpecialTypeResolver::resolve($constOffset, $ref);
            expect($resolvedConst)->toBeInstanceOf(ConstTypeNode::class);
        });
    });

    describe('resolve: Array Shapes with Constant Keys', function () {
        test('resolves self::KEY_ID constant key inside ArrayShapeNode', function () {
            $ref = new ReflectionMethod(ConstKeyContainer::class, 'process');

            $shapeNode = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(
                    new ConstFetchNode('self', 'KEY_ID'),
                    false,
                    new IdentifierTypeNode('positive-int')
                ),
            ]);

            $resolved = SpecialTypeResolver::resolve($shapeNode, $ref);

            expect($resolved)->toBeInstanceOf(ArrayShapeNode::class)
                ->and($resolved->items[0]->keyName->value)->toBe('user_id')
            ;
        });
    });

    describe('resolve: All Complex AST Branches', function () {
        test('recursively resolves Callables, Conditionals, Generics, Unions, Intersections, and Shapes', function () {
            $ref = new ReflectionMethod(UserService::class, 'find');

            $genericNode = new GenericTypeNode(new IdentifierTypeNode('Collection'), [new IdentifierTypeNode('self')]);
            $resGeneric = SpecialTypeResolver::resolve($genericNode, $ref);
            expect($resGeneric->genericTypes[0]->name)->toBe(UserService::class);

            $callableNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [new CallableTypeParameterNode(new IdentifierTypeNode('self'), false, false, 'item', false)],
                new IdentifierTypeNode('self'),
                []
            );
            $resCallable = SpecialTypeResolver::resolve($callableNode, $ref);
            expect($resCallable->returnType->name)->toBe(UserService::class);

            $conditionalNode = new ConditionalTypeNode(
                new IdentifierTypeNode('self'),
                new IdentifierTypeNode('self'),
                new IdentifierTypeNode('int'),
                new IdentifierTypeNode('string'),
                false
            );
            expect(SpecialTypeResolver::resolve($conditionalNode, $ref))->toBeInstanceOf(ConditionalTypeNode::class);

            $paramConditional = new ConditionalTypeForParameterNode(
                '$flag',
                new IdentifierTypeNode('true'),
                new IdentifierTypeNode('int'),
                new IdentifierTypeNode('string'),
                false
            );
            expect(SpecialTypeResolver::resolve($paramConditional, $ref))->toBeInstanceOf(ConditionalTypeForParameterNode::class);

            $nullable = new NullableTypeNode(new IdentifierTypeNode('self'));
            expect(SpecialTypeResolver::resolve($nullable, $ref)->type->name)->toBe(UserService::class);

            $array = new ArrayTypeNode(new IdentifierTypeNode('self'));
            expect(SpecialTypeResolver::resolve($array, $ref)->type->name)->toBe(UserService::class);

            $union = new UnionTypeNode([new IdentifierTypeNode('self'), new IdentifierTypeNode('int')]);
            expect(SpecialTypeResolver::resolve($union, $ref)->types[0]->name)->toBe(UserService::class);

            $intersection = new IntersectionTypeNode([new IdentifierTypeNode('self'), new IdentifierTypeNode('Countable')]);
            expect(SpecialTypeResolver::resolve($intersection, $ref)->types[0]->name)->toBe(UserService::class);

            $objShape = new ObjectShapeNode([new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('self'))]);
            expect(SpecialTypeResolver::resolve($objShape, $ref)->items[0]->valueType->name)->toBe(UserService::class);
        });
    });

    describe('resolveForFile: File Context Resolution', function () {
        test('resolves imported class names using file path context', function () {
            $filePath = (new ReflectionClass(UserApi::class))->getFileName();
            expect($filePath)->not()->toBeFalse();

            $node = new IdentifierTypeNode('GlobalTypes');
            $resolved = SpecialTypeResolver::resolveForFile($node, (string) $filePath);

            expect($resolved)->toBeInstanceOf(IdentifierTypeNode::class)
                ->and($resolved->name)->toBe('TypePHP\Tests\Fixtures\Types\GlobalTypes')
            ;
        });

        test('resolves complex AST branches with resolveForFile', function () {
            $filePath = (new ReflectionClass(UserApi::class))->getFileName();
            expect($filePath)->not()->toBeFalse();

            $generic = new GenericTypeNode(new IdentifierTypeNode('GlobalTypes'), [new IdentifierTypeNode('GlobalTypes')]);
            $resGeneric = SpecialTypeResolver::resolveForFile($generic, (string) $filePath);
            expect($resGeneric->type->name)->toBe('TypePHP\Tests\Fixtures\Types\GlobalTypes');

            $nullable = new NullableTypeNode(new IdentifierTypeNode('GlobalTypes'));
            expect(SpecialTypeResolver::resolveForFile($nullable, (string) $filePath)->type->name)->toBe('TypePHP\Tests\Fixtures\Types\GlobalTypes');

            $union = new UnionTypeNode([new IdentifierTypeNode('GlobalTypes'), new IdentifierTypeNode('int')]);
            expect(SpecialTypeResolver::resolveForFile($union, (string) $filePath)->types[0]->name)->toBe('TypePHP\Tests\Fixtures\Types\GlobalTypes');
        });
    });

    describe('Metadata Seeding & Trait DocBlocks', function () {
        test('seeds and extracts file namespace and use imports with slash normalization', function () {
            $windowsPath = 'C:\\project\\app\\Services\\UserService.php';
            SpecialTypeResolver::seedFileMetadata($windowsPath, 'App\\Services', ['User' => 'App\\Models\\User'], [
                'App\\Services\\UserService' => ['/** @use LoggerTrait<int> */'],
            ]);

            $forwardPath = 'C:/project/app/Services/UserService.php';

            expect(SpecialTypeResolver::getNamespaceFromFile($forwardPath))->toBe('App\\Services')
                ->and(SpecialTypeResolver::getUseImportsFromFile($forwardPath))->toHaveKey('User')
                ->and(SpecialTypeResolver::getClassTraitUseDocs('App\\Services\\UserService'))->toContain('/** @use LoggerTrait<int> */')
            ;
        });

        test('extracts inline trait use docblocks from unseeded class file directly', function () {
            $docs = SpecialTypeResolver::getClassTraitUseDocs(InlineTraitUseService::class);

            expect($docs)->toHaveCount(1)
                ->and($docs[0])->toContain('GenericItemLoggerTrait<Dog>')
            ;
        });
    });

    describe('resolveFqcn & resolveFqcnForFile', function () {
        test('un-prefixes leading backslashes on FQCNs', function () {
            $ref = new ReflectionMethod(UserService::class, 'find');

            expect(SpecialTypeResolver::resolveFqcn('\App\Models\User', $ref))->toBe('App\Models\User');
            expect(SpecialTypeResolver::resolveFqcnForFile('\App\Models\User', 'some_file.php'))->toBe('App\Models\User');
        });

        test('returns built-in type keywords untouched', function () {
            $ref = new ReflectionMethod(UserService::class, 'find');

            expect(SpecialTypeResolver::resolveFqcn('positive-int', $ref))->toBe('positive-int');
            expect(SpecialTypeResolver::resolveFqcnForFile('non-empty-string', 'some_file.php'))->toBe('non-empty-string');
        });
    });

    describe('parseFileMetadata Tokenizer Edge Cases', function () {
        test('extracts single, aliased, multi, and group use imports accurately', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\User;
use App\Models\Order as CustomerOrder;
use App\Contracts\{PaymentInterface, RefundInterface as Refund, Utilities\Formatter};
use App\Helpers\MathHelper, App\Helpers\StringHelper as Str;
use function App\Utils\calculateTotal as compute;
use const App\Config\MAX_ITEMS as LIMIT;

class CommerceService
{
    /**
     * @use LoggerTrait<User>
     */
    use LoggerTrait;

    public function process(): void
    {
        $callback = function () use ($userData) {
            // Must not be confused with class use import!
        };
    }
}
PHP;

            $virtualFile = 'VirtualCommerceService.php';
            SpecialTypeResolver::parseFileMetadata($virtualFile, $source);

            expect(SpecialTypeResolver::getNamespaceFromFile($virtualFile))->toBe('App\Services\Commerce');

            $imports = SpecialTypeResolver::getUseImportsFromFile($virtualFile);

            expect($imports)->toHaveKey('User')
                ->and($imports['User'])->toBe('App\Models\User')
            ;

            expect($imports)->toHaveKey('CustomerOrder')
                ->and($imports['CustomerOrder'])->toBe('App\Models\Order')
            ;

            expect($imports)->toHaveKey('PaymentInterface')
                ->and($imports['PaymentInterface'])->toBe('App\Contracts\PaymentInterface')
                ->and($imports)->toHaveKey('Refund')
                ->and($imports['Refund'])->toBe('App\Contracts\RefundInterface')
                ->and($imports)->toHaveKey('Formatter')
                ->and($imports['Formatter'])->toBe('App\Contracts\Utilities\Formatter')
            ;

            expect($imports)->toHaveKey('MathHelper')
                ->and($imports['MathHelper'])->toBe('App\Helpers\MathHelper')
                ->and($imports)->toHaveKey('Str')
                ->and($imports['Str'])->toBe('App\Helpers\StringHelper')
            ;

            expect($imports)->toHaveKey('compute')
                ->and($imports['compute'])->toBe('App\Utils\calculateTotal')
                ->and($imports)->toHaveKey('LIMIT')
                ->and($imports['LIMIT'])->toBe('App\Config\MAX_ITEMS')
            ;

            expect($imports)->not()->toHaveKey('userData')
                ->and($imports)->not()->toHaveKey('LoggerTrait')
            ;

            $traitDocs = SpecialTypeResolver::getClassTraitUseDocs('App\Services\Commerce\CommerceService');
            expect($traitDocs)->toHaveCount(1)
                ->and($traitDocs[0])->toContain('LoggerTrait<User>')
            ;
        });

        test('ignores use statements in docblock code examples and comments', function () {
            $source = <<<'PHP'
<?php

namespace App\Demo;

/**
 * Example DocBlock with fake imports:
 * use Fake\Example\ClassNotImported;
 */
// Comment: use Another\Fake\Import;
class SampleDemo
{
}
PHP;

            $virtualFile = 'VirtualSampleDemo.php';
            SpecialTypeResolver::parseFileMetadata($virtualFile, $source);

            $imports = SpecialTypeResolver::getUseImportsFromFile($virtualFile);

            expect($imports)->toBeEmpty();
        });
    });
});
