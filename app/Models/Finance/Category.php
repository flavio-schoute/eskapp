<?php

namespace App\Models\Finance;

use App\Enums\Finance\CategoryKind;
use Database\Factories\Finance\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A main group (no parent) or a subcategory. Transactions always get a subcategory; the P&L adds them up per group.
 */
#[Table('finance_categories')]
#[Fillable(['parent_id', 'name', 'code', 'kind', 'fixed_variable', 'default_vat_rate', 'below_operating', 'revenue_stream_id', 'is_ad_spend', 'monthly_budget_cents', 'color', 'sort_order', 'archived'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
            'default_vat_rate' => 'float',
            'below_operating' => 'boolean',
            'is_ad_spend' => 'boolean',
            'monthly_budget_cents' => 'integer',
            'archived' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * The revenue stream these costs belong to, for the margin per stream.
     *
     * @return BelongsTo<RevenueStream, $this>
     */
    public function revenueStream(): BelongsTo
    {
        return $this->belongsTo(RevenueStream::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The categories a line can be booked on, grouped per main group: subcategories, or the group itself when it has
     * none (e.g. "Fiscale Kosten"). Archived categories are left out.
     *
     * @return array<string, array<int, string>>
     */
    public static function pickerOptions(): array
    {
        $categories = static::query()->where('archived', false)->orderBy('sort_order')->orderBy('name')->get();
        $options = [];

        foreach ($categories->whereNull('parent_id') as $group) {
            $children = $categories->where('parent_id', $group->getKey());
            $options[$group->name] = $children->isNotEmpty()
                ? $children->mapWithKeys(fn (Category $child): array => [$child->getKey() => $child->name])->all()
                : [$group->getKey() => $group->name];
        }

        return $options;
    }

    public function isGroup(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * "Group › Category", or just the name for a group.
     */
    public function label(): string
    {
        return $this->parent ? "{$this->parent->name} › {$this->name}" : $this->name;
    }
}
