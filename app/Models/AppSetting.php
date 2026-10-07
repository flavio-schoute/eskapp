<?php

namespace App\Models;

use Database\Factories\AppSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Settings that can be changed in the panel, stored by key.
 */
#[Fillable(['key', 'value', 'updated_by'])]
class AppSetting extends Model
{
    /** @use HasFactory<AppSettingFactory> */
    use HasFactory;

    public const CreateInvoicesViaMollieApi = 'mollie.create_invoices_via_api';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /**
     * Get a setting's value, or the default when it has never been saved.
     */
    public static function value(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Save a setting's value.
     */
    public static function put(string $key, mixed $value, ?User $updatedBy = null): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $updatedBy?->getKey()]);
    }
}
