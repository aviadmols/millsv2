<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One scheduled-task execution (append-only log). Written by the ScheduledTask
 * event listeners; never updated.
 */
class CronRun extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'runtime_ms' => 'integer',
        ];
    }
}
