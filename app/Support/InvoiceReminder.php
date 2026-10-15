<?php

namespace App\Support;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Models\Affiliate;
use App\Models\AppSetting;
use Illuminate\Support\Carbon;

/**
 * The monthly Slack reminder to send the affiliate invoices: its settings (changed under System → Slack settings)
 * and when it is due.
 */
class InvoiceReminder
{
    public const SettingKey = 'invoice_reminder';

    public const LastSentKey = 'invoice_reminder_last_sent';

    public const Timezone = 'Europe/Amsterdam';

    public const DefaultMessage = "Tijd om de affiliate facturen van {month} te versturen ({count} affiliates).\n\nOpen Monthly invoices: {link}";

    /**
     * The placeholders the reminder message can use.
     *
     * @var array<string, string>
     */
    public const Placeholders = [
        '{month}' => 'The month to invoice, e.g. september 2026',
        '{count}' => 'Number of active affiliates paid by invoice',
        '{link}' => 'Link to the Invoices page',
    ];

    /**
     * @return array{enabled: bool, channel: ?string, day: int, time: string, message: string}
     */
    public static function settings(): array
    {
        $saved = AppSetting::get(self::SettingKey, []);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? true),
            'channel' => $saved['channel'] ?? config('services.slack.notifications.channel'),
            'day' => (int) ($saved['day'] ?? 4),
            'time' => $saved['time'] ?? '09:00',
            'message' => $saved['message'] ?? self::DefaultMessage,
        ];
    }

    /**
     * @param  array{enabled: bool, channel: ?string, day: int|string, time: string, message: string}  $settings
     */
    public static function save(array $settings): void
    {
        AppSetting::put(self::SettingKey, [
            'enabled' => (bool) $settings['enabled'],
            'channel' => filled($settings['channel']) ? trim($settings['channel']) : null,
            'day' => (int) $settings['day'],
            'time' => substr((string) $settings['time'], 0, 5),
            'message' => $settings['message'],
        ]);
    }

    /**
     * Whether the reminder should be posted now: on the chosen day, from the chosen time, once a month.
     */
    public static function isDue(?Carbon $now = null): bool
    {
        $settings = self::settings();
        $now = ($now ?? now())->copy()->setTimezone(self::Timezone);

        return $settings['enabled']
            && filled($settings['channel'])
            && $now->day === $settings['day']
            && $now->format('H:i') >= $settings['time']
            && AppSetting::get(self::LastSentKey) !== $now->format('Y-m');
    }

    public static function markSent(?Carbon $now = null): void
    {
        AppSetting::put(self::LastSentKey, ($now ?? now())->copy()->setTimezone(self::Timezone)->format('Y-m'));
    }

    /**
     * The number of active affiliates paid by invoice: the {count} in the message.
     */
    public static function affiliateCount(): int
    {
        return Affiliate::query()
            ->where('status', AffiliateStatus::Active)
            ->where('payment_method', AffiliatePaymentMethod::Invoice)
            ->count();
    }

    /**
     * The message with its placeholders filled in.
     */
    public static function render(string $message, Carbon $month, int $affiliates, string $link): string
    {
        return strtr($message, [
            '{month}' => $month->copy()->locale('nl')->translatedFormat('F Y'),
            '{count}' => (string) $affiliates,
            '{link}' => $link,
        ]);
    }
}
