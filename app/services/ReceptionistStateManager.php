<?php

declare(strict_types=1);

class ReceptionistStateManager
{
    private const STATE_KEY = 'ahli_llm_receptionist_state';
    private const CLIENT_PAGE_KEY = 'ahli_llm_receptionist_page_id';
    private const MAX_TURNS = 12;

    public function syncClientPage(?string $pageId): void
    {
        $pageId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $pageId) ?? '';
        if ($pageId === '') {
            return;
        }

        $current = (string) ($_SESSION[self::CLIENT_PAGE_KEY] ?? '');
        if ($current !== '' && $current !== $pageId) {
            $this->reset();
        }

        $_SESSION[self::CLIENT_PAGE_KEY] = $pageId;
    }

    public function state(): array
    {
        $state = isset($_SESSION[self::STATE_KEY]) && is_array($_SESSION[self::STATE_KEY])
            ? $_SESSION[self::STATE_KEY]
            : [];

        return array_replace_recursive($this->defaultState(), $state);
    }

    public function save(array $state): void
    {
        $_SESSION[self::STATE_KEY] = array_replace_recursive($this->defaultState(), $state);
    }

    public function reset(): void
    {
        unset(
            $_SESSION[self::STATE_KEY],
            $_SESSION['ahli_chat_agent_state'],
            $_SESSION['ahli_chat_conversation_memory'],
            $_SESSION['ahli_chat_reply_variants']
        );
    }

    public function appendTurn(array $state, string $role, string $text): array
    {
        $turns = isset($state['turns']) && is_array($state['turns']) ? $state['turns'] : [];
        $turns[] = [
            'role' => $role,
            'text' => $this->limitText($text, 700),
            'at' => date('c'),
        ];

        $state['turns'] = array_slice($turns, -self::MAX_TURNS);
        $state['memory_summary'] = $this->buildRollingSummary($state);

        return $state;
    }

    public function defaultState(): array
    {
        return [
            'intent' => 'general',
            'goal' => 'general',
            'last_missing_field' => null,
            'memory_summary' => '',
            'turns' => [],
            'booking' => $this->defaultBooking(),
            'lab_result' => $this->defaultLabResult(),
            'appointment_action' => $this->defaultAppointmentAction(),
        ];
    }

    public function defaultBooking(): array
    {
        return [
            'active' => false,
            'stage' => 'idle',
            'selected_department' => null,
            'selected_doctor' => null,
            'candidate_doctors' => [],
            'selected_date' => null,
            'selected_time' => null,
            'desired_period' => null,
            'patient_name' => null,
            'patient_phone' => null,
            'patient_email' => null,
            'patient_national_id' => null,
            'new_patient_requested' => false,
            'verified_patient' => null,
            'reason' => null,
            'pending_specialty_alias' => null,
            'pending_specialty_label' => null,
            'verification_status' => 'not_started',
            'otp_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
            'last_missing_field' => null,
            'last_slots' => [],
            'appointment' => null,
            'booked_at' => null,
        ];
    }

    public function defaultLabResult(): array
    {
        return [
            'active' => false,
            'stage' => 'idle',
            'requested_test_name' => null,
            'requested_status' => null,
            'patient_national_id' => null,
            'verified_patient' => null,
            'patient_email' => null,
            'selected_tests' => [],
            'verification_status' => 'not_started',
            'otp_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ];
    }

    public function defaultAppointmentAction(): array
    {
        return [
            'active' => false,
            'type' => null,
            'stage' => 'idle',
            'selected_appointment' => null,
            'candidate_appointments' => [],
            'requested_date' => null,
            'requested_time' => null,
            'last_missing_field' => null,
            'patient' => null,
            'appointment_id_hint' => null,
        ];
    }

    private function buildRollingSummary(array $state): string
    {
        $booking = $state['booking'] ?? [];
        $appointmentAction = $state['appointment_action'] ?? [];
        $facts = [];

        if (($booking['active'] ?? false) === true) {
            $facts[] = 'المستخدم داخل مسار حجز موعد';
        }
        if (!empty($booking['selected_doctor']['full_name'])) {
            $facts[] = 'الطبيب: ' . $booking['selected_doctor']['full_name'];
        }
        if (!empty($booking['selected_department']['name'])) {
            $facts[] = 'القسم: ' . $booking['selected_department']['name'];
        }
        if (!empty($booking['selected_date'])) {
            $facts[] = 'التاريخ: ' . $booking['selected_date'];
        }
        if (!empty($booking['selected_time'])) {
            $facts[] = 'الوقت: ' . $booking['selected_time'];
        }
        if (!empty($booking['patient_email'])) {
            $facts[] = 'الإيميل موجود';
        }
        if (($appointmentAction['active'] ?? false) === true) {
            $facts[] = 'المستخدم داخل مسار ' . (($appointmentAction['type'] ?? '') === 'reschedule' ? 'تعديل موعد' : 'إلغاء موعد');
        }
        if (!empty($appointmentAction['selected_appointment']['appointment_id'])) {
            $facts[] = 'موعد مختار: #' . $appointmentAction['selected_appointment']['appointment_id'];
        }

        $recent = array_slice($state['turns'] ?? [], -4);
        foreach ($recent as $turn) {
            $role = ($turn['role'] ?? '') === 'bot' ? 'المساعد' : 'المستخدم';
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text !== '') {
                $facts[] = $role . ': ' . $this->limitText($text, 120);
            }
        }

        return $this->limitText(implode(' | ', $facts), 900);
    }

    private function limitText(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit, 'UTF-8');
    }
}
