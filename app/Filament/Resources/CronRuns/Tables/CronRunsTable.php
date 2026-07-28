<?php

namespace App\Filament\Resources\CronRuns\Tables;

use App\Models\CronRun;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * What the scheduler actually did, run by run.
 *
 * The table shipped with an empty `columns([])` — the scaffold nobody filled in — so the page
 * rendered rows of pure whitespace: every run was there, and not one of them was readable.
 * This is the screen you open to answer "is billing running, and how long does it take", so
 * the columns are the four facts that answer it.
 */
class CronRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ran_at')
                    ->label(__('cron.ran_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->description(fn (CronRun $record) => $record->ran_at?->diffForHumans())
                    ->sortable(),

                TextColumn::make('command')
                    ->label(__('cron.command'))
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
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

                TextColumn::make('runtime_ms')
                    ->label(__('cron.runtime'))
                    // Shown in the unit a human reads. A job that suddenly takes ten times as
                    // long is the first visible sign of trouble, and "8452" hides that.
                    ->formatStateUsing(fn (?int $state) => $state === null
                        ? '—'
                        : ($state < 1000 ? $state.' ms' : round($state / 1000, 1).' s'))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('output')
                    ->label(__('cron.output'))
                    ->limit(60)
                    ->placeholder('—')
                    ->tooltip(fn (CronRun $record) => $record->output)
                    ->toggleable(),
            ])
            // Newest first: this is a log, and the run anyone opens it for is the last one.
            ->defaultSort('ran_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cron.status'))
                    ->options([
                        'completed' => __('cron.status_completed'),
                        'failed' => __('cron.status_failed'),
                        'skipped' => __('cron.status_skipped'),
                    ]),

                SelectFilter::make('command')
                    ->label(__('cron.command'))
                    // Built from what has actually run, so the filter can never drift from
                    // the commands the scheduler really executes.
                    ->options(fn () => CronRun::query()
                        ->distinct()
                        ->orderBy('command')
                        ->pluck('command', 'command')
                        ->all()),
            ])
            ->recordActions([
                /*
                 * View, never Edit or Delete. These rows are an append-only record of what the
                 * scheduler did (the model itself says "never updated"). Editing one would be
                 * rewriting history, and the audit value of a log you can edit is zero.
                 */
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('cron.empty_heading'))
            ->emptyStateDescription(__('cron.empty_description'));
    }
}
