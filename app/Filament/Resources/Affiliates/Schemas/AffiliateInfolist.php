<?php

namespace App\Filament\Resources\Affiliates\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AffiliateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basics')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->label('Partner name'),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                    ]),
                Section::make('Access')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('login_url')
                            ->label('Login URL')
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->placeholder('-'),
                        TextEntry::make('username')
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('password')
                            ->formatStateUsing(fn (): string => '••••••••')
                            ->copyable()
                            ->copyableState(fn (?string $state): ?string => $state)
                            ->copyMessage('Password copied')
                            ->placeholder('-'),
                    ]),
                Section::make('Agreement')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('agreement')
                            ->placeholder('-')
                            ->prose(),
                        TextEntry::make('payment_method')
                            ->label('Payment')
                            ->placeholder('-'),
                    ]),
                Section::make('Links')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('affiliate_link')
                            ->label('Affiliate / tracking link')
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->label('Extra notes')
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
