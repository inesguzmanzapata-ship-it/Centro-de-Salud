@extends('layouts.app')

@section('title', 'Reprogramar cita')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">

    <div>
        <p class="text-sm font-semibold uppercase tracking-wide text-teal-700">
            Citas médicas
        </p>
        <h1 class="mt-2 text-3xl font-bold text-slate-900">
            Reprogramar cita
        </h1>
        <p class="mt-2 text-slate-600">
            Selecciona una nueva fecha y un horario disponible.
        </p>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">
            Información de tu cita actual
        </h2>

        <div class="mt-4 grid gap-3 text-sm text-slate-600 sm:grid-cols-2">
            <p>
                <strong class="text-slate-800">Médico:</strong>
                {{ $doctor->user->name }}
            </p>

            <p>
                <strong class="text-slate-800">Especialidad:</strong>
                {{ $doctor->specialties->pluck('name')->join(' · ') }}
            </p>

            <p>
                <strong class="text-slate-800">Fecha actual:</strong>
                {{ $appointment->appointment_date->format('d/m/Y') }}
            </p>

            <p>
                <strong class="text-slate-800">Hora actual:</strong>
                {{ substr($appointment->start_time, 0, 5) }}
            </p>

            <p>
                <strong class="text-slate-800">Código de reserva:</strong>
                {{ $appointment->booking_code }}
            </p>
        </div>
    </section>

    <form method="GET"
          action="{{ route('appointments.reschedule.form', $appointment) }}"
          class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-[1fr_auto] sm:items-end">

        <div>
            <label for="date" class="mb-2 block text-sm font-medium text-slate-700">
                Nueva fecha
            </label>

            <input
                id="date"
                name="date"
                type="date"
                min="{{ today()->toDateString() }}"
                value="{{ $date->toDateString() }}"
                required
                class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
            >
        </div>

        <button type="submit"
                class="rounded-xl bg-teal-700 px-5 py-3 font-semibold text-white hover:bg-teal-800">
            Buscar horarios
        </button>
    </form>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-bold text-slate-900">
                Horarios disponibles
            </h2>

            <span class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800">
                {{ $date->format('d/m/Y') }}
            </span>
        </div>

        @if ($availableTimes)
            <p class="mt-2 text-sm text-slate-500">
                Selecciona el horario que deseas reservar.
            </p>

            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($availableTimes as $time)
                    <form
                        method="POST"
                        action="{{ route('appointments.reschedule.update', $appointment) }}"
                        class="rounded-xl border border-slate-200 p-4"
                    >
                        @csrf
                        @method('PATCH')

                        <input
                            type="hidden"
                            name="appointment_date"
                            value="{{ $date->toDateString() }}"
                        >

                        <input
                            type="hidden"
                            name="start_time"
                            value="{{ $time }}"
                        >

                        <p class="text-center text-xl font-bold text-slate-800">
                            {{ $time }}
                        </p>

                        <button
                            type="submit"
                            class="mt-3 w-full rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800"
                        >
                            Elegir horario
                        </button>
                    </form>
                @endforeach
            </div>
        @else
            <div class="mt-5 rounded-xl bg-slate-50 p-6 text-center">
                <p class="font-semibold text-slate-700">
                    No hay horarios disponibles.
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    Prueba con otra fecha para encontrar un horario libre.
                </p>
            </div>
        @endif
    </section>

    <div>
        <a href="{{ route('appointments.mine') }}"
           class="text-sm font-semibold text-teal-700 hover:text-teal-900">
            ← Volver a mis citas
        </a>
    </div>

</div>
@endsection
