<?php

namespace App\Console\Commands;

use App\Services\ConsistencyCheckService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:check-consistency')]
#[Description('Sweep booking/finance data for integrity issues (see ConsistencyCheckService) and log any found')]
class CheckDataConsistency extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ConsistencyCheckService $consistency): int
    {
        $issues = $consistency->runAndLog();
        if ($issues->isEmpty()) {
            $this->info('No consistency issues found.');

            return self::SUCCESS;
        }
        $issues->groupBy('severity')->each(fn ($group, $severity) => $this->warn(strtoupper($severity).': '.$group->count().' issue(s)'));
        foreach ($issues as $issue) {
            $this->line("- [{$issue['check']}] {$issue['message']}");
        }

        return self::SUCCESS;
    }
}
