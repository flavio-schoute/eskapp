<?php

namespace App\Filament\Resources\Affiliates\RelationManagers;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Who changed what and when on this affiliate, also visible to viewers.
 */
class ActivityLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'activityLogs';

    protected static ?string $title = 'Activity';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return ActivityLogResource::activityTable($table)
            ->recordTitleAttribute('description');
    }
}
