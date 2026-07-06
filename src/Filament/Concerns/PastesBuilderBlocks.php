<?php

namespace Mmoollllee\Cms\Filament\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Support\Str;

trait PastesBuilderBlocks
{
    use WithBuilderDataPath;

    /**
     * Paste a builder block from clipboard JSON data into the builder state.
     *
     * Called from the published block-picker Blade view when the user
     * chooses "Aus Zwischenablage einfügen".
     */
    public function pasteBuilderBlock(string $statePath, string $jsonData, ?string $afterItem = null): void
    {
        $blockData = json_decode($jsonData, true);

        if (! is_array($blockData) || ! isset($blockData['type'], $blockData['data'])) {
            Notification::make()
                ->title('Ungültige Block-Daten in der Zwischenablage')
                ->warning()
                ->send();

            return;
        }

        $dataPath = $this->toDataPath($statePath);
        $state = data_get($this->data, $dataPath) ?? [];

        $newUuid = Str::uuid()->toString();
        $newItem = [
            'type' => $blockData['type'],
            'data' => $blockData['data'],
        ];

        if ($afterItem && isset($state[$afterItem])) {
            // Insert after the specified item
            $newState = [];

            foreach ($state as $uuid => $item) {
                $newState[$uuid] = $item;

                if ($uuid === $afterItem) {
                    $newState[$newUuid] = $newItem;
                }
            }

            $state = $newState;
        } else {
            // Append at the end
            $state[$newUuid] = $newItem;
        }

        // Reassign entire $this->data so Livewire detects the nested change.
        $data = $this->data;
        data_set($data, $dataPath, $state);
        $this->data = $data;

        Notification::make()
            ->title('Block eingefügt')
            ->success()
            ->send();
    }
}
