<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;

class UpdateTaskStatusFlags extends Command
{
    protected $signature = 'tasks:update-status-flags';
    protected $description = 'Обновить флаги срочности и просроченности задач';

    public function handle()
    {
        Task::chunk(100, function ($tasks) {
            foreach ($tasks as $task) {
                $task->updateStatusFlags();
                $task->saveQuietly();
            }
        });

        $this->info('Флаги задач обновлены');
    }
}
