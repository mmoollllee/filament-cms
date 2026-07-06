<?php

namespace Mmoollllee\Cms\Support\Content;

use Mmoollllee\Cms\Models\LayoutPreset;

/**
 * Resolves LayoutPreset CSS classes by ID with request-scoped caching.
 *
 * Blade templates used to query LayoutPreset::whereIn() inline, causing
 * N+1 queries per page. This service preloads all preset IDs from the
 * block tree in a single query, then serves resolve() calls from cache.
 *
 * Called by the frontend controllers once per request before rendering. */
class LayoutPresetResolver
{
    /** @var array<int, string> Preset ID → CSS classes */
    protected array $cache = [];

    /**
     * Collect all preset IDs from a block tree and load them in one query.
     *
     * @param  array<int, array{type?: string, data?: array<string, mixed>}>  $blocks
     */
    public function preload(array $blocks): void
    {
        $ids = $this->collectPresetIds($blocks);
        $missing = array_diff($ids, array_keys($this->cache));

        if ($missing === []) {
            return;
        }

        $presets = LayoutPreset::whereIn('id', $missing)->pluck('classes', 'id');

        foreach ($presets as $id => $classes) {
            $this->cache[$id] = $classes;
        }
    }

    /**
     * Resolve CSS classes for the given preset IDs (from cache).
     *
     * @param  array<int, int>  $ids
     */
    public function resolve(array $ids): string
    {
        if ($ids === []) {
            return '';
        }

        return collect($ids)
            ->map(fn (int $id): string => $this->cache[$id] ?? '')
            ->filter()
            ->implode(' ');
    }

    /**
     * Recursively collect all layout_preset_ids from a block tree.
     *
     * Scans: layout_preset_ids, header_preset_ids, wrapper_preset_ids.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, int>
     */
    protected function collectPresetIds(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            $data = $block['data'] ?? [];

            foreach (['layout_preset_ids', 'header_preset_ids', 'wrapper_preset_ids'] as $field) {
                $fieldIds = array_map('intval', array_filter((array) ($data[$field] ?? [])));
                array_push($ids, ...$fieldIds);
            }

            // Recurse into child blocks (section nesting).
            if (isset($data['blocks']) && is_array($data['blocks'])) {
                array_push($ids, ...$this->collectPresetIds($data['blocks']));
            }
        }

        return array_unique($ids);
    }
}
