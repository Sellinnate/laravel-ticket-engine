<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\Contact;

/**
 * @extends Factory<Contact>
 *
 * @internal
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = strtolower(fake()->unique()->safeEmail());

        // No dedupe_key: only ContactDirectory::remember() computes it, so a
        // factory row never collides with (or is found by) remember().
        return [
            'email' => $email,
            'name' => fake()->name(),
        ];
    }
}
