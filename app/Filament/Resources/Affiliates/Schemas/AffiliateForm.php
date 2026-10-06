<?php

namespace App\Filament\Resources\Affiliates\Schemas;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AffiliateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basics')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Partner name')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(AffiliateType::class)
                            ->required(),
                        Select::make('status')
                            ->options(AffiliateStatus::class)
                            ->default(AffiliateStatus::Active)
                            ->required(),
                    ]),
                Section::make('Access')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('login_url')
                            ->label('Login URL (dashboard / backend)')
                            ->url()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('username')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('Agreement')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('agreement')
                            ->label('Agreement (short and clear)')
                            ->placeholder("E.g. 20% recurring per sale.\nMonthly automatic payout.\nTracking via affiliate link.")
                            ->rows(4),
                        Select::make('payment_method')
                            ->label('Payment')
                            ->options(AffiliatePaymentMethod::class)
                            ->required(),
                    ]),
                Section::make('Links')
                    ->description('Optional, only if relevant.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('affiliate_link')
                            ->label('Affiliate / tracking link')
                            ->url()
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->label('Extra notes')
                            ->helperText('Max 1–2 lines, otherwise leave empty.')
                            ->rows(2),
                    ]),
            ]);
    }
}
