<?php

namespace App\Services;

use App\Enums\Integration;
use App\Models\Affiliate;
use App\Models\IntegrationError;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntegrationErrorLogger
{
    /**
     * Record a failed integration step in the integration errors list and the integrations log file.
     *
     * The same unresolved error for the same affiliate and step is counted on one row instead of being added again.
     */
    public function record(Integration $integration, string $action, Throwable $exception, ?Affiliate $affiliate = null): IntegrationError
    {
        Log::channel('integrations')->error("{$integration->getLabel()}: {$action} failed", [
            'affiliate_id' => $affiliate?->getKey(),
            'affiliate' => $affiliate?->name,
            'message' => self::readableMessage($exception),
            'exception' => $exception,
        ]);

        $error = IntegrationError::query()
            ->unresolved()
            ->where('integration', $integration)
            ->where('action', $action)
            ->where('affiliate_id', $affiliate?->getKey())
            ->where('message', self::readableMessage($exception))
            ->first();

        if ($error) {
            $error->increment('occurrences', 1, ['last_occurred_at' => now()]);

            return $error;
        }

        return IntegrationError::create([
            'affiliate_id' => $affiliate?->getKey(),
            'integration' => $integration,
            'action' => $action,
            'message' => self::readableMessage($exception),
            'exception_class' => $exception::class,
            'last_occurred_at' => now(),
        ]);
    }

    /**
     * The error message for people: services sometimes answer with an HTML error page instead of an error message.
     */
    public static function readableMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (! str_contains($message, '<html') && ! str_contains($message, '<!DOCTYPE')) {
            return $message;
        }

        preg_match('/"(\d{3}) ([^"]+)"/', $message, $status);

        return $status
            ? "The service returned a server error ({$status[1]} {$status[2]}) instead of a response. This is a problem on their side."
            : 'The service returned an error page instead of a response. This is a problem on their side.';
    }
}
