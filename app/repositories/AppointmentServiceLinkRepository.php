<?php

require_once __DIR__ . '/../config/database.php';

class AppointmentServiceLinkRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function replaceServicesForAppointment(int $appointmentId, array $serviceIds): void
    {
        $delete = $this->pdo->prepare('DELETE FROM AppointmentService WHERE appointment_id = :appointment_id');
        $delete->execute([':appointment_id' => $appointmentId]);

        if (empty($serviceIds)) {
            return;
        }

        $insert = $this->pdo->prepare('
            INSERT INTO AppointmentService (appointment_id, service_id, notes)
            VALUES (:appointment_id, :service_id, NULL)
        ');

        foreach (array_unique($serviceIds) as $serviceId) {
            $insert->execute([
                ':appointment_id' => $appointmentId,
                ':service_id' => $serviceId,
            ]);
        }
    }

    public function findServicesByAppointmentId(int $appointmentId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT aps.service_id,
                   s.name AS service_name,
                   aps.notes
            FROM AppointmentService aps
            JOIN Service s ON s.service_id = aps.service_id
            WHERE aps.appointment_id = :appointment_id
            ORDER BY aps.service_id ASC
        ');
        $stmt->execute([':appointment_id' => $appointmentId]);

        return $stmt->fetchAll();
    }
}
