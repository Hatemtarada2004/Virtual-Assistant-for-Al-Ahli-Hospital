<?php

class Validator
{
    private array $errors = [];
    private array $data = [];

    public static function validate(array $data, array $rules): array
    {
        $validator = new self();
        $validator->data = $data;

        foreach ($rules as $field => $fieldRules) {
            foreach ($fieldRules as $rule) {
                $validator->applyRule($field, $rule);
            }
        }

        return $validator->errors;
    }

    private function applyRule(string $field, string $rule): void
    {
        if (str_contains($rule, ':')) {
            [$ruleName, $param] = explode(':', $rule, 2);
        } else {
            $ruleName = $rule;
            $param = null;
        }

        $value = $this->data[$field] ?? null;

        match ($ruleName) {
            'required' => $this->validateRequired($field, $value),
            'integer' => $this->validateInteger($field, $value),
            'numeric' => $this->validateNumeric($field, $value),
            'string' => $this->validateString($field, $value),
            'email' => $this->validateEmail($field, $value),
            'date' => $this->validateDate($field, $value),
            'datetime' => $this->validateDatetime($field, $value),
            'enum' => $this->validateEnum($field, $value, $param),
            'min' => $this->validateMin($field, $value, (int) $param),
            'max' => $this->validateMax($field, $value, (int) $param),
            'minLength' => $this->validateMinLength($field, $value, (int) $param),
            'maxLength' => $this->validateMaxLength($field, $value, (int) $param),
            'phone' => $this->validatePhone($field, $value),
            default => null,
        };
    }

    private function validateRequired(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->addError($field, "الحقل '{$field}' مطلوب.");
        }
    }

    private function validateInteger(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!filter_var($value, FILTER_VALIDATE_INT)) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون عددًا صحيحًا.");
        }
    }

    private function validateNumeric(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_numeric($value)) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون قيمة رقمية.");
        }
    }

    private function validateString(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value)) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون نصًا.");
        }
    }

    private function validateEmail(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون بريدًا إلكترونيًا صحيحًا.");
        }
    }

    private function validateDate(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $date = DateTime::createFromFormat('Y-m-d', (string) $value);
        if (!$date || $date->format('Y-m-d') !== (string) $value) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون تاريخًا بصيغة YYYY-MM-DD.");
        }
    }

    private function validateDatetime(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $value = (string) $value;
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $value)
            ?: DateTime::createFromFormat('Y-m-d H:i', $value);

        if (!$date) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون تاريخًا ووقتًا بصيغة YYYY-MM-DD HH:MM:SS.");
        }
    }

    private function validateEnum(string $field, mixed $value, ?string $param): void
    {
        if ($value === null || $value === '' || $param === null) {
            return;
        }

        $allowed = array_map('trim', explode(',', $param));
        if (!in_array((string) $value, $allowed, true)) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون إحدى القيم: " . implode(', ', $allowed) . '.');
        }
    }

    private function validateMin(string $field, mixed $value, int $min): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ((float) $value < $min) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون {$min} أو أكثر.");
        }
    }

    private function validateMax(string $field, mixed $value, int $max): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ((float) $value > $max) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون {$max} أو أقل.");
        }
    }

    private function validateMinLength(string $field, mixed $value, int $min): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (mb_strlen((string) $value) < $min) {
            $this->addError($field, "الحقل '{$field}' يجب ألا يقل عن {$min} حرف.");
        }
    }

    private function validateMaxLength(string $field, mixed $value, int $max): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (mb_strlen((string) $value) > $max) {
            $this->addError($field, "الحقل '{$field}' يجب ألا يتجاوز {$max} حرفًا.");
        }
    }

    private function validatePhone(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalized = preg_replace('/[\s\-]/', '', (string) $value);
        $pattern = '/^(\+?\d{7,15}|0\d{8,11})$/';
        if (!preg_match($pattern, (string) $normalized)) {
            $this->addError($field, "الحقل '{$field}' يجب أن يكون رقم هاتف صحيحًا.");
        }
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
