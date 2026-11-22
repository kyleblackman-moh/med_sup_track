<?php

namespace App\Http\Controllers;

use App\Notifications\Bell;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * NOTIF-2: the bell's endpoints, for the signed-in user's own notifications
 * only. Another user's notification (or an unknown id) is 404.
 * NOTIF-3: index is the "View all" Inertia page (paginated, filtered); the
 * header dropdown reads latest (JSON) and polls unread-count every 60 seconds.
 * The read actions answer JSON to fetch and redirect back for a form post.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    /** The "View all" filters, in the order the page shows them. */
    public const FILTERS = ['all', 'action', 'unread'];

    public function index(Request $request): Response|RedirectResponse
    {
        $validator = Validator::make($request->query(), [
            'filter' => ['nullable', 'string', Rule::in(self::FILTERS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // A bad filter is reported, never silently ignored (as on the case list).
        if ($validator->fails()) {
            return to_route('notifications.index')->withErrors($validator);
        }

        $filter = $validator->validated()['filter'] ?? 'all';
        $user = $request->user();

        $page = $user->notifications()
            ->reorder()
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($filter === 'action', fn ($q) => $q->where('data->kind', 'action'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('notifications/index', [
            'notifications' => [
                'data' => $page->getCollection()->map(fn (DatabaseNotification $n) => Bell::row($n))->values()->all(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'filter' => $filter,
        ]);
    }

    /** The header dropdown: the latest Bell::LATEST rows and the unread count. */
    public function latest(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $user->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id')->limit(Bell::LATEST)->get();

        return response()->json([
            'data' => $rows->map(fn (DatabaseNotification $n) => Bell::row($n))->values()->all(),
            'unread' => Bell::unreadCount($user),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => Bell::unreadCount($request->user())]);
    }

    public function read(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $row = $request->user()->notifications()->whereKey($notification)->first() ?? abort(404);
        $row->markAsRead();

        return $this->done($request);
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => CarbonImmutable::now('UTC')]);

        return $this->done($request);
    }

    private function done(Request $request): JsonResponse|RedirectResponse
    {
        return $request->wantsJson()
            ? response()->json(['count' => Bell::unreadCount($request->user())])
            : back();
    }
}
