<?php

namespace App\Services;

use App\Models\PlatformResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Reports unexpected exceptions to a configured external error-monitoring service (Sentry-style —
 * any provider that accepts a JSON POST works). Registered against the exception handler's
 * report() hook in bootstrap/app.php. Two safety properties matter more than the integration
 * itself: this must never throw (a failure here must never mask or replace the original
 * exception), and it must never forward routine, expected rejections (validation failures, 404s,
 * permission denials — the app's normal abort_if/abort_unless flow) as if they were incidents.
 */
class ErrorMonitoringService
{
    /** @param array<string, mixed> $context */
    public function report(Throwable $exception, array $context = []): void
    {
        try {
            if ($this->isExpected($exception)) {
                return;
            }
            $config = config('integrations.error_monitoring');
            $status = 'skipped';
            if (filled($config['url'] ?? null)) {
                try {
                    Http::withToken((string) ($config['token'] ?? ''))->timeout(5)->post($config['url'], [
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'trace' => collect($exception->getTrace())->take(10)->toArray(),
                        'environment' => config('app.env'),
                        'context' => $context,
                        'reported_at' => now()->toIso8601String(),
                    ])->throw();
                    $status = 'sent';
                } catch (Throwable) {
                    $status = 'failed';
                }
            }
            (new PlatformResource)->useModule('integration_logs')->fill([
                'code' => 'error:'.now()->format('YmdHis').':'.Str::random(8), 'name' => 'Error monitoring: '.$exception::class, 'status' => $status,
                'data' => ['type' => 'error_monitoring', 'message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()],
            ])->save();
        } catch (Throwable) {
            // Monitoring must never itself break the app or mask the original exception.
        }
    }

    private function isExpected(Throwable $exception): bool
    {
        return $exception instanceof ValidationException
            || $exception instanceof AuthenticationException
            || $exception instanceof AuthorizationException
            || $exception instanceof ModelNotFoundException
            || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
    }
}
