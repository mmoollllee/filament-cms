<?php

namespace Mmoollllee\Cms\Fields;

use Filament\Schemas\Components\Component;

/**
 * Base for composable, fluent field kits.
 *
 * A kit groups related form fields (SEO, page header, …) behind a stable, named
 * API. The shared base set lives in {@see fields()}; central improvements there
 * reach every project that composes the kit. Each use site adapts the kit
 * fluently — without forking the shared definition:
 *
 *   SeoFields::make()
 *       ->without('noindex')
 *       ->extend([TextInput::make('meta.og_image')])
 *       ->toArray();
 *
 */
abstract class FieldKit
{
    /** @var array<int, string> */
    protected array $without = [];

    /** @var array<int, string>|null */
    protected ?array $only = null;

    /** @var array<int, mixed> */
    protected array $prepended = [];

    /** @var array<int, mixed> */
    protected array $appended = [];

    public static function make(): static
    {
        return new static;
    }

    /**
     * Named base fields, keyed by a stable identifier. Improve the kit for every
     * consumer at once by editing this set.
     *
     * @return array<string, Component>
     */
    abstract protected function fields(): array;

    public function without(string ...$keys): static
    {
        $this->without = [...$this->without, ...$keys];

        return $this;
    }

    /**
     * Restrict to the given fields, in the given order.
     */
    public function only(string ...$keys): static
    {
        $this->only = $keys;

        return $this;
    }

    /**
     * Append project-specific fields after the shared set.
     *
     * @param  array<int, Component>  $components
     */
    public function extend(array $components): static
    {
        $this->appended = [...$this->appended, ...$components];

        return $this;
    }

    /**
     * Prepend project-specific fields before the shared set.
     *
     * @param  array<int, Component>  $components
     */
    public function prepend(array $components): static
    {
        $this->prepended = [...$this->prepended, ...$components];

        return $this;
    }

    /**
     * Resolve the configured field list for use in a Filament `->schema([...])`.
     *
     * @return array<int, Component>
     */
    public function toArray(): array
    {
        $fields = $this->fields();

        if ($this->only !== null) {
            $ordered = [];

            foreach ($this->only as $key) {
                if (isset($fields[$key])) {
                    $ordered[$key] = $fields[$key];
                }
            }

            $fields = $ordered;
        }

        foreach ($this->without as $key) {
            unset($fields[$key]);
        }

        return [...$this->prepended, ...array_values($fields), ...$this->appended];
    }
}
