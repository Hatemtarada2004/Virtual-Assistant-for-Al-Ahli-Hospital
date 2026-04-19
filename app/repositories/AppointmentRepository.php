<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/AppointmentServiceLinkRepository.php';

class AppointmentRepository
{
    private PDO $pdo;
    private AppointmentServiceLinkRepository $appointmentServices;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->appointmentServices = new AppointmentServiceLinkRepository();
    }

    private function baseSelect(): string
    {
        return '
            SELECT a.appointment_id,
                   a.patient_id,
                   a.doctor_id,
                   a.department_id,
                   a.appointment_datetime,
                   a.status,
                   a.reason,
                   a.created_at,
                   p.full_name AS patient_name,
                   d.full_name AS doctor_name,
                   dep.name AS department_name
            FROM Appointment a
            JOIN Patient p ON p.patient_id = a.patient_id
            JOIN Doctor d ON d.doctor_id = a.doctor_id
            JOIN Department dep ON dep.department_id = a.department_id
        ';
    }

    public function findById(int $id): ?Appointment
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE a.appointment_id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $appointment = new Appointment($row);
        $appointment->services = $this->appointmentServices->findServicesByAppointmentId($appointment->appointment_id);

        return $appointment;
    }

    public function findByPatientId(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . '
            WHERE a.patient_id = :patient_id
            ORDER BY a.appointment_datetime DESC
        '
        );
        $stmt->execute([':patient_id' => $patientId]);

        $appointments = array_map(fn(array $row) => new Appointment($row), $stmt->fetchAll());
        foreach ($appointments as $appointment) {
            $appointment->services = $this->appointmentServices->findServicesByAppointmentId($appointment->appointment_id);
        }

        return $appointments;
    }

    public function findBookedTimesByDoctorAndDate(int $doctorId, string $date, ?int $excludeId = null): array
    {
        $sql = '
            SELECT TIME(appointment_datetime) AS booked_time
            FROM Appointment
            WHERE doctor_id = :doctor_id
              AND DATE(appointment_datetime) = :date
              AND status = :status
        ';

        $params = [
            ':doctor_id' => $doctorId,
            ':date' => $date,
            ':status' => Appointment::STATUS_BOOKED,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND appointment_id != :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_column($stmt->fetchAll(), 'booked_time');
    }

    public function hasConflict(int $doctorId, string $datetime, ?int $excludeId = null): bool
    {
        $sql = '
            SELECT COUNT(*) FROM Appointment
            WHERE doctor_id = :doctor_id
              AND appointment_datetime = :datetime
              AND status = :status
        ';

        $params = [
            ':doctor_id' => $doctorId,
            ':datetime' => $datetime,
            ':status' => Appointment::STATUS_BOOKED,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND appointment_id != :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(
        int $patientId,
        int $doctorId,
        int $departmentId,
        string $datetime,
        ?string $reason
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO Appointment
                (patient_id, doctor_id, department_id, appointment_datetime, status, reason)
            VALUES
                (:patient_id, :doctor_id, :department_id, :datetime, :status, :reason)
        ');
        $stmt->execute([
            ':patient_id' => $patientId,
            ':doctor_id' => $doctorId,
            ':department_id' => $departmentId,
            ':datetime' => $datetime,
            ':status' => Appointment::STATUS_BOOKED,
            ':reason' => $reason,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function replaceServices(int $appointmentId, array $serviceIds): void
    {
        $this->appointmentServices->replaceServicesForAppointment($appointmentId, $serviceIds);
    }

    public function updateStatus(int $appointmentId, string $status): int
    {
        $stmt = $this->pdo->prepare('
            UPDATE Appointment
            SET status = :status
            WHERE appointment_id = :id
        ');
        $stmt->execute([
            ':status' => $status,
            ':id' => $appointmentId,
        ]);

        return $stmt->rowCount();
    }

    public function updateDatetime(int $appointmentId, string $datetime): int
    {
        $stmt = $this->pdo->prepare('
            UPDATE Appointment
            SET appointment_datetime = :datetime
            WHERE appointment_id = :id
        ');
        $stmt->execute([
            ':datetime' => $datetime,
            ':id' => $appointmentId,
        ]);

        return $stmt->rowCount();
    }
}
