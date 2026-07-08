<?php

namespace App\Filament\Resources\PaymentLedgers;

use App\Filament\Resources\PaymentLedgers\Pages\CreatePaymentLedger;
use App\Filament\Resources\PaymentLedgers\Pages\EditPaymentLedger;
use App\Filament\Resources\PaymentLedgers\Pages\ListPaymentLedgers;
use App\Filament\Resources\PaymentLedgers\Schemas\PaymentLedgerForm;
use App\Filament\Resources\PaymentLedgers\Tables\PaymentLedgersTable;
use App\Models\PaymentLedger;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentLedgerResource extends Resource
{
    protected static ?string $model = PaymentLedger::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PaymentLedgerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentLedgersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentLedgers::route('/'),
            'create' => CreatePaymentLedger::route('/create'),
            'edit' => EditPaymentLedger::route('/{record}/edit'),
        ];
    }
}
