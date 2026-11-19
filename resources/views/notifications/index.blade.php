@extends('layouts.app')

@section('title', 'Notificaciones')

@section('content')
@php
    $currentUser = auth()->user();
@endphp

<div class="mx-auto max-w-5xl space-y-6">

    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
            <p class="text-sm font-bold uppercase tracking-widest text-teal-700">
                Centro de Salud San Juan
            </p>
            <h1 class="mt-1 text-3xl font-bold text-slate-900">
                Notificaciones
            </h1>
            <p class="mt-2 text-sm text-slate-500">
                Consulta los avisos y novedades de las citas médicas.
            </p>
        </div>

        @if (! $currentUser->hasRole('admin') && $currentUser->unreadNotifications()->exists())
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                @method('PATCH')

                <button type="submit"
                    class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-600">
                    Marcar todas como leídas
                </button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white px-6 py-16 text-center shadow-sm">
            <div class="mx-auto grid size-16 place-items-center rounded-full bg-teal-50 text-teal-700">
                <span class="text-3xl">🔔</span>
            </div>

            <h2 class="mt-4 text-lg font-bold text-slate-900">
                No hay notificaciones
            </h2>

            <p class="mt-2 text-sm text-slate-500">
                Aquí aparecerán los avisos cuando se registren novedades.
            </p>
        </div>
    @else

        @if ($currentUser->hasRole('admin'))
            <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                Vista general: puedes consultar las notificaciones de todos los usuarios.
            </div>
        @endif

        <div class="space-y-3">

            @foreach ($notifications as $notification)

                @php
                    $data = $notification->data ?? [];
                    $isRead = $notification->read_at !== null;

                    $isOwner = $notification->notifiable_type === $currentUser->getMorphClass()
                        && (string) $notification->notifiable_id === (string) $currentUser->getKey();
                @endphp

                <article class="rounded-2xl border {{ $isRead ? 'border-slate-200 bg-white' : 'border-teal-200 bg-teal-50/50' }} p-5 shadow-sm">

                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">

                        <div class="flex items-start gap-3">

                            <div class="grid size-11 shrink-0 place-items-center rounded-xl {{ $isRead ? 'bg-slate-100 text-slate-500' : 'bg-teal-100 text-teal-800' }}">
                                <span class="text-xl">🔔</span>
                            </div>

                            <div class="min-w-0">

                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="font-bold text-slate-900">
                                        {{ $data['title'] ?? 'Actualización de cita' }}
                                    </h2>

                                    @if (! $isRead)
                                        <span class="rounded-full bg-teal-100 px-2.5 py-1 text-xs font-semibold text-teal-800">
                                            Nueva
                                        </span>
                                    @else
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500">
                                            Leída
                                        </span>
                                    @endif
                                </div>

                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $data['message'] ?? 'Hay una actualización relacionada con una cita.' }}
                                </p>

                                @if ($currentUser->hasRole('admin'))
                                    <p class="mt-2 text-xs font-medium text-blue-700">
                                        Destinatario:
                                        {{ $notification->notifiable?->name ?? 'Usuario no disponible' }}
                                    </p>
                                @endif

                                <div class="mt-3 space-y-1 text-sm text-slate-500">

                                    @if (! empty($data['patient_name']))
                                        <p>
                                            <strong>Paciente:</strong>
                                            {{ $data['patient_name'] }}
                                        </p>
                                    @endif

                                    @if (! empty($data['doctor_name']))
                                        <p>
                                            <strong>Médico:</strong>
                                            {{ $data['doctor_name'] }}
                                        </p>
                                    @endif

                                    @if (! empty($data['booking_code']))
                                        <p>
                                            <strong>Código:</strong>
                                            {{ $data['booking_code'] }}
                                        </p>
                                    @endif

                                    @if (! empty($data['appointment_date']))
                                        <p>
                                            <strong>Fecha:</strong>
                                            {{ $data['appointment_date'] }}
                                        </p>
                                    @endif

                                    @if (! empty($data['start_time']))
                                        <p>
                                            <strong>Hora:</strong>
                                            {{ $data['start_time'] }}
                                        </p>
                                    @endif

                                </div>

                                <p class="mt-3 text-xs text-slate-400">
                                    Recibida:
                                    {{ $notification->created_at->format('d/m/Y H:i') }}
                                </p>

                            </div>
                        </div>

                        @if (! $isRead && $isOwner)
                            <form method="POST"
                                action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                @method('PATCH')

                                <button type="submit"
                                    class="shrink-0 rounded-lg border border-teal-700 px-3 py-2 text-xs font-semibold text-teal-800 transition hover:bg-teal-50">
                                    Marcar como leída
                                </button>
                            </form>
                        @endif

                    </div>
                </article>

            @endforeach

        </div>

        <div class="pt-3">
            {{ $notifications->links() }}
        </div>

    @endif

</div>
@endsection
