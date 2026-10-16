<?php

namespace App\Models;

use Database\Factories\DebtorCaseEventFactory;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One moment in a debtor case's timeline: opened, charged back, reopened, resolved, or a note by a colleague.
 */
#[Fillable(['debtor_case_id', 'user_id', 'user_name', 'type', 'title', 'body', 'created_at'])]
class DebtorCaseEvent extends Model
{
    /** @use HasFactory<DebtorCaseEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const Opened = 'opened';

    public const ChargedBack = 'charged_back';

    public const Reopened = 'reopened';

    public const Resolved = 'resolved';

    public const Note = 'note';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DebtorCase, $this>
     */
    public function debtorCase(): BelongsTo
    {
        return $this->belongsTo(DebtorCase::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who added it: the colleague, or "Plug&Pay sync" for changes the app picked up itself.
     */
    public function causer(): string
    {
        return $this->user_name ?? 'Plug&Pay sync';
    }

    public function icon(): Heroicon
    {
        return match ($this->type) {
            self::Opened => Heroicon::OutlinedFolderOpen,
            self::ChargedBack => Heroicon::OutlinedArrowUturnLeft,
            self::Reopened => Heroicon::OutlinedArrowPath,
            self::Resolved => Heroicon::OutlinedCheckCircle,
            default => Heroicon::OutlinedChatBubbleBottomCenterText,
        };
    }

    public function color(): string
    {
        return match ($this->type) {
            self::ChargedBack, self::Reopened => 'danger',
            self::Resolved => 'success',
            self::Note => 'primary',
            default => 'gray',
        };
    }
}
