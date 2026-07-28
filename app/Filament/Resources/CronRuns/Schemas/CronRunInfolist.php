<?php

namespace App\Filament\Resources\CronRuns\Schemas;

use App\Models\CronRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** One scheduled run, in full — including the output the table has to truncate. */
class CronRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('cron.singular'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('command')->label(__('cron.command'))->fontFamily('mono'),

                        TextEntry::make('status')
                            ->label(__('cron.status'))
                            ->badge()
                            ->formatStateUsing(fn (string $state) => __('cron.status_'.$state) === 'cron.status_'.$state
                                ? $state
                                : __('cron.status_'.$state))
                            ->color(fn (string $state) => match ($state) {
                                'completed' => 'success',
                                'failed' => 'danger',
                                'skipped' => 'warning',
                                default => 'gray',
                            }),

                        TextEntry::make('ran_at')
                            ->label(__('cron.ran_at'))
                            ->dateTime('Y-m-d H:i:s')
                            ->helperText(fn (CronRun $record) => $record->ran_at?->diffForHumans()),

                        TextEntry::make('runtime_ms')
                            ->label(__('cron.runtime'))
                            ->formatStateUsing(fn (?int $state) => $state === null
                                ? '—'
                                : ($state < 1000 ? $state.' ms' : round($state / 1000, 1).' s')),
                    ]),

                Section::make(__('cron.output'))
                    ->schema([
                        TextEntry::make('output')
                            ->hiddenLabel()
                            ->placeholder(__('cron.no_output'))
                            // The whole thing, unwrapped — a truncated stack trace is no trace.
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
