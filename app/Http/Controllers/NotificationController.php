<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $request->string('category')->lower()->toString();
        $unreadOnly = $request->boolean('unread');

        $paginator = $request->user()->userNotifications()
            ->when(
                in_array($category, array_column(NotificationCategory::cases(), 'value'), true),
                fn (Builder $q) => $q->where('category', $category),
            )
            ->when($unreadOnly, fn (Builder $q) => $q->unread())
            ->paginate(20)
            ->withQueryString()
            ->through(fn (UserNotification $n) => $this->present($n));

        return Inertia::render('notifications/Index', [
            'notifications' => $paginator,
            'filters' => ['category' => $category ?: 'all', 'unread' => $unreadOnly],
            'categories' => NotificationCategory::options(),
            'unreadCount' => $request->user()->userNotifications()->unread()->count(),
        ]);
    }

    /**
     * Lightweight JSON endpoint polled by the header bell.
     */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'count' => $user->userNotifications()->unread()->count(),
            'items' => $user->userNotifications()->limit(8)->get()->map(fn (UserNotification $n) => $this->present($n)),
        ]);
    }

    public function markRead(Request $request, UserNotification $notification): RedirectResponse
    {
        $this->authorizeOwner($request, $notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->userNotifications()->unread()->update(['read_at' => now()]);

        return back();
    }

    public function destroy(Request $request, UserNotification $notification): RedirectResponse
    {
        $this->authorizeOwner($request, $notification);
        $notification->delete();

        return back();
    }

    private function authorizeOwner(Request $request, UserNotification $notification): void
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UserNotification $n): array
    {
        return [
            'id' => $n->id,
            'category' => $n->category->value,
            'category_color' => $n->category->color(),
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'action_url' => $n->action_url,
            'is_read' => $n->isRead(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
