<?php
namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\DateTime as ICalDateTime;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Arr;

class TaskController extends Controller
{
    private function resolveAuth(Request $request): void
    {
        if (!auth()->check()) {
            $authHeader = $request->header('Authorization');

            $token = null;
            if ($authHeader) {
                if (str_starts_with($authHeader, 'Bearer ')) {
                    $token = substr($authHeader, 7);
                } elseif (str_starts_with($authHeader, 'Token ')) {
                    $token = substr($authHeader, 6);
                }
            }

            $token = $token ?? $request->input('token');

            if ($token) {
                $model = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                if ($model && $model->tokenable) {
                    auth()->login($model->tokenable);
                }
            }
        }
    }

    public function index(Request $request)
    {
        $this->resolveAuth($request);
        $query = Task::query()->where('user_id', Auth::id());

        $isUrgent = $request->boolean('is_urgent', false);
        $isOverdue = $request->boolean('is_overdue', false);

        if ($isUrgent && $isOverdue) {
            $query->where(function ($q) {
                $q->where('is_urgent', true)
                    ->orWhere('is_overdue', true);
            });
        } elseif ($isUrgent) {
            $query->where('is_urgent', true);
        } elseif ($isOverdue) {
            $query->where('is_overdue', true);
        }

        if ($request->has('priority')) {
            $priorities = Arr::wrap($request->query('priority'));
            if (!empty($priorities)) {
                $query->whereIn('priority', $priorities);
            }
        }

        if ($request->has('status')) {
            $priorities = Arr::wrap($request->query('status'));
            if (!empty($priorities)) {
                $query->whereIn('status', $priorities);
            }
        }

        $sortable = ['priority', 'status', 'deadline', 'is_urgent', 'is_overdue'];
        $ordering = $request->query('ordering');

        try {
            if ($ordering === '-is_urgent') {
                $query->orderByRaw("
                CASE
                    WHEN status = 'DONE' THEN 4
                    WHEN is_overdue = true THEN 1
                    WHEN is_urgent = true THEN 2
                    ELSE 3
                END ASC
            ");
            } elseif ($ordering === 'is_urgent') {
                $query->orderByRaw("
                CASE
                    WHEN status = 'DONE' THEN 1
                    WHEN is_urgent = false AND is_overdue = false THEN 2
                    WHEN is_urgent = true THEN 3
                    WHEN is_overdue = true THEN 4
                    ELSE 5
                END ASC
            ");
            } elseif ($ordering) {
                $direction = 'asc';
                $column = $ordering;

                if (str_starts_with($ordering, '-')) {
                    $direction = 'desc';
                    $column = substr($ordering, 1);
                }

                if (in_array($column, $sortable)) {
                    if ($column === 'priority') {
                        $order = "FIELD(priority, 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW')";
                        if ($direction === 'desc') {
                            $order .= " DESC";
                        }
                        $query->orderByRaw($order);
                    } else {
                        $query->orderBy($column, $direction);
                    }
                }
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $tasks = $query->get();
        return response()->json($tasks->map(fn($task) => $this->decorateTask($task)));
    }

    public function store(Request $request)
    {
        $this->resolveAuth($request);
        if ($request->filled('deadline')) {
            $request->merge([
                'deadline' => Carbon::parse($request->input('deadline'), 'Europe/Moscow')->timezone('UTC')
            ]);
        }
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'deadline' => 'nullable|date',
            'priority' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL',
            'status' => 'required|in:TODO,IN_PROGRESS,DONE',
            'tags' => 'nullable|string',
        ]);

        $task = Task::create(array_merge($data, ['user_id' => Auth::id()]));
        return response()->json($this->decorateTask($task), 201);
    }

    public function show(Request $request, Task $task)
    {
        $this->resolveAuth($request);
        $this->authorizeTask($task);
        return response()->json($this->decorateTask($task));
    }

    public function update(Request $request, Task $task)
    {
        $this->resolveAuth($request);
        $this->authorizeTask($task);

        if ($request->filled('deadline')) {
            $request->merge([
                'deadline' => Carbon::parse($request->input('deadline'), 'Europe/Moscow')->timezone('UTC')
            ]);
        }

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'deadline' => 'nullable|date',
            'priority' => 'sometimes|required|in:LOW,MEDIUM,HIGH,CRITICAL',
            'status' => 'sometimes|required|in:TODO,IN_PROGRESS,DONE',
            'tags' => 'nullable|string',
        ]);

        $task->fill($data);
        $task->updateStatusFlags();
        $task->save();
        return response()->json($this->decorateTask($task));
    }

    public function destroy(Request $request, Task $task)
    {
        $this->resolveAuth($request);
        $this->authorizeTask($task);
        $task->delete();
        return response()->json(['message' => 'Задача удалена']);
    }

    public function generateIcs(Request $request, Task $task)
    {
        $this->resolveAuth($request);
        $this->authorizeTask($task);

        $calendar = new Calendar();
        $event = new Event();

        $event->setSummary($task->title);
        $event->setDescription($task->description ?? '');

        $start = $task->deadline ? \Carbon\Carbon::parse($task->deadline) : now();
        $end = $start->copy()->addHour(); // длительность 1 час

        $event->setOccurrence(
            new \Eluceo\iCal\Domain\ValueObject\TimeSpan(
                new ICalDateTime($start, true),
                new ICalDateTime($end, true)
            )
        );

        $calendar->addEvent($event);

        $componentFactory = new CalendarFactory();
        $icsContent = $componentFactory->createCalendar($calendar);

        return new Response(
            $icsContent,
            200,
            [
                'Content-Type' => 'text/calendar',
                'Content-Disposition' => "attachment; filename=task_{$task->id}.ics",
            ]
        );
    }

    protected function authorizeTask(Task $task): void
    {
        if ($task->user_id !== Auth::id()) {
            abort(403, 'Нет доступа к этой задаче');
        }
    }

    protected function decorateTask(Task $task): array
    {
        $deadline = $task->deadline
            ? $task->deadline->copy()->timezone('Europe/Moscow')->toDateTimeString()
            : null;

        return array_merge($task->toArray(), [
            'deadline' => $deadline,
            'is_overdue' => $task->is_overdue,
            'is_urgent' => $task->is_urgent,
        ]);
    }

}
