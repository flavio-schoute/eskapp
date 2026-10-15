<?php

namespace App\Models;

use App\Enums\ContractStatus;
use Database\Factories\AffiliateContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'affiliate_id',
    'created_by',
    'esignatures_contract_id',
    'status',
    'signer_name',
    'signer_email',
    'sign_page_url',
    'is_test',
    'fields',
    'drive_file_id',
    'drive_url',
    'viewed_at',
    'signed_at',
    'status_checked_at',
])]
class AffiliateContract extends Model
{
    /** @use HasFactory<AffiliateContractFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'is_test' => 'boolean',
            'fields' => 'array',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'status_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Contracts that are still out for signature.
     *
     * @param  Builder<AffiliateContract>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [ContractStatus::Sent, ContractStatus::Viewed]);
    }

    /**
     * Signed contracts whose PDF is not stored in Google Drive yet.
     *
     * @param  Builder<AffiliateContract>  $query
     */
    #[Scope]
    protected function signedWithoutDriveFile(Builder $query): void
    {
        $query->where('status', ContractStatus::Signed)->whereNull('drive_file_id');
    }
}
