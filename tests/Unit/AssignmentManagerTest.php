<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\AssignmentStrategy;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Routing\AssignmentManager;
use Selli\Ticketing\Routing\Strategies\LeastBusyStrategy;
use Selli\Ticketing\Routing\Strategies\ManualStrategy;
use Selli\Ticketing\Routing\Strategies\RoundRobinStrategy;
use Selli\Ticketing\Routing\Strategies\SkillBasedStrategy;

it('resolves the built-in strategies', function (): void {
    $manager = app(AssignmentManager::class);

    expect($manager->strategy('manual'))->toBeInstanceOf(ManualStrategy::class)
        ->and($manager->strategy('round-robin'))->toBeInstanceOf(RoundRobinStrategy::class)
        ->and($manager->strategy('least-busy'))->toBeInstanceOf(LeastBusyStrategy::class)
        ->and($manager->strategy('skill-based'))->toBeInstanceOf(SkillBasedStrategy::class);
});

it('falls back to the configured default strategy', function (): void {
    config()->set('ticketing.routing.default_strategy', 'least-busy');

    expect(app(AssignmentManager::class)->strategy())->toBeInstanceOf(LeastBusyStrategy::class);
});

it('allows a custom strategy to be registered', function (): void {
    $manager = app(AssignmentManager::class);

    $custom = new class implements AssignmentStrategy
    {
        public function assign(Ticket $ticket, Team $team): ?Model
        {
            return null;
        }
    };

    $manager->extend('custom', fn () => $custom);

    expect($manager->strategy('custom'))->toBe($custom);
});

it('throws for an unknown strategy', function (): void {
    app(AssignmentManager::class)->strategy('does-not-exist');
})->throws(InvalidConfigurationException::class);
