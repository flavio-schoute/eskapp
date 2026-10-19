<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A signal (budget exceeded, new or more expensive subscription, possible duplicate or unusual payment) that was
 * sent to Slack, or dismissed by someone. The key makes sure each signal is only sent once.
 */
#[Table('finance_alerts')]
#[Fillable(['key', 'type', 'message', 'notified_at', 'dismissed_at', 'dismissed_by'])]
class Alert extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }
}
