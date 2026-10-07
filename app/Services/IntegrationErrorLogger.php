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
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        $error = IntegrationError::query()
            ->unresolved()
            ->where('integration', $integration)
            ->where('action', $action)
            ->where('affiliate_id', $affiliate?->getKey())
            ->where('message', $exception->getMessage())
            ->first();

        if ($error) {
            $error->increment('occurrences', 1, ['last_occurred_at' => now()]);

            return $error;
        }

        return IntegrationError::create([
            'affiliate_id' => $affiliate?->getKey(),
            'integration' => $integration,
            'action' => $action,
            'message' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'last_occurred_at' => now(),
        ]);
    }
}
