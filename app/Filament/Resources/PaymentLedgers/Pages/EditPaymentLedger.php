<?php

namespace App\Filament\Resources\PaymentLedgers\Pages;

use App\Filament\Resources\PaymentLedgers\PaymentLedgerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentLedger extends EditRecord
{
    protected static string $resource = PaymentLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
