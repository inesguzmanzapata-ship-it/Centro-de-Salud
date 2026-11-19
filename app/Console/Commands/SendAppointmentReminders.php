<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('app:send-appointment-reminders')]
#[Description('Envía recordatorios de citas médicas 24 horas antes')]
class SendAppointmentReminders extends Command
{
    public function handle(): int
    {
        $now = Carbon::now();

        $reminderStart = $now->copy()->addHours(24)->subMinute();
        $reminderEnd = $now->copy()->addHours(24)->addMinute();

        $appointments = Appointment::query()
            ->where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
            ->whereBetween('appointment_date', [
                $reminderStart->toDateString(),
                $reminderEnd->toDateString(),
            ])
            ->with(['patient', 'doctor.user'])
            ->get()
            ->filter(function (Appointment $appointment) use (
                $reminderStart,
                $reminderEnd
            ): bool {
                $appointmentDateTime = Carbon::parse(
                    $appointment->appointment_date->toDateString()
                    .' '.$appointment->start_time
                );

                return $appointmentDateTime->between(
                    $reminderStart,
                    $reminderEnd
                );
            });

        foreach ($appointments as $appointment) {
            $patient = $appointment->patient;

            if ($patient === null || blank($patient->email)) {
                $this->warn(
                    "La cita {$appointment->booking_code} no tiene correo de paciente."
                );
                continue;
            }

            Mail::raw(
                "Hola {$patient->name}, te recordamos que tienes una cita médica en el Centro de Salud San Juan.\n\n"
                ."Médico: ".($appointment->doctor?->user?->name ?? 'No disponible')."\n"
                ."Fecha: ".$appointment->appointment_date->format('d/m/Y')."\n"
                ."Hora: ".substr((string) $appointment->start_time, 0, 5)."\n"
                ."Código: ".$appointment->booking_code,
                function ($message) use ($patient): void {
                    $message->to($patient->email)
                        ->subject('Recordatorio de cita médica - Centro de Salud San Juan');
                }
            );

            $appointment->update([
                'reminder_sent_at' => Carbon::now(),
            ]);

            $this->info(
                "Recordatorio enviado: {$appointment->booking_code}"
            );
        }

        $this->info(
            "Proceso finalizado. Citas revisadas: {$appointments->count()}"
        );

        return self::SUCCESS;
    }
}
