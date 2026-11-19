<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CareEncounterEvent;
use App\Models\DoctorSchedule;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\StaffProfile;
use App\Models\User;
use App\Notifications\AppointmentInternalNotification;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function availability(Request $request): View
    {
        $data = $request->validate([
            'date' => ['nullable', 'date', 'after_or_equal:today'],
            'specialty_id' => ['nullable', 'integer', Rule::exists('specialties', 'id')->where('is_active', true)],
        ]);
        $date = Carbon::parse($data['date'] ?? today()->toDateString());
        $doctors = StaffProfile::query()
            ->whereHas('user', fn ($query) => $query->whereHas('role', fn ($role) => $role->where('code', 'doctor')))
            ->when(
                $data['specialty_id'] ?? null,
                fn ($query, $specialtyId) => $query->whereHas('specialties', fn ($specialty) => $specialty->whereKey($specialtyId)),
            )
            ->with([
                'user',
                'specialties' => fn ($query) => $query->where('is_active', true),
                'schedules' => fn ($query) => $query->where('day_of_week', $date->dayOfWeekIso),
            ])
            ->orderBy('employee_number')
            ->get()
            ->map(function (StaffProfile $doctor) use ($date): StaffProfile {
                $doctor->available_times = $doctor->schedules
                    ->first()
                    ?->availableTimesFor($date) ?? [];

                return $doctor;
            });
        $specialties = Specialty::query()->where('is_active', true)->orderBy('name')->get();

        return view('appointments.availability', compact('date', 'doctors', 'specialties'));
    }

    public function patientIndex(Request $request): View
    {
        $appointments = Appointment::query()
            ->where('patient_id', $request->user()->id)
            ->with(['doctor.user', 'doctor.specialties'])
            ->orderByDesc('appointment_date')
            ->orderByDesc('start_time')
            ->paginate(15);
        $appointments->getCollection()->transform(function (Appointment $appointment): Appointment {
            $isFuture = $this->appointmentIsFuture($appointment);
            $appointment->setAttribute('can_confirm', $appointment->status === 'pending' && $isFuture);
            $appointment->setAttribute('can_cancel', in_array($appointment->status, ['pending', 'confirmed'], true) && $isFuture);
            $appointment->setAttribute('status_label', [
                'pending' => 'Pendiente',
                'confirmed' => 'Confirmada',
                'completed' => 'Atendida',
                'cancelled' => 'Cancelada',
                'no_show' => 'No se presentó',
            ][$appointment->status] ?? 'Estado desconocido');

            return $appointment;
        });

        return view('appointments.patient-index', compact('appointments'));
    }

    
public function storeForPatient(Request $request): RedirectResponse
{
    $data = $request->validate($this->bookingRules());

    $appointment = $this->createAppointment(
        $data,
        (int) $request->user()->id,
        'pending'
    );

    $this->notifyAppointmentStaff($appointment, 'created');

    return redirect()->route('appointments.mine')
        ->with('status', 'Tu cita quedó reservada y pendiente de confirmación.');
}


    public function confirm(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless((int) $appointment->patient_id === (int) $request->user()->id, 404);

        if ($appointment->status !== 'pending' || ! $this->appointmentIsFuture($appointment)) {
            return back()->with('error', 'Solo puedes confirmar citas pendientes que aún no hayan iniciado.');
        }

        $updated = DB::transaction(function () use ($appointment, $request): int {
            $updated = Appointment::query()
                ->whereKey($appointment->id)
                ->where('patient_id', $request->user()->id)
                ->where('status', 'pending')
                ->update(['status' => 'confirmed']);

            if ($updated === 1) {
                CareEncounterEvent::query()->create([
                    'appointment_id' => $appointment->id,
                    'author_user_id' => $request->user()->id,
                    'from_status' => 'pending',
                    'to_status' => 'confirmed',
                ]);
            }

            return $updated;
        });

        if ($updated !== 1) {
            return back()->with('error', 'La cita ya no está pendiente de confirmación.');
        }

        return back()->with('status', 'La cita fue confirmada.');
    }

    
