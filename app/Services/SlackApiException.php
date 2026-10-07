<?php

namespace App\Services;

use RuntimeException;

class SlackApiException extends RuntimeException
{
    public function __construct(public string $method, public string $error)
    {
        parent::__construct("Slack {$method} failed: {$error}");
    }
}
