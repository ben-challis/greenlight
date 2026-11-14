<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use BcMath\Number;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Tests\Fixture\Expect\CollectionWithLabel;

use function Greenlight\expect;

final class NativeObjectEqualityTest
{
    #[Test]
    public function arrayObjectsCompareTheirStorage(): void
    {
        $this->expectStateComparison(new \ArrayObject([1]), new \ArrayObject([1]), new \ArrayObject([2]));
    }

    #[Test]
    public function arrayIteratorsCompareTheirStorage(): void
    {
        $this->expectStateComparison(new \ArrayIterator([1]), new \ArrayIterator([1]), new \ArrayIterator([2]));
    }

    #[Test]
    public function objectStoragesCompareTheirEntries(): void
    {
        $key = new \stdClass();

        $this->expectStateComparison(
            $this->storage($key, 'a'),
            $this->storage($key, 'a'),
            $this->storage($key, 'b'),
        );
    }

    #[Test]
    public function objectStoragesIgnoreTheAttachOrder(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $left = new \SplObjectStorage();
        $left[$first] = 1;
        $left[$second] = 2;
        $right = new \SplObjectStorage();
        $right[$second] = 2;
        $right[$first] = 1;

        expect($left)
            ->because('an object storage is a set, so the attach order MUST NOT affect equality')
            ->toEqual($right);

        $right[$first] = 3;

        expect($left)
            ->because('the data of each object MUST still count')
            ->not()->toEqual($right);
    }

    #[Test]
    public function objectStoragesThatContainThemselvesCompare(): void
    {
        expect($this->storageThatContainsItself('a'))
            ->because('a storage that contains itself MUST compare without endless recursion')
            ->toEqual($this->storageThatContainsItself('a'));
        expect($this->storageThatContainsItself('a'))->not()->toEqual($this->storageThatContainsItself('b'));
    }

    #[Test]
    public function objectStoragesThatReachThemselvesThroughAnEntryCompare(): void
    {
        expect($this->storageThatAnEntryRefersTo('a'))
            ->because('a storage that an entry refers back to MUST compare without endless recursion')
            ->toEqual($this->storageThatAnEntryRefersTo('a'));
        expect($this->storageThatAnEntryRefersTo('a'))->not()->toEqual($this->storageThatAnEntryRefersTo('b'));
    }

    #[Test]
    public function fixedArraysCompareTheirElements(): void
    {
        $this->expectStateComparison(
            \SplFixedArray::fromArray([1]),
            \SplFixedArray::fromArray([1]),
            \SplFixedArray::fromArray([2]),
        );
    }

    #[Test]
    public function stacksCompareTheirElements(): void
    {
        $this->expectStateComparison($this->stack(1), $this->stack(1), $this->stack(2));
    }

    #[Test]
    public function queuesCompareTheirElements(): void
    {
        $this->expectStateComparison($this->queue(1), $this->queue(1), $this->queue(2));
    }

    #[Test]
    public function heapsCompareTheirElementsAndKeepThem(): void
    {
        $heap = $this->heap(3, 1);

        $this->expectStateComparison($heap, $this->heap(1, 3), $this->heap(1, 2));

        expect($heap)
            ->because('equality reads a copy of the heap and keeps the original elements')
            ->toHaveCount(2);
    }

    #[Test]
    public function priorityQueuesCompareTheirPriorities(): void
    {
        $this->expectStateComparison(
            $this->priorityQueue('a', 1),
            $this->priorityQueue('a', 1),
            $this->priorityQueue('a', 2),
        );
    }

    #[Test]
    public function timeZonesCompareTheirName(): void
    {
        $this->expectStateComparison(
            new \DateTimeZone('UTC'),
            new \DateTimeZone('UTC'),
            new \DateTimeZone('Europe/London'),
        );
    }

    #[Test]
    #[SkipUnless(ClassAvailable::class, \GMP::class)]
    public function gmpNumbersCompareTheirValue(): void
    {
        $this->expectStateComparison(\gmp_init(1), \gmp_init(1), \gmp_init(2));
    }

    #[Test]
    #[SkipUnless(ClassAvailable::class, Number::class)]
    public function bcMathNumbersCompareTheirValue(): void
    {
        $this->expectStateComparison(
            new Number('1.5'),
            new Number('1.5'),
            new Number('2.5'),
        );
    }

    #[Test]
    #[SkipUnless(ClassAvailable::class, \DOMDocument::class)]
    public function domDocumentsAreEqualOnlyToThemselves(): void
    {
        $document = $this->document('<a/>');

        $this->expectIdentityOnly($document, $this->document('<a/>'), $this->document('<b/>'));
    }

