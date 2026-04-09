<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\Set;

final class SetTest extends TestCase
{
    public function testAddPreventsDuplicateScalarsAndPreservesInsertionOrder(): void
    {
        $set = new Set();

        $set->add('alpha');
        $set->add('beta');
        $set->add('alpha');

        self::assertSame(2, $set->size());
        self::assertSame(['alpha', 'beta'], $set->values());
        self::assertTrue($set->has('alpha'));
    }

    public function testArraysAreComparedByValue(): void
    {
        $set = new Set();

        $set->add(['id' => 1]);
        $set->add(['id' => 1]);
        $set->add(['id' => 2]);

        self::assertSame(2, $set->size());
        self::assertSame([['id' => 1], ['id' => 2]], $set->values());
    }

    public function testObjectsAreComparedByIdentity(): void
    {
        $first = new \stdClass();
        $second = clone $first;

        $set = new Set();
        $set->add($first);
        $set->add($first);
        $set->add($second);

        self::assertSame(2, $set->size());
        self::assertSame([$first, $second], $set->values());
    }

    public function testDeleteAndClearRemoveValues(): void
    {
        $set = new Set();

        $set->add('alpha');
        $set->add('beta');
        $set->delete('alpha');

        self::assertFalse($set->has('alpha'));
        self::assertSame(['beta'], $set->values());

        $set->clear();

        self::assertSame(0, $set->size());
        self::assertSame([], $set->values());
    }
}