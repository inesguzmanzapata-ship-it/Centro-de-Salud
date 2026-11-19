<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Notifications\Notification;

class AppointmentInternalNotification extends Notification
{
    public function __construct(
        public Appointment $appointment,
        public string $event
    ) {
    }

    /**
     * Las notificaciones se guardarán en la base de datos.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Información que se almacenará para cada usuario.
     */
    public function toArray(object $notifiable): array
    {
        $appointment = $this->appointment->loadMissing([
            'patient',
            'doctor.user',
        ]);

        [$title, $description] = match ($this->event) {
            'created' => [
                'Nueva cita',
                'Se ha registrado una nueva cita.',
            ],
            'updated' => [
                'Cita modificada',
                'Se ha modificado una cita.',
            ],
            'cancelled' => [
                'Cita cancelada',
                'Se ha cancelado una cita.',
            ],
            default => [
                'Actualización de cita',
                'Se ha actualizado la información de una cita.',
            ],
        };

        return [
            'title' => $title,
            'message' => $description,
            'event' => $this->event,
            'appointment_id' => $appointment->id,
            'booking_code' => $appointment->booking_code,
            'patient_name' => $appointment->patient?->name,
            'doctor_name' => $appointment->doctor?->user?->name,
            'appointment_date' => $appointment->appointment_date?->format('d/m/Y'),
            'start_time' => substr((string) $appointment->start_time, 0, 5),
        ];
    }
}
