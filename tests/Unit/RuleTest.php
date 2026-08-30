<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\Rule;

final class RuleTest extends TestCase
{
    public function testFluentBuilderProducesOrderedRules(): void
    {
        $rule = Rule::required()
            ->email()
            ->min(3)
            ->max(255)
            ->confirmed();

        $expected = ['required', 'email', 'min:3', 'max:255', 'confirmed'];

        self::assertSame($expected, $rule->toArray());
        self::assertSame('required|email|min:3|max:255|confirmed', $rule->toString());
        self::assertSame($rule->toString(), (string) $rule);
    }

    public function testOptionalRuleStartsEmpty(): void
    {
        $rule = Rule::optional();

        self::assertSame([], $rule->toArray());
        self::assertSame('', $rule->toString());
    }

    public function testMergeAppendsRulesFromAnotherBuilder(): void
    {
        $rule = Rule::make()
            ->file()
            ->extensions(['jpg', 'png'])
            ->merge(
                Rule::make()
                    ->mimes(['image/jpeg', 'image/png'])
                    ->max(2048)
            );

        self::assertSame(
            ['file', 'extensions:jpg,png', 'mimes:image/jpeg,image/png', 'max:2048'],
            $rule->toArray()
        );
    }

    public function testInAndNotInRulesCastValuesToStrings(): void
    {
        $rule = Rule::make()
            ->in([1, 'two', true])
            ->notIn([0, false]);

        self::assertSame(['in:1,two,1', 'notIn:0,'], $rule->toArray());
    }
}