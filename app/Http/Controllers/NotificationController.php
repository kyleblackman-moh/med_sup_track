<?php

namespace App\Http\Controllers;

use App\Notifications\Bell;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * NOTIF-2: the bell's endpoints, for the signed-in user's own notifications
 * only. Another user's notification (or an unknown id) is 404. JSON for the
 * header dropdown and its 60-second poll; the read actions also accept a
 * normal Inertia form post and redirect back.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (DatabaseNotification $n) => Bell::row($n))->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'unread' => Bell::unreadCount($request->user()),
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
