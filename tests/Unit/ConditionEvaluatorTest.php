<?php

declare(strict_types=1);

use Selli\Ticketing\Automation\ConditionEvaluator;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;

function evalCond(array $conditions, string $match = 'all'): bool
{
    $ticket = Ticketing::open(
        type: 'support',
        title: 'x',
        requester: makeUser(),
        priority: Priority::High,
        category: 'billing',
    );

    return (new ConditionEvaluator)->matches($ticket->fresh(), $conditions, $match);
}

it('matches an empty condition set', fn () => expect(evalCond([]))->toBeTrue());

it('evaluates equality and inequality', function (): void {
    expect(evalCond([['field' => 'category', 'operator' => '=', 'value' => 'billing']]))->toBeTrue()
        ->and(evalCond([['field' => 'category', 'operator' => '!=', 'value' => 'sales']]))->toBeTrue()
        ->and(evalCond([['field' => 'category', 'operator' => '=', 'value' => 'sales']]))->toBeFalse();
});

it('evaluates numeric comparisons on priority', function (): void {
    $p = Priority::High->value; // 30
    expect(evalCond([['field' => 'priority', 'operator' => 'gt', 'value' => $p - 1]]))->toBeTrue()
        ->and(evalCond([['field' => 'priority', 'operator' => 'gte', 'value' => $p]]))->toBeTrue()
        ->and(evalCond([['field' => 'priority', 'operator' => 'lt', 'value' => $p]]))->toBeFalse()
        ->and(evalCond([['field' => 'priority', 'operator' => 'lte', 'value' => $p]]))->toBeTrue();
});

it('evaluates in / not_in', function (): void {
    expect(evalCond([['field' => 'priority_name', 'operator' => 'in', 'value' => ['high', 'urgent']]]))->toBeTrue()
        ->and(evalCond([['field' => 'priority_name', 'operator' => 'not_in', 'value' => ['low', 'normal']]]))->toBeTrue()
        ->and(evalCond([['field' => 'priority_name', 'operator' => 'in', 'value' => ['low']]]))->toBeFalse();
});

it('evaluates null checks and contains', function (): void {
    expect(evalCond([['field' => 'assignee_id', 'operator' => 'is_null', 'value' => null]]))->toBeTrue()
        ->and(evalCond([['field' => 'assignee_id', 'operator' => 'is_not_null', 'value' => null]]))->toBeFalse()
        ->and(evalCond([['field' => 'category', 'operator' => 'contains', 'value' => 'bill']]))->toBeTrue()
        ->and(evalCond([['field' => 'is_assigned', 'operator' => '=', 'value' => false]]))->toBeTrue();
});

it('resolves the type field', function (): void {
    expect(evalCond([['field' => 'type', 'operator' => '=', 'value' => 'support']]))->toBeTrue();
});

it('matches numeric and boolean fields against string values', function (): void {
    // Rule JSON commonly stores values as strings; they must still match.
    expect(evalCond([['field' => 'priority', 'operator' => '=', 'value' => '30']]))->toBeTrue()
        ->and(evalCond([['field' => 'priority', 'operator' => 'in', 'value' => ['20', '30']]]))->toBeTrue()
        ->and(evalCond([['field' => 'is_assigned', 'operator' => '=', 'value' => 'false']]))->toBeTrue();
});

it('fails closed on numeric comparisons with a non-numeric/null operand', function (): void {
    // assignee_id is null on a fresh ticket — must not coerce to 0 and match.
    expect(evalCond([['field' => 'assignee_id', 'operator' => 'gt', 'value' => 5]]))->toBeFalse()
        ->and(evalCond([['field' => 'priority', 'operator' => 'gt', 'value' => 'not-a-number']]))->toBeFalse();
});

it('fails closed on malformed equality, list and bool operands', function (): void {
    // A non-scalar = value, a non-list not_in value, an unparseable bool, and a
    // non-scalar contains value must all throw — not silently (mis)match,
    // especially through the !=/not_in negation.
    expect(fn () => evalCond([['field' => 'category', 'operator' => '=', 'value' => ['arr']]]))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => evalCond([['field' => 'category', 'operator' => 'not_in', 'value' => 'not-a-list']]))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => evalCond([['field' => 'is_assigned', 'operator' => '=', 'value' => 'maybe']]))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => evalCond([['field' => 'category', 'operator' => 'contains', 'value' => ['a']]]))
        ->toThrow(InvalidConfigurationException::class);
});

it('combines with all vs any', function (): void {
    $hit = ['field' => 'category', 'operator' => '=', 'value' => 'billing'];
    $miss = ['field' => 'category', 'operator' => '=', 'value' => 'sales'];

    expect(evalCond([$hit, $miss], 'all'))->toBeFalse()
        ->and(evalCond([$hit, $miss], 'any'))->toBeTrue();
});
