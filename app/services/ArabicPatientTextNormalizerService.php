<?php

declare(strict_types=1);

class ArabicPatientTextNormalizerService
{
    private array $canonicalTokens = [
        'بدي', 'اريد', 'احتاج', 'احجز', 'حجز', 'موعد', 'مواعيد', 'متاح', 'متوفر',
        'دكتور', 'طبيب', 'تخصص', 'قلب', 'قسطرة', 'اطفال', 'عيون', 'اشعة',
        'باطنية', 'نسائية', 'جراحة', 'طوارئ', 'مختبر', 'تحاليل', 'فحص',
        'فاتورة', 'تامين', 'تذكير', 'شكوى', 'ملاحظة', 'الغاء', 'تعديل',
        'بكرا', 'اليوم', 'ساعة', 'وقت', 'سبب', 'زيارة', 'مراجعة',
    ];

    private array $phraseCorrections = [
        '/\b(?:بدى|بديي|بدييی|بددي|بدي)\b/u' => 'بدي',
        '/\b(?:اريد|اررريد|اريدد|أريد|إريد)\b/u' => 'اريد',
        '/\b(?:احتاج|بحتاج|محتاج|محتاجة|محتاجه)\b/u' => 'احتاج',
        '/\b(?:احجذ|اححجز|احجزز|احجزل|احجزي|احجزلي)\b/u' => 'احجز',
        '/\b(?:حجزز|حجوز|حجذ|حجزلي)\b/u' => 'حجز',
        '/\b(?:موعدي|موعددد|موعدد|مواعدد|مواعيدد|معود|موعد)\b/u' => 'موعد',
        '/\b(?:دكتوور|دكتورر|دختور|دكتور)\b/u' => 'دكتور',
        '/\b(?:طبييب|طبيبب|دكتور)\b/u' => 'طبيب',
        '/\b(?:قللب|ئلب|قلبب|القلب)\b/u' => 'قلب',
        '/\b(?:اطفال|اطفأل|اطفالل|الاطفال|الأطفال)\b/u' => 'اطفال',
        '/\b(?:عيوون|عيونن|العين|العيون)\b/u' => 'عيون',
        '/\b(?:اشعه|اشعة|الاشعه|الأشعة|اشعاع)\b/u' => 'اشعة',
        '/\b(?:نسائيه|نسائية|النسايية|نسوانية)\b/u' => 'نسائية',
        '/\b(?:باطنيه|باطنية|الباطنية)\b/u' => 'باطنية',
        '/\b(?:جراحه|جراحة|الجراحه)\b/u' => 'جراحة',
        '/\b(?:طوارى|طوارئ|الطواري|الطوارئ)\b/u' => 'طوارئ',
        '/\b(?:بكراا|بكره|بكرا)\b/u' => 'بكرا',
        '/\b(?:الساعه|الساعة|ساعه)\b/u' => 'ساعة',
        '/\b(?:الغى|الغاء|إلغاء|الغي|ألغى)\b/u' => 'الغاء',
        '/\b(?:اعدل|تعديل|عدل|غير|غيّر)\b/u' => 'تعديل',
        '/\b(?:تأمين|تامين|التامين|التأمين)\b/u' => 'تامين',
        '/\b(?:فاتوره|فاتورة|فواتير)\b/u' => 'فاتورة',
        '/\b(?:شكوه|شكوى|شكاية)\b/u' => 'شكوى',
        '/\b(?:ملاحظه|ملاحظة|اقتراح)\b/u' => 'ملاحظة',
    ];

    public function variants(string $message): array
    {
        $original = trim($message);
        $normalized = $this->normalizeForNlu($original);

        return array_values(array_unique(array_filter([$original, $normalized], static fn(string $item) => trim($item) !== '')));
    }

    public function normalizeForNlu(string $message): string
    {
        $text = $this->normalizeCharacters($message);

        foreach ($this->phraseCorrections as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        $tokens = preg_split('/\s+/u', $text) ?: [];
        $tokens = array_map(fn(string $token): string => $this->correctToken($token), $tokens);

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $tokens)) ?? implode(' ', $tokens));
    }

    private function normalizeCharacters(string $text): string
    {
        $text = $this->normalizeDigits($text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        $text = str_replace('ـ', '', $text);
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace(['ؤ', 'ئ'], ['و', 'ي'], $text);
        $text = str_replace(['ى', 'ة'], ['ي', 'ه'], $text);
        $text = preg_replace('/([\p{Arabic}])\1{2,}/u', '$1', $text) ?? $text;
        $text = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}\s:#\-\/]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function correctToken(string $token): string
    {
        $token = trim($token);
        if ($token === '' || is_numeric($token) || mb_strlen($token, 'UTF-8') < 3) {
            return $token;
        }

        foreach ($this->canonicalTokens as $canonical) {
            if ($token === $canonical) {
                return $token;
            }
        }

        $best = $token;
        $bestDistance = 99;
        foreach ($this->canonicalTokens as $canonical) {
            $distance = $this->unicodeDistance($token, $canonical);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $canonical;
            }
        }

        $length = mb_strlen($token, 'UTF-8');
        $maxDistance = $length >= 7 ? 2 : 1;

        return $bestDistance <= $maxDistance ? $best : $token;
    }

    private function unicodeDistance(string $a, string $b): int
    {
        $left = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $right = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rows = count($left);
        $cols = count($right);

        $previous = range(0, $cols);
        for ($i = 1; $i <= $rows; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $cols; $j++) {
                $cost = $left[$i - 1] === $right[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + $cost
                );
            }
            $previous = $current;
        }

        return $previous[$cols] ?? max($rows, $cols);
    }

    private function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
