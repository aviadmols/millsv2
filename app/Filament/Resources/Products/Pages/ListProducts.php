<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Jobs\ImportShopProductsJob;
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

                    /*
                     * Queued, not run here.
                     *
                     * A full sweep walks every product AND every page of its variants —
                     * minutes of Shopify calls. Doing that inside the browser request meant
                     * the button simply hung until something timed out, with no way to tell
                     * a slow sync from a broken one. The worker already has a job for this;
                     * the screen's business is to say the work started.
                     */
                    ImportShopProductsJob::dispatch();

                    Notification::make()
                        ->title(__('products.sync_queued_title'))
                        ->body(__('products.sync_queued_body'))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
