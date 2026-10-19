<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Finance signals posted in the #finance Slack channel: budgets exceeded, new or more expensive subscriptions and
 * possible duplicate or unusual payments, with a message per kind. Changed under System → Slack settings.
 */
class FinanceAlerts
{
    public const SettingKey = 'finance_alerts';

    public const DefaultChannel = 'C09GF1F3XTQ';

    /**
     * The message per kind of signal, with its placeholders and example values for the preview and the test message.
     *
     * @var array<string, array{label: string, message: string, placeholders: array<string, string>, example: array<string, string>}>
     */
    public const Kinds = [
        'intro' => [
            'label' => 'Kop en afsluiting',
            'message' => "*Finance-signalen*\n{signalen}\n\nBekijk ze: {link}",
            'placeholders' => ['{signalen}' => 'De signalen, elk op een eigen regel', '{link}' => 'Link naar Finance → Signalen'],
            'example' => [],
        ],
        'budget' => [
            'label' => 'Budget overschreden',
            'message' => 'Budget overschreden: *{categorie}* staat deze maand op {besteed} van {budget}',
            'placeholders' => ['{categorie}' => 'De categorie', '{besteed}' => 'Uitgegeven deze maand', '{budget}' => 'Het maandbudget'],
            'example' => ['{categorie}' => 'Marketing › Adspend Google', '{besteed}' => '€ 1.250', '{budget}' => '€ 1.000'],
        ],
        'subscription_new' => [
            'label' => 'Nieuw abonnement',
            'message' => 'Nieuw abonnement: *{partij}*, {bedrag} per maand',
            'placeholders' => ['{partij}' => 'De tegenpartij', '{bedrag}' => 'Bedrag per maand'],
            'example' => ['{partij}' => 'Fathom.video', '{bedrag}' => '€ 49,00'],
        ],
        'subscription_price' => [
            'label' => 'Abonnement duurder',
            'message' => 'Duurder: *{partij}* van {oud} naar {nieuw}',
            'placeholders' => ['{partij}' => 'De tegenpartij', '{oud}' => 'Vorige bedrag', '{nieuw}' => 'Nieuwe bedrag'],
            'example' => ['{partij}' => 'Notion Labs', '{oud}' => '€ 10,00', '{nieuw}' => '€ 12,00'],
        ],
        'duplicate' => [
            'label' => 'Mogelijk dubbel betaald',
            'message' => 'Mogelijk dubbel betaald: {bedrag} aan *{partij}* op {datum} en {datum2}',
            'placeholders' => ['{bedrag}' => 'Het bedrag', '{partij}' => 'De tegenpartij', '{datum}' => 'Datum eerste betaling', '{datum2}' => 'Datum tweede betaling'],
            'example' => ['{bedrag}' => '€ 450,00', '{partij}' => 'Smid online marketing', '{datum}' => '2026-10-01', '{datum2}' => '2026-10-04'],
        ],
        'payment_open' => [
            'label' => 'Bankbetaling, order staat nog open',
            'message' => 'Betaald per bank maar open in Plug & Pay: {bedrag} van *{partij}* op {datum} hoort bij {order}. Zet de order op betaald, anders telt de omzet niet mee.',
            'placeholders' => ['{bedrag}' => 'Het bedrag', '{partij}' => 'Wie betaalde', '{datum}' => 'De datum', '{order}' => 'Factuurnummer van de order'],
            'example' => ['{bedrag}' => '€ 1.798,48', '{partij}' => 'L. de Vries', '{datum}' => '2026-10-12', '{order}' => 'ESKOOL202602188'],
        ],
        'payment_unmatched' => [
            'label' => 'Klantbetaling zonder order',
            'message' => 'Klantbetaling zonder Plug & Pay-order: {bedrag} van *{partij}* op {datum}. Maak de order aan of boek de regel anders.',
            'placeholders' => ['{bedrag}' => 'Het bedrag', '{partij}' => 'Wie betaalde', '{datum}' => 'De datum'],
            'example' => ['{bedrag}' => '€ 499,00', '{partij}' => 'J. Jansen', '{datum}' => '2026-10-14'],
        ],
        'unusual' => [
            'label' => 'Ongewoon hoog bedrag',
            'message' => 'Ongewoon hoog: {bedrag} aan *{partij}* op {datum}, normaal {normaal}',
            'placeholders' => ['{bedrag}' => 'Het bedrag', '{partij}' => 'De tegenpartij', '{datum}' => 'De datum', '{normaal}' => 'Wat je normaal betaalt'],
            'example' => ['{bedrag}' => '€ 900,00', '{partij}' => 'Andro commerce', '{datum}' => '2026-10-10', '{normaal}' => '€ 100,00'],
        ],
    ];

    /**
     * @return array{enabled: bool, channel: ?string, budgets: bool, subscriptions: bool, anomalies: bool, payments: bool, messages: array<string, string>}
     */
    public static function settings(): array
    {
        $saved = AppSetting::get(self::SettingKey, []);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? true),
            'channel' => array_key_exists('channel', $saved) ? $saved['channel'] : self::DefaultChannel,
            'budgets' => (bool) ($saved['budgets'] ?? true),
            'subscriptions' => (bool) ($saved['subscriptions'] ?? true),
            'anomalies' => (bool) ($saved['anomalies'] ?? true),
            'payments' => (bool) ($saved['payments'] ?? true),
            'messages' => collect(self::Kinds)->map(fn (array $kind, string $type): string => filled($saved['messages'][$type] ?? null) ? $saved['messages'][$type] : $kind['message'])->all(),
        ];
    }

    /**
     * @param  array{enabled: bool, channel: ?string, budgets: bool, subscriptions: bool, anomalies: bool, payments?: bool, messages?: array<string, string|null>}  $settings
     */
    public static function save(array $settings): void
    {
        AppSetting::put(self::SettingKey, [
            'enabled' => (bool) $settings['enabled'],
            'channel' => filled($settings['channel']) ? trim($settings['channel']) : null,
            'budgets' => (bool) $settings['budgets'],
            'subscriptions' => (bool) $settings['subscriptions'],
            'anomalies' => (bool) $settings['anomalies'],
            'payments' => (bool) ($settings['payments'] ?? true),
            'messages' => collect($settings['messages'] ?? [])->only(array_keys(self::Kinds))->map(fn (?string $message): ?string => filled($message) ? $message : null)->all(),
        ]);
    }

    /**
     * One signal's text from the saved message of its kind.
     *
     * @param  array<string, string>  $values  Placeholder => value.
     * @param  array<string, string>|null  $messages  Messages to use instead of the saved ones (for the preview).
     */
    public static function render(string $type, array $values, ?array $messages = null): string
    {
        $messages ??= static::settings()['messages'];

        return strtr($messages[$type] ?? self::Kinds[$type]['message'], $values);
    }

    /**
     * The whole Slack message for a list of signal texts.
     *
     * @param  list<string>  $signals
     * @param  array<string, string>|null  $messages
     */
    public static function message(array $signals, string $link, ?array $messages = null): string
    {
        return static::render('intro', ['{signalen}' => implode("\n", array_map(fn (string $signal): string => "• {$signal}", $signals)), '{link}' => $link], $messages);
    }

    /**
     * An example message with one signal of every kind, for the preview and the test message.
     *
     * @param  array<string, string>|null  $messages
     */
    public static function example(string $link, ?array $messages = null): string
    {
        $signals = collect(self::Kinds)->except('intro')->map(fn (array $kind, string $type): string => static::render($type, $kind['example'], $messages))->values()->all();

        return static::message($signals, $link, $messages);
    }
}