    #[Test]
    public function weakMapsAreEqualOnlyToThemselves(): void
    {
        $key = new \stdClass();
        $left = new \WeakMap();
        $left[$key] = 1;
        $right = new \WeakMap();
        $right[$key] = 2;

        $this->expectIdentityOnly($left, new \WeakMap(), $right);
    }

    #[Test]
    public function generatorsAreEqualOnlyToThemselves(): void
    {
        $this->expectIdentityOnly($this->generator(1), $this->generator(1), $this->generator(2));
    }

    #[Test]
    public function exceptionsCompareTheirDeclaredProperties(): void
    {
        [$first, $same] = [$this->exception('a'), $this->exception('a')];

        $this->expectStateComparison($first, $same, $this->exception('b'));
    }

    #[Test]
    public function userSubclassesOfNativeClassesCompareNativeStateAndProperties(): void
    {
        $this->expectStateComparison(
            new CollectionWithLabel([1], 'label'),
            new CollectionWithLabel([1], 'label'),
            new CollectionWithLabel([2], 'label'),
        );

        expect(new CollectionWithLabel([1], 'label'))
            ->because('toEqual() MUST compare user properties of a native subclass')
            ->not()->toEqual(new CollectionWithLabel([1], 'other'));
    }

    #[Test]
    public function canonicalizingSortsNativeObjectsByTheirState(): void
    {
        expect([new \ArrayObject([1]), new \ArrayObject([2])])
            ->because('canonical sort keys MUST include native object state')
            ->toEqualCanonicalizing([new \ArrayObject([2]), new \ArrayObject([1])]);

        expect([new \ArrayObject([1]), new \ArrayObject([2])])
            ->because('canonicalizing equality MUST compare native object state')
            ->not()->toEqualCanonicalizing([new \ArrayObject([2]), new \ArrayObject([3])]);
    }

    private function expectStateComparison(object $value, object $equalCopy, object $different): void
    {
        expect($value)
            ->because(\sprintf('toEqual() MUST pass for %s values with equal native state', $value::class))
            ->toEqual($equalCopy);

        expect($value)
            ->because(\sprintf('toEqual() MUST fail for %s values with different native state', $value::class))
            ->not()->toEqual($different);
    }

    private function expectIdentityOnly(object $value, object $similar, object $different): void
    {
        expect($value)
            ->because(\sprintf('a %s value is equal to itself', $value::class))
            ->toEqual($value);

        expect($value)
            ->because(\sprintf('%s has no comparable state, so equality MUST fail closed', $value::class))
            ->not()->toEqual($similar);

        expect($value)
            ->because(\sprintf('%s has no comparable state, so equality MUST fail closed', $value::class))
            ->not()->toEqual($different);
    }

    /** @return \SplObjectStorage<object, string> */
    private function storage(object $key, string $data): \SplObjectStorage
    {
        $storage = new \SplObjectStorage();
        $storage[$key] = $data;

        return $storage;
    }

    /** @return \SplObjectStorage<object, string> */
    private function storageThatContainsItself(string $data): \SplObjectStorage
    {
        $storage = new \SplObjectStorage();
        $storage[$storage] = $data;

        return $storage;
    }

    /** @return \SplObjectStorage<object, string> */
    private function storageThatAnEntryRefersTo(string $data): \SplObjectStorage
    {
        $storage = new \SplObjectStorage();
        $entry = new \stdClass();
        $entry->owner = $storage;
        $storage[$entry] = $data;

        return $storage;
    }

    /** @return \SplStack<int> */
    private function stack(int $value): \SplStack
    {
        $stack = new \SplStack();
        $stack->push($value);

        return $stack;
    }

    /** @return \SplQueue<int> */
    private function queue(int $value): \SplQueue
    {
        $queue = new \SplQueue();
        $queue->enqueue($value);

        return $queue;
    }

    /** @return \SplMinHeap<int> */
    private function heap(int ...$values): \SplMinHeap
    {
        $heap = new \SplMinHeap();

        foreach ($values as $value) {
            $heap->insert($value);
        }

        return $heap;
    }

    /** @return \SplPriorityQueue<int, string> */
    private function priorityQueue(string $value, int $priority): \SplPriorityQueue
    {
        $queue = new \SplPriorityQueue();
        $queue->insert($value, $priority);

        return $queue;
    }

    /** @param non-empty-string $xml */
    private function document(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    /** @return \Generator<int, int> */
    private function generator(int $value): \Generator
    {
        yield $value;
    }

    private function exception(string $message): \RuntimeException
    {
        return new \RuntimeException($message);
    }
}
