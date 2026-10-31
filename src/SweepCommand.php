<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Console\Command;

final class SweepCommand extends Command
{
    protected $signature = 'approvals:sweep
                            {--only=* : Run only some tasks: expire, escalate, remind}
                            {--remind-after= : Minutes an approval waits before its reviewers are reminded}';

    protected $description = 'Expire, escalate and send reminders for open approvals';

    public function handle(Approvals $approvals): int
    {
        $only = $this->option('only');
        $tasks = is_array($only) && $only !== [] ? array_values(array_filter($only, is_string(...))) : ['expire', 'escalate', 'remind'];
        $unknown = array_diff($tasks, ['expire', 'escalate', 'remind']);

        if ($unknown !== []) {
            $this->components->error('Unknown task: '.implode(', ', $unknown).'.');

            return self::FAILURE;
        }

        $after = $this->option('remind-after');

        foreach ($tasks as $task) {
            $count = match ($task) {
                'expire' => $approvals->expire(),
                'escalate' => $approvals->escalateDue(),
                default => $approvals->remind(is_numeric($after) ? (int) $after : null),
            };

            $this->components->twoColumnDetail(ucfirst($task), (string) $count);
        }

        return self::SUCCESS;
    }
}
