<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;

class RunTaskUpdater extends Command
{
    protected $signature = 'tasks:run-updater';
    protected $description = 'Обновляет флаги задач в цикле при запущенном сервере';

    public function handle()
    {
        $this->info('Запущен фоновый цикл обновления задач...');

        while (true) {
            Task::chunk(100, function ($tasks) {
                foreach ($tasks as $task) {
                    $task->updateStatusFlags();
                    $task->save();
                }
            });

            $this->info('Обновлены статусы задач: ' . now()->toDateTimeString());

            sleep(3600);
        }
    }
}
