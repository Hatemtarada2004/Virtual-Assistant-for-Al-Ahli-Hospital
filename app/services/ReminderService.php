<?php

require_once __DIR__ . '/../repositories/ReminderRepository.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';

class ReminderService
{
    private ReminderRepository $reminders;
    private PatientRepository $patients;

    public function __construct()
    {
        $this->reminders = new ReminderRepository();
        $this->patients = new PatientRepository();
    }

    public function getByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        return array_map(
            fn($reminder) => $reminder->toArray(),
            $this->reminders->findByPatientId($patientId)
        );
    }

    public function getUpcomingByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        return array_map(
            fn($reminder) => $reminder->toArray(),
            $this->reminders->findUpcomingByPatientId($patientId)
        );
    }

    private function assertPatientExists(int $patientId): void
    {
        if (!$this->patients->existsById($patientId)) {
            throw new InvalidArgumentException("المريض بالمعرّف {$patientId} غير موجود.");
        }
    }
}
