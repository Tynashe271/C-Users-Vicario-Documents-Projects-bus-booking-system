<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Platform-ops visibility into the background-processing lanes (see DeliverPlatformNotification,
 * DeliverWebhookNotification, GenerateReportExport for what actually runs on them) and Laravel's
 * standard failed_jobs table. Retry/forget delegate to the framework's own queue:retry and
 * queue:forget commands rather than reimplementing them.
 */
class QueueController extends Controller
{
    /** The named queues real jobs in this app are dispatched onto — kept in sync with each job's onQueue() call. */
    private const MONITORED_QUEUES = ['notifications', 'webhooks', 'reports'];

    public function health(Request $request): JsonResponse
    {
        $this->authorizePlatform($request);
        $queues = collect(self::MONITORED_QUEUES)->mapWithKeys(fn (string $queue) => [$queue => ['pending' => Queue::size($queue)]]);

        return response()->json([
            'queues' => $queues,
            'failed_jobs' => ['count' => DB::table('failed_jobs')->count(), 'oldest_failed_at' => DB::table('failed_jobs')->min('failed_at')],
        ]);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $this->authorizePlatform($request);
        $jobs = DB::table('failed_jobs')->orderByDesc('failed_at')->paginate(25);
        $jobs->getCollection()->transform(fn ($row) => [
            'uuid' => $row->uuid, 'connection' => $row->connection, 'queue' => $row->queue,
            'job_class' => data_get(json_decode($row->payload, true), 'displayName'),
            'exception_summary' => str($row->exception)->before("\n")->limit(500)->toString(),
            'failed_at' => $row->failed_at,
        ]);

        return response()->json($jobs);
    }

    public function retryFailedJob(Request $request, string $uuid): JsonResponse
    {
        $this->authorizePlatform($request);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['retried' => true]);
    }

    public function forgetFailedJob(Request $request, string $uuid): JsonResponse
    {
        $this->authorizePlatform($request);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:forget', ['id' => $uuid]);

        return response()->json(status: 204);
    }

    /** Queue health/failed-job management is shared infrastructure, not a company's own data — super admin only. */
    private function authorizePlatform(Request $request): void
    {
        abort_unless(in_array($request->user()->role, config('platform.platform_roles'), true) && $request->user()->can('platform.manage'), 403);
    }
}