public function cancel(Request $request, Appointment $appointment): RedirectResponse
{
    abort_unless((int) $appointment->patient_id === (int) $request->user()->id, 404);

    if (! in_array($appointment->status, ['pending', 'confirmed'], true) || ! $this->appointmentIsFuture($appointment)) {
        return back()->with('error', 'Solo puedes cancelar citas pendientes o confirmadas que aún no hayan iniciado.');
    }

    $updated = DB::transaction(function () use ($appointment, $request): int {
        $lockedAppointment = Appointment::query()
            ->whereKey($appointment->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            (int) $lockedAppointment->patient_id !== (int) $request->user()->id
            || ! in_array($lockedAppointment->status, ['pending', 'confirmed'], true)
        ) {
            return 0;
        }

        $previousStatus = $lockedAppointment->status;
        $lockedAppointment->update(['status' => 'cancelled']);

        CareEncounterEvent::query()->create([
            'appointment_id' => $lockedAppointment->id,
            'author_user_id' => $request->user()->id,
            'from_status' => $previousStatus,
            'to_status' => 'cancelled',
        ]);

        return 1;
    });

    if ($updated !== 1) {
        return back()->with('error', 'La cita ya no se puede cancelar.');
    }

    $appointment->refresh();
    $this->notifyAppointmentStaff($appointment, 'cancelled');

    return back()->with('status', 'La cita fue cancelada; el horario vuelve a estar disponible.');
}


    public function adminCreate(Request $request): View
    {
        $data = $request->validate([
            'date' => ['nullable', 'date', 'after_or_equal:today'],
            'staff_profile_id' => [
                'nullable',
                'integer',
                Rule::exists('staff_profiles', 'id')->where(fn ($query) => $query->whereIn(
                    'user_id',
                    User::query()->whereHas('role', fn ($role) => $role->where('code', 'doctor'))->select('id'),
                )),
            ],
        ]);
        $date = Carbon::parse($data['date'] ?? today()->toDateString());
        $patients = User::query()->whereHas('role', fn ($query) => $query->where('code', 'patient'))
            ->orderBy('name')
            ->get();
        $doctors = StaffProfile::query()
            ->whereHas('user', fn ($query) => $query->whereHas('role', fn ($role) => $role->where('code', 'doctor')))
            ->with(['user', 'specialties'])
            ->orderBy('employee_number')
            ->get();
        $selectedDoctor = isset($data['staff_profile_id'])
            ? $doctors->firstWhere('id', (int) $data['staff_profile_id'])
            : null;
        $schedule = $selectedDoctor?->schedules()->where('day_of_week', $date->dayOfWeekIso)->first();
        $availableTimes = $schedule?->availableTimesFor($date) ?? [];

        return view('admin.appointments.create', compact(
            'date',
            'doctors',
            'patients',
            'selectedDoctor',
            'availableTimes',
        ));
    }

    
public function storeForPatientByAdmin(Request $request): RedirectResponse
{
    $data = $request->validate([
        ...$this->bookingRules(),
        'patient_id' => [
            'required',
            'integer',
            Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn(
                'role_id',
                Role::query()->where('code', 'patient')->select('id'),
            )),
        ],
    ]);

    $patient = User::query()->whereKey($data['patient_id'])
        ->whereHas('role', fn ($query) => $query->where('code', 'patient'))
        ->firstOrFail();

    $appointment = $this->createAppointment(
        $data,
        (int) $patient->id,
        'confirmed'
    );

    $this->notifyAppointmentStaff($appointment, 'created');

   return redirect()->to('/admin/citas/crear')
    ->with('status', 'La cita presencial fue registrada y confirmada.');
}

    
/**
 * Mostrar los horarios disponibles para reprogramar una cita.
 */
public function rescheduleForm(Request $request, Appointment $appointment): View
{
    abort_unless(
        (int) $appointment->patient_id === (int) $request->user()->id,
        404
    );

    abort_unless(
        in_array($appointment->status, ['pending', 'confirmed'], true)
        && $this->appointmentIsFuture($appointment),
        403
    );

    $data = $request->validate([
        'date' => ['nullable', 'date', 'after_or_equal:today'],
    ]);

    $date = Carbon::parse(
        $data['date'] ?? $appointment->appointment_date->toDateString()
    );

    $doctor = $appointment->doctor()
        ->with(['user', 'specialties'])
        ->firstOrFail();

    $schedule = $doctor->schedules()
        ->where('day_of_week', $date->dayOfWeekIso)
        ->first();

    $availableTimes = $schedule?->availableTimesFor(
        $date,
        (int) $appointment->id
    ) ?? [];

    // No ofrecer nuevamente el mismo horario de la cita actual.
    if ($date->isSameDay($appointment->appointment_date)) {
        $currentTime = substr((string) $appointment->start_time, 0, 5);

        $availableTimes = array_values(array_filter(
            $availableTimes,
            fn (string $time): bool => $time !== $currentTime
        ));
    }

    return view('appointments.reschedule', compact(
        'appointment',
        'doctor',
        'date',
        'availableTimes'
    ));
}

    
/**
 * Actualizar la fecha y hora de una cita.
 */
