<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\SlackMember;
use RuntimeException;

class AffiliateSlackSync
{
    /**
     * How many numbered alternatives to try when the channel name is already taken.
     */
    private const MaxNameAttempts = 5;

    public function __construct(public Slack $slack) {}

    /**
     * Make sure the affiliate has a private Slack channel with the team members in it.
     */
    public function ensureChannel(Affiliate $affiliate): string
    {
        if (filled($affiliate->slack_channel_id)) {
            return $affiliate->slack_channel_id;
        }

        $this->guardConfigured();

        $channel = $this->createWithAvailableName($affiliate->slackChannelName());

        $affiliate->forceFill([
            'slack_channel_id' => $channel['id'],
            'slack_channel_name' => $channel['name'],
        ])->saveQuietly();

        $this->slack->inviteUsers($channel['id'], SlackMember::active()->pluck('slack_user_id')->all());

        return $channel['id'];
    }

    /**
     * Rename the affiliate's channel to match its current name and type.
     */
    public function renameChannel(Affiliate $affiliate): void
    {
        if (blank($affiliate->slack_channel_id) || $affiliate->slack_channel_name === $affiliate->slackChannelName()) {
            return;
        }

        $this->guardConfigured();

        $name = $this->slack->renameChannel($affiliate->slack_channel_id, $affiliate->slackChannelName());

        $affiliate->forceFill(['slack_channel_name' => $name])->saveQuietly();
    }

    /**
     * @return array{id: string, name: string}
     */
    private function createWithAvailableName(string $name): array
    {
        foreach (range(1, self::MaxNameAttempts) as $attempt) {
            $candidate = $attempt === 1 ? $name : mb_substr($name, 0, 77)."-{$attempt}";

            try {
                return $this->slack->createPrivateChannel($candidate);
            } catch (SlackApiException $exception) {
                if ($exception->error !== 'name_taken') {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException("The Slack channel name #{$name} and its alternatives are already taken.");
    }

    private function guardConfigured(): void
    {
        if (! $this->slack->isConfigured()) {
            throw new RuntimeException('Slack is not configured.');
        }
    }
}
