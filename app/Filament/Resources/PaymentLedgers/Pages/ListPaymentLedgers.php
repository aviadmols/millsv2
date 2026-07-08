<?php

namespace App\Filament\Resources\PaymentLedgers\Pages;

use App\Filament\Resources\PaymentLedgers\PaymentLedgerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentLedgers extends ListRecords
{
    protected static string $resource = PaymentLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
