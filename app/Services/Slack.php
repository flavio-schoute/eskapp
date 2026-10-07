<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class Slack
{
    private const ApiUrl = 'https://slack.com/api';

    /**
     * Determine whether a Slack bot token is configured.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.slack.notifications.bot_user_oauth_token'));
    }

    /**
     * Create a private channel and return its ID and final name.
     *
     * @return array{id: string, name: string}
     */
    public function createPrivateChannel(string $name): array
    {
        $channel = $this->call('conversations.create', [
            'name' => $name,
            'is_private' => true,
        ])['channel'];

        return ['id' => $channel['id'], 'name' => $channel['name']];
    }

    /**
     * Rename an existing channel.
     */
    public function renameChannel(string $channelId, string $name): string
    {
        return $this->call('conversations.rename', [
            'channel' => $channelId,
            'name' => $name,
        ])['channel']['name'];
    }

    /**
     * Invite users to a channel, skipping users that cannot be invited instead of failing the whole call.
     *
     * @param  list<string>  $userIds
     */
    public function inviteUsers(string $channelId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->call('conversations.invite', [
            'channel' => $channelId,
            'users' => implode(',', $userIds),
            'force' => true,
        ], ignoredErrors: ['already_in_channel', 'cant_invite_self']);
    }

    /**
     * Call a Slack Web API method, throwing when Slack reports an error.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $ignoredErrors
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload, array $ignoredErrors = []): array
    {
        $response = Http::withToken(config('services.slack.notifications.bot_user_oauth_token'))
            ->acceptJson()
            ->timeout(30)
            ->post(self::ApiUrl."/{$method}", $payload)
            ->throw()
            ->json();

        if (! ($response['ok'] ?? false) && ! in_array($response['error'] ?? null, $ignoredErrors, true)) {
            throw new SlackApiException($method, $response['error'] ?? 'unknown_error');
        }

        return $response;
    }
}
