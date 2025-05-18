<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Task extends Model
{
    public const PRIORITY_CRITICAL = 'CRITICAL';
    public const PRIORITY_HIGH = 'HIGH';
    public const PRIORITY_MEDIUM = 'MEDIUM';
    public const PRIORITY_LOW = 'LOW';

    public const PRIORITY_CHOICES = [
        self::PRIORITY_CRITICAL => 'Critical',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_MEDIUM => 'Medium',
        self::PRIORITY_LOW => 'Low',
    ];

    public const PRIORITY_MAPPING = [
        self::PRIORITY_LOW => 1,
        self::PRIORITY_MEDIUM => 2,
        self::PRIORITY_HIGH => 3,
        self::PRIORITY_CRITICAL => 4,
    ];

    public const STATUS_TODO = 'TODO';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_DONE = 'DONE';

    public const STATUS_CHOICES = [
        self::STATUS_TODO => 'К выполнению',
        self::STATUS_IN_PROGRESS => 'В работе',
        self::STATUS_DONE => 'Завершено',
    ];
    protected $fillable = [
        'title',
        'description',
        'deadline',
        'priority',
        'status',
        'tags',
        'user_id',
        'is_urgent',
        'is_overdue',
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'is_urgent' => 'boolean',
        'is_overdue' => 'boolean',
    ];

    public function updateStatusFlags(): void
    {
        $nowTs = now()->timestamp;
        $deadlineTs = optional($this->deadline)->timestamp;

        $isOverdue = false;
        $isUrgent = false;

        if ($deadlineTs && $this->status !== 'DONE') {
            if ($deadlineTs < $nowTs) {
                $isOverdue = true;
            } elseif (($deadlineTs - $nowTs) < 86400) {
                $isUrgent = true;
            }
        }

        $this->forceFill([
            'is_overdue' => $isOverdue,
            'is_urgent' => $isUrgent,
        ]);
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            $task->updateStatusFlags();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
