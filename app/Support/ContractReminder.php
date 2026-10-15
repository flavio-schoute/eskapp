<?php

namespace App\Support;

use App\Filament\Pages\Contracts;
use App\Models\AffiliateContract;
use App\Models\AppSetting;

/**
 * The reminder for contracts that are still not signed after a few days: eSignatures emails the partner again
 * and a message is posted in the admin Slack channel. Changed under System → Slack settings.
 */
class ContractReminder
{
    public const SettingKey = 'contract_reminder';

    public const DefaultMessage = "Het contract van *{partner}* is na {days} dagen nog niet getekend door {signer} ({email}).\n\nBekijk de contracten: {link}";

    /**
     * The placeholders the reminder message can use.
     *
     * @var array<string, string>
     */
    public const Placeholders = [
        '{partner}' => 'The affiliate partner name',
        '{signer}' => 'Name of the signer at the partner',
        '{email}' => 'Email address of the signer',
        '{days}' => 'Days since the contract was sent',
        '{link}' => 'Link to the Contracts page',
    ];

    /**
     * @return array{enabled: bool, days: int, remind_partner: bool, channel: ?string, message: string}
     */
    public static function settings(): array
    {
        $saved = AppSetting::get(self::SettingKey, []);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? true),
            'days' => (int) ($saved['days'] ?? 5),
            'remind_partner' => (bool) ($saved['remind_partner'] ?? true),
            'channel' => $saved['channel'] ?? InvoiceReminder::settings()['channel'],
            'message' => $saved['message'] ?? self::DefaultMessage,
        ];
    }

    /**
     * @param  array{enabled: bool, days: int|string, remind_partner: bool, channel: ?string, message: string}  $settings
     */
    public static function save(array $settings): void
    {
        AppSetting::put(self::SettingKey, [
            'enabled' => (bool) $settings['enabled'],
            'days' => (int) $settings['days'],
            'remind_partner' => (bool) $settings['remind_partner'],
            'channel' => filled($settings['channel']) ? trim($settings['channel']) : null,
            'message' => $settings['message'],
        ]);
    }

    /**
     * The message with its placeholders filled in for a contract.
     */
    public static function render(string $message, AffiliateContract $contract, ?int $days = null): string
    {
        return strtr($message, [
            '{partner}' => (string) ($contract->fields['partner_name'] ?? $contract->affiliate?->name),
            '{signer}' => $contract->signer_name,
            '{email}' => $contract->signer_email,
            '{days}' => (string) ($days ?? (int) $contract->created_at->diffInDays(now(), true)),
            '{link}' => Contracts::getUrl(panel: 'admin'),
        ]);
    }
}