public function reschedule(Request $request, Appointment $appointment): RedirectResponse
{
    abort_unless(
        (int) $appointment->patient_id === (int) $request->user()->id,
        404
    );

    $data = $request->validate([
        'appointment_date' => ['required', 'date', 'after_or_equal:today'],
        'start_time' => ['required', 'date_format:H:i'],
    ]);

    $updatedAppointment = DB::transaction(function () use (
        $appointment,
        $request,
        $data
    ): Appointment {
        $lockedAppointment = Appointment::query()
            ->whereKey($appointment->id)
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless(
            (int) $lockedAppointment->patient_id === (int) $request->user()->id,
            404
        );

        if (
            ! in_array($lockedAppointment->status, ['pending', 'confirmed'], true)
            || ! $this->appointmentIsFuture($lockedAppointment)
        ) {
            throw ValidationException::withMessages([
                'appointment' => 'Esta cita ya no puede reprogramarse.',
            ]);
        }

        $date = Carbon::parse($data['appointment_date']);

        $currentDate = $lockedAppointment->appointment_date->toDateString();
        $currentTime = substr((string) $lockedAppointment->start_time, 0, 5);

        if (
            $currentDate === $date->toDateString()
            && $currentTime === $data['start_time']
        ) {
            throw ValidationException::withMessages([
                'start_time' => 'Selecciona una fecha u horario diferente.',
            ]);
        }

        $schedule = DoctorSchedule::query()
            ->where('staff_profile_id', $lockedAppointment->staff_profile_id)
            ->where('day_of_week', $date->dayOfWeekIso)
            ->lockForUpdate()
            ->first();

        if ($schedule === null) {
            throw ValidationException::withMessages([
                'appointment_date' => 'El médico no tiene horario disponible para esa fecha.',
            ]);
        }

        $availableTimes = $schedule->availableTimesFor(
            $date,
            (int) $lockedAppointment->id
        );

        if (! in_array($data['start_time'], $availableTimes, true)) {
            throw ValidationException::withMessages([
                'start_time' => 'Ese horario ya no está disponible. Selecciona otro.',
            ]);
        }
         $lockedAppointment->update([
    'appointment_date' => $date->toDateString(),
    'start_time' => $data['start_time'],
    'reminder_sent_at' => null,
          ]);
       
        return $lockedAppointment->fresh();
    });

    $this->notifyAppointmentStaff($updatedAppointment, 'updated');

    return redirect()->route('appointments.mine')
        ->with('status', 'Tu cita fue reprogramada correctamente.');
}


    public function doctorAgenda(Request $request): View
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);
        $doctor = $request->user()->staffProfile;
        abort_unless($doctor !== null && $request->user()->hasRole('doctor'), 404);

        $date = Carbon::parse($data['date'] ?? today()->toDateString());
        $appointments = $doctor->appointments()
            ->whereDate('appointment_date', $date)
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->with(['patient.patientProfile', 'nursingIntake', 'medicalConsultation', 'careEncounter'])
            ->orderBy('start_time')
            ->get()
            ->each(function (Appointment $appointment): void {
                $appointment->setAttribute(
                    'can_register_consultation',
                    $appointment->status === 'confirmed'
                    && Carbon::parse($appointment->appointment_date->toDateString().' '.$appointment->start_time)->isPast(),
                );
            });

        return view('appointments.doctor-agenda', compact('appointments', 'date'));
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingRules(): array
    {
        return [
            'staff_profile_id' => [
                'required',
                'integer',
                Rule::exists('staff_profiles', 'id')->where(fn ($query) => $query->whereIn(
                    'user_id',
                    User::query()->whereHas('role', fn ($role) => $role->where('code', 'doctor'))->select('id'),
                )),
            ],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAppointment(array $data, int $patientId, string $status): Appointment
    {
        return DB::transaction(function () use ($data, $patientId, $status): Appointment {
            $date = Carbon::parse($data['appointment_date']);
            $schedule = DoctorSchedule::query()
                ->where('staff_profile_id', $data['staff_profile_id'])
                ->where('day_of_week', $date->dayOfWeekIso)
                ->lockForUpdate()
                ->first();

            if ($schedule === null || ! in_array($data['start_time'], $schedule->availableTimesFor($date), true)) {
                throw ValidationException::withMessages([
                    'start_time' => 'Ese horario ya no está disponible. Elige otro horario.',
                ]);
            }

            return Appointment::query()->create([
                'staff_profile_id' => $schedule->staff_profile_id,
                'patient_id' => $patientId,
                'appointment_date' => $date->toDateString(),
                'start_time' => $data['start_time'],
                'status' => $status,
                'reason' => $data['reason'] ?? null,
            ]);
        });
    }


/**
 * Envía notificaciones internas al médico y al personal administrativo.
 */
private function notifyAppointmentStaff(Appointment $appointment, string $event): void
{
    $appointment->loadMissing('doctor.user');

    // Administradores y personal de recepción.
    $recipients = User::query()
        ->whereHas('role', fn ($query) => $query->whereIn(
            'code',
            ['admin', 'staff', 'receptionist']
        ))
        ->get();

    // Médico responsable de la cita.
    $doctorUser = $appointment->doctor?->user;

    if ($doctorUser !== null) {
        $recipients->push($doctorUser);
    }

    // Enviar una notificación a cada destinatario.
    $recipients->unique('id')->each(
        function (User $recipient) use ($appointment, $event): void {
            $recipient->notify(
                new AppointmentInternalNotification($appointment, $event)
            );
        }
    );
}


    private function appointmentIsFuture(Appointment $appointment): bool
    {
        return Carbon::parse($appointment->appointment_date->toDateString().' '.$appointment->start_time)->isFuture();
    }
}
