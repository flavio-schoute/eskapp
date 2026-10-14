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
     * Invite users to a channel. By default users that cannot be invited are skipped instead of failing the whole call.
     *
     * @param  list<string>  $userIds
     */
    public function inviteUsers(string $channelId, array $userIds, bool $skipUsersThatCannotBeInvited = true): void
    {
        if ($userIds === []) {
            return;
        }

        $this->call('conversations.invite', array_filter([
            'channel' => $channelId,
            'users' => implode(',', $userIds),
            'force' => $skipUsersThatCannotBeInvited ?: null,
        ]), ignoredErrors: ['already_in_channel', 'cant_invite_self']);
    }

    /**
     * Post a message in a channel.
     */
    public function postMessage(string $channelId, string $text): void
    {
        $this->call('chat.postMessage', [
            'channel' => $channelId,
            'text' => $text,
        ]);
    }

    /**
     * Upload a file to a channel, with a message posted together with it.
     */
    public function uploadFile(string $channelId, string $filename, string $contents, string $message): void
    {
        $upload = $this->call('files.getUploadURLExternal', [
            'filename' => $filename,
            'length' => strlen($contents),
        ], asForm: true);

        Http::withBody($contents, 'application/octet-stream')
            ->timeout(60)
            ->post($upload['upload_url'])
            ->throw();

        $this->call('files.completeUploadExternal', [
            'files' => json_encode([['id' => $upload['file_id'], 'title' => $filename]]),
            'channel_id' => $channelId,
            'initial_comment' => $message,
        ], asForm: true);
    }

    /**
     * Call a Slack Web API method, throwing when Slack reports an error.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $ignoredErrors
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload, array $ignoredErrors = [], bool $asForm = false): array
    {
        $request = Http::withToken(config('services.slack.notifications.bot_user_oauth_token'))
            ->acceptJson()
            ->timeout(30);

        $response = ($asForm ? $request->asForm() : $request)
            ->post(self::ApiUrl."/{$method}", $payload)
            ->throw()
            ->json();

        if (! ($response['ok'] ?? false) && ! in_array($response['error'] ?? null, $ignoredErrors, true)) {
            throw new SlackApiException($method, $response['error'] ?? 'unknown_error');
        }

        return $response;
    }
}
