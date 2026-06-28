<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Selli\Ticketing\Models\Tag;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * Honour a host-overridden model (Ticketing::useTagModel()).
     *
     * @return class-string<Tag>
     */
    public function modelName(): string
    {
        /** @var class-string<Tag> $model */
        $model = Ticketing::tagModel();

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['name' => $name, 'slug' => Str::slug($name)];
    }
}
