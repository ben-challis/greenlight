<?php

declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!\class_exists(TestCase::class)) {
        class TestCase {}
        class Assert {}
    }
}

namespace PHPUnit\Framework\Attributes {
    if (!\class_exists(Test::class)) {
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class Test {}
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class Before {}
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class After {}
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class DataProvider
        {
            public function __construct(public string $methodName) {}
        }
        #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
        final class TestWith
        {
            /**
             * @param array<mixed> $data
             */
            public function __construct(public array $data, public ?string $name = null) {}
        }
        #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
        final class Group
        {
            public function __construct(public string $name) {}
        }
        #[\Attribute(\Attribute::TARGET_CLASS)]
        final class CoversClass
        {
            public function __construct(public string $className) {}
        }
        #[\Attribute(\Attribute::TARGET_CLASS)]
        final class Small {}
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class RunInSeparateProcess {}
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class DoesNotPerformAssertions {}
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class RequiresPhpExtension
        {
            public function __construct(public string $extension, public ?string $versionRequirement = null) {}
        }
        #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
        final class Depends
        {
            public function __construct(public string $methodName) {}
        }
    }
}
