<?php

namespace Mmoollllee\Cms\Filament\Concerns;

trait TransfersBuilderItems
{
    use WithBuilderDataPath;

    /**
     * Transfer a builder item from one nested builder to another.
     *
     * Called from the published builder Blade view when a cross-list
     * SortableJS drag-and-drop is detected (items dragged between
     * Builder instances sharing the same x-sortable-group).
     *
     * @param  array{sourcePath: string, targetPath: string, sourceItems: list<string>, targetItems: list<string>}  $params
     */
    public function transferBuilderItem(array $params): void
    {
        $sourcePath = $this->toDataPath($params['sourcePath']);
        $targetPath = $this->toDataPath($params['targetPath']);
        $sourceItems = $params['sourceItems'];
        $targetItems = $params['targetItems'];

        $sourceState = data_get($this->data, $sourcePath) ?? [];
        $targetState = data_get($this->data, $targetPath) ?? [];

        // Identify the moved item: present in targetItems but not yet in targetState.
        $movedUuid = collect($targetItems)
            ->diff(array_keys($targetState))
            ->first();

        if (! $movedUuid || ! isset($sourceState[$movedUuid])) {
            return;
        }

        $movedData = $sourceState[$movedUuid];
        unset($sourceState[$movedUuid]);

        // Rebuild source in the order reported by SortableJS.
        $newSourceState = [];
        foreach ($sourceItems as $uuid) {
            if (isset($sourceState[$uuid])) {
                $newSourceState[$uuid] = $sourceState[$uuid];
            }
        }

        // Insert the moved item into target state, then rebuild in reported order.
        $targetState[$movedUuid] = $movedData;
        $newTargetState = [];
        foreach ($targetItems as $uuid) {
            if (isset($targetState[$uuid])) {
                $newTargetState[$uuid] = $targetState[$uuid];
            }
        }

        // Reassign the entire $this->data property so Livewire detects
        // the nested change and triggers a proper re-render.
        $data = $this->data;
        data_set($data, $sourcePath, $newSourceState);
        data_set($data, $targetPath, $newTargetState);
        $this->data = $data;
    }
}
