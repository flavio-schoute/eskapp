<?php

namespace App\Models\Finance;

use Database\Factories\Finance\RuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Categorizes new bank lines automatically. The first active rule (lowest priority) whose conditions all match wins.
 *
 * Conditions: [{field: counterparty|iban|description|mutation_code|account_id, op: equals|contains|starts_with|regex,
 * value}], [{field: direction, op: equals, value: in|out}] or [{field: amount, op: gte|lte|between, value, value2}]
 * (absolute cents). Action: {categoryId, vatRate, note} or {status: "excluded"}.
 */
#[Table('finance_rules')]
#[Fillable(['name', 'priority', 'active', 'conditions', 'action', 'hit_count', 'source'])]
class Rule extends Model
{
    /** @use HasFactory<RuleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'active' => 'boolean',
            'conditions' => 'array',
            'action' => 'array',
            'hit_count' => 'integer',
        ];
    }

    public function excludes(): bool
    {
        return ($this->action['status'] ?? null) === 'excluded';
    }
}
