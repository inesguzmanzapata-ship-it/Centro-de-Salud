<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * Mostrar el historial de notificaciones.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // El administrador puede consultar todas las notificaciones.
        if ($user->hasRole('admin')) {
            $query = DatabaseNotification::query()
                ->with('notifiable');
        } else {
            // Los demás usuarios solo consultan las suyas.
            $query = $user->notifications();
        }

        $notifications = $query
            ->latest()
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    /**
     * Marcar una notificación propia como leída.
     */
    public function markAsRead(
        Request $request,
        string $notification
    ): RedirectResponse {
        $record = DatabaseNotification::query()
            ->findOrFail($notification);

        $user = $request->user();

        $isOwner = $record->notifiable_type === $user->getMorphClass()
            && (string) $record->notifiable_id === (string) $user->getKey();

        abort_unless($isOwner, 403);

        $record->markAsRead();

        return back()->with(
            'status',
            'La notificación fue marcada como leída.'
        );
    }

    /**
     * Marcar todas las notificaciones propias como leídas.
     */
    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return back()->with(
            'status',
            'Todas tus notificaciones fueron marcadas como leídas.'
        );
    }
}

