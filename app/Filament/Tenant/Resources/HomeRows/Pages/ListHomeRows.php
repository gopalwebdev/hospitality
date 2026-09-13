<?php

namespace App\Filament\Restaurant\Resources\HomeRows\Pages;

use App\Filament\Restaurant\Resources\HomeRows\HomeRowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * The home screen, arranged in the order the rows are dragged into.
 *
 * Rows are few and arranged against each other, so they are created in a modal
 * here — the same shape as ListMenus. Editing one opens its own page instead,
 * because that is where its tiles live.
 */
class ListHomeRows extends ListRecords
{
    protected static string $resource = HomeRowResource::class;

    public function getHeading(): string
    {
        return __('panel.rows.heading');
    }

    public function getSubheading(): string
    {
        return __('panel.rows.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.rows.create'))
                ->icon(Heroicon::OutlinedPlus),
        ];
    }
}
