#!/usr/bin/env bash

# Create Directories
mkdir -p tests/TypeChecking/Boundaries
mkdir -p tests/TypeChecking/Scalars
mkdir -p tests/TypeChecking/ArraysAndShapes
mkdir -p tests/TypeChecking/Generics
mkdir -p tests/TypeChecking/CallablesAndIterators
mkdir -p tests/TypeChecking/InheritanceAndAttributes
mkdir -p tests/TypeChecking/Configuration

# Move Boundaries Tests
git mv tests/TypeChecking/ParamContractsTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/ReturnContractsTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/ExtendedReturnTypesTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/PropertyValidationTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/PropertyHooksTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/InlineVariableValidationTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/BlockScopeShadowingTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/ClosureVariableScopeTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/ListDestructuringTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/VarAnnotationPrebindingTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/ImportedFunctionsTest.php tests/TypeChecking/Boundaries/
git mv tests/TypeChecking/NamedArgumentsTest.php tests/TypeChecking/Boundaries/

# Move Scalars Tests
git mv tests/TypeChecking/ExtendedScalarTypesTest.php tests/TypeChecking/Scalars/
git mv tests/TypeChecking/FloatLiteralsTest.php tests/TypeChecking/Scalars/
git mv tests/TypeChecking/NewScalarAndPseudoTypesTest.php tests/TypeChecking/Scalars/
git mv tests/TypeChecking/UppercaseAndArrayKeyTest.php tests/TypeChecking/Scalars/
git mv tests/TypeChecking/IntMaskTest.php tests/TypeChecking/Scalars/

# Move ArraysAndShapes Tests
git mv tests/TypeChecking/ArrayAndListTypesTest.php tests/TypeChecking/ArraysAndShapes/
git mv tests/TypeChecking/UnionAndIntersectionTypesTest.php tests/TypeChecking/ArraysAndShapes/
git mv tests/TypeChecking/KeyOfValueOfTest.php tests/TypeChecking/ArraysAndShapes/
git mv tests/TypeChecking/OffsetAccessTest.php tests/TypeChecking/ArraysAndShapes/
git mv tests/TypeChecking/AdvancedTypesAndEnumsTest.php tests/TypeChecking/ArraysAndShapes/

# Move Generics Tests
git mv tests/TypeChecking/GenericsAndInheritanceTest.php tests/TypeChecking/Generics/
git mv tests/TypeChecking/DefaultTemplateTypesTest.php tests/TypeChecking/Generics/
git mv tests/TypeChecking/CloneGenericInstanceTest.php tests/TypeChecking/Generics/
git mv tests/TypeChecking/GenericPropertyHooksTest.php tests/TypeChecking/Generics/
git mv tests/TypeChecking/ConditionalTypesWithGenericsTest.php tests/TypeChecking/Generics/
git mv tests/TypeChecking/InheritedGenericCloneAndConditionalTest.php tests/TypeChecking/Generics/
git mv tests/TypeChecking/AdvancedGenericsAndShapesTest.php tests/TypeChecking/Generics/

# Move CallablesAndIterators Tests
git mv tests/TypeChecking/CallableAndClosureContractsTest.php tests/TypeChecking/CallablesAndIterators/
git mv tests/TypeChecking/LazyIteratorsAndGeneratorsTest.php tests/TypeChecking/CallablesAndIterators/

# Move InheritanceAndAttributes Tests
git mv tests/TypeChecking/OopInheritanceTest.php tests/TypeChecking/InheritanceAndAttributes/
git mv tests/TypeChecking/DeepInheritanceTest.php tests/TypeChecking/InheritanceAndAttributes/
git mv tests/TypeChecking/LiskovAndVendorIsolationTest.php tests/TypeChecking/InheritanceAndAttributes/
git mv tests/TypeChecking/AttributeConstructorInheritanceTest.php tests/TypeChecking/InheritanceAndAttributes/
git mv tests/TypeChecking/PhpAttributesCoexistenceTest.php tests/TypeChecking/InheritanceAndAttributes/
git mv tests/TypeChecking/NamespaceResolutionTest.php tests/TypeChecking/InheritanceAndAttributes/

# Move Configuration Tests
git mv tests/TypeChecking/BoundaryConfigTest.php tests/TypeChecking/Configuration/
git mv tests/TypeChecking/RespectIgnoreTagsConfigTest.php tests/TypeChecking/Configuration/
git mv tests/TypeChecking/DocblockIgnoreTagsTest.php tests/TypeChecking/Configuration/
git mv tests/TypeChecking/RecursionAndExceptionLeakTest.php tests/TypeChecking/Configuration/

echo "Reorganization Complete!"