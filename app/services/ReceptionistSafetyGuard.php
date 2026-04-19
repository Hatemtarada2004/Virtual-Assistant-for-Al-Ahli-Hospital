<?php

declare(strict_types=1);

require_once __DIR__ . '/ArabicPatientTextNormalizerService.php';

class ReceptionistSafetyGuard
{
    private ArabicPatientTextNormalizerService $normalizer;

    public function __construct()
    {
        $this->normalizer = new ArabicPatientTextNormalizerService();
    }

    public function assess(string $message): array
    {
        $normalized = $this->normalize($message);

        if ($this->containsAny($normalized, [
            'الم صدر شديد',
            'وجع صدر شديد',
            'مش قادر اتنفس',
            'مش قادره اتنفس',
            'ضيق نفس شديد',
            'اختناق',
            'نزيف شديد',
            'نزيف ما بوقف',
            'اغماء',
            'فقدان وعي',
            'جلطه',
            'سكتة',
            'ضعف مفاجئ',
            'الم قوي جدا',
            'حرق شديد',
            'تسمم',
            'حادث',
        ])) {
            return [
                'level' => 'emergency',
                'intent' => 'medical_emergency',
                'draft_reply' => 'الأعراض اللي بتحكي عنها ممكن تكون طارئة. الأفضل تتوجه للطوارئ فوراً أو تتواصل مع الإسعاف/رقم الطوارئ في منطقتك. إذا أنت داخل المستشفى، توجه لقسم الطوارئ مباشرة.',
            ];
        }

        if ($this->containsAny($normalized, [
            'اعراض',
            'وجع',
            'الم',
            'حراره',
            'حمى',
            'سعال',
            'كحه',
            'دوخه',
            'غثيان',
            'استفراغ',
            'اسهال',
            'صداع',
            'بطني',
            'معده',
            'ضغط',
            'سكر',
        ])) {
            return [
                'level' => 'medical_general',
                'intent' => 'medical_general',
                'draft_reply' => null,
            ];
        }

        return ['level' => 'normal', 'intent' => null, 'draft_reply' => null];
    }

    private function normalize(string $text): string
    {
        $text = $this->normalizer->normalizeForNlu($text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\x{064B}-\x{065F}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}\s:-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = $this->normalize((string) $needle);
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
