<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Modules\MillsSubscriptions\Services\Shopify\ProductSyncService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncFromShopify')
                ->label(__('products.sync_from_shopify'))
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(__('products.sync_confirm'))
                ->action(function (): void {
                    if (! app(ShopifyAdminClient::class)->isConnected()) {
                        Notification::make()
                            ->title(__('products.not_connected_title'))
                            ->body(__('products.not_connected_body'))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $count = app(ProductSyncService::class)->refreshAll();

                    if ($count === 0) {
                        Notification::make()
                            ->title(__('products.sync_zero_title'))
                            ->body(__('products.sync_zero_body'))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('products.synced_title'))
                        ->body(trans_choice('products.synced_body', $count, ['count' => $count]))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
