<?php

namespace App\Models;

use App\Enums\Integration;
use Database\Factories\IntegrationErrorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'affiliate_id',
    'integration',
    'action',
    'message',
    'exception_class',
    'occurrences',
    'last_occurred_at',
    'resolved_at',
])]
class IntegrationError extends Model
{
    /** @use HasFactory<IntegrationErrorFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'integration' => Integration::class,
            'occurrences' => 'integer',
            'last_occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * The error without the request body and timestamp prefix that some services add, for lists.
     */
    public function summary(): string
    {
        $summary = trim(explode(' Request body:', $this->message, 2)[0]);

        return trim(preg_replace('/^\[[^\]]+\]\s*/', '', $summary));
    }

    /**
     * The request body included in the error message, pretty-printed when it is JSON.
     */
    public function requestBody(): ?string
    {
        if (! str_contains($this->message, ' Request body:')) {
            return null;
        }

        $body = trim(explode(' Request body:', $this->message, 2)[1]);
        $json = json_decode($body, true);

        return is_array($json)
            ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;
    }
}
