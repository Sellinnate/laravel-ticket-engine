<?php

declare(strict_types=1);

namespace Selli\Ticketing\Concerns;

/**
 * Typed access to the JSON `custom_fields` column.
 *
 * No Entity-Attribute-Value: a single JSON cast column holds values validated
 * on the way in against the owning TicketType's declared schema.
 */
trait HasCustomFields
{
    /**
     * Read a custom field value with an optional default.
     */
    public function customField(string $key, mixed $default = null): mixed
    {
        return data_get($this->custom_fields ?? [], $key, $default);
    }

    /**
     * Set a single custom field value (does not persist by itself).
     */
    public function setCustomField(string $key, mixed $value): static
    {
        $fields = $this->custom_fields ?? [];
        data_set($fields, $key, $value);
        $this->custom_fields = $fields;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function customFields(): array
    {
        return $this->custom_fields ?? [];
    }
}
