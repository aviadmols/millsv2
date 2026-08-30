<?php

namespace App\Filament\Resources\DiscountRules\Pages;

use App\Filament\Resources\DiscountRules\DiscountRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDiscountRule extends EditRecord
{
    protected static string $resource = DiscountRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
