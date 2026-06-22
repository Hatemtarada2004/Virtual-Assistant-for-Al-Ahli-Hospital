<?php

declare(strict_types=1);

require_once __DIR__ . '/ArabicPatientTextNormalizerService.php';

class HospitalWebsiteKnowledgeService
{
    private array $config;
    private ArabicPatientTextNormalizerService $normalizer;
    private string $cachePath;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/load_env.php';
        $this->normalizer = new ArabicPatientTextNormalizerService();
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $this->cachePath = $root . '/storage/cache/ahli-website-knowledge.json';
    }

    public function contextForPrompt(string $query): string
    {
        if (!(bool) ($this->config['website_knowledge_enabled'] ?? true)) {
            return '';
        }

        $documents = $this->loadDocuments();
        if (empty($documents)) {
            return '';
        }

        $normalizedQuery = $this->normalize($query);
        if ($normalizedQuery === '') {
            return '';
        }

        $tokens = $this->queryTokens($normalizedQuery);
        if (empty($tokens)) {
            return '';
        }

        $scored = [];
        foreach ($documents as $document) {
            $text = trim((string) ($document['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $normalizedText = $this->normalize($text);
            $paddedText = ' ' . $normalizedText . ' ';
            $score = 0;
            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($paddedText, ' ' . $token . ' ')) {
                    $score += mb_strlen($token, 'UTF-8') >= 4 ? 3 : 1;
                }
            }

            if ($score === 0 && str_contains($paddedText, ' ' . $normalizedQuery . ' ')) {
                $score = 4;
            }

            if ($score > 0) {
                $source = (string) ($document['source'] ?? '');
                $isStatic = !str_starts_with($source, 'http');
                // Static KB entries get 4× boost over scraped web content
                if ($isStatic) {
                    $score *= 4;
                }
                // Skip scraped web entries that look like navigation items (< 80 chars)
                if (!$isStatic && mb_strlen($text, 'UTF-8') < 80) {
                    continue;
                }
                $document['_score'] = $score;
                $scored[] = $document;
            }
        }

        usort($scored, static fn(array $a, array $b): int => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));
        if (empty($scored)) {
            return '';
        }

        // Show top result always; show second only if it scores ≥ 40% of top
        $topScore = (int) ($scored[0]['_score'] ?? 0);
        $top = array_filter(
            array_slice($scored, 0, 2),
            static fn(array $item): bool => ((int) ($item['_score'] ?? 0)) >= (int) ceil($topScore * 0.4)
        );

        $lines = [];
        foreach ($top as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = $text;
        }

        return implode("\n\n", $lines);
    }

    public function refreshCache(): array
    {
        $documents = [];
        foreach ($this->websiteSources() as $title => $url) {
            $html = $this->fetchUrl($url);
            if ($html === '') {
                continue;
            }

            foreach ($this->extractDocuments($html, $title, $url) as $document) {
                $documents[] = $document;
            }
        }

        if (empty($documents)) {
            return [];
        }

        $payload = [
            'fetched_at' => date('c'),
            'documents' => $documents,
        ];

        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $documents;
    }

    private function loadDocuments(): array
    {
        $static = $this->loadStaticKnowledge();
        $base = !empty($static) ? $static : $this->seedDocuments();

        $cached = $this->readCache();
        $maxAge = (int) ($this->config['website_knowledge_cache_ttl'] ?? 21600);
        if (!empty($cached['documents']) && ($cached['fetched_at_ts'] ?? 0) >= (time() - $maxAge)) {
            return array_merge($base, $cached['documents']);
        }

        $fresh = $this->refreshCache();
        if (!empty($fresh)) {
            return array_merge($base, $fresh);
        }

        if (!empty($cached['documents'])) {
            return array_merge($base, $cached['documents']);
        }

        return $base;
    }

    private function loadStaticKnowledge(): array
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $path = $root . '/storage/knowledge/ahli-chatbot-knowledge.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        // Strip UTF-8 BOM if present (prevents json_decode failure)
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['documents'])) {
            return [];
        }

        return array_values(array_filter($decoded['documents'], static fn(mixed $d): bool => is_array($d) && !empty($d['text'])));
    }

    private function readCache(): array
    {
        if (!is_file($this->cachePath)) {
            return [];
        }

        $raw = @file_get_contents($this->cachePath);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $documents = is_array($decoded['documents'] ?? null) ? $decoded['documents'] : [];
        $fetchedAt = strtotime((string) ($decoded['fetched_at'] ?? ''));

        return [
            'documents' => $documents,
            'fetched_at_ts' => $fetchedAt !== false ? $fetchedAt : 0,
        ];
    }

    private function websiteSources(): array
    {
        return [
            'الصفحة الرئيسية' => 'https://ahli.org/ar/',
            'مواعيد العيادات الخارجية' => 'https://ahli.org/ar/clinics.php',
            'اتصل بنا' => 'https://ahli.org/ar/contact.php',
            'شركات التأمين المعتمدة' => 'https://ahli.org/ar/show_news.php?art=184',
        ];
    }

    private function fetchUrl(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => (int) ($this->config['website_knowledge_timeout'] ?? 6),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'AhliHospitalChatbot/1.0',
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $httpCode < 200 || $httpCode >= 300) {
            return '';
        }

        return $raw;
    }

    private function extractDocuments(string $html, string $title, string $source): array
    {
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
        $text = preg_replace('/<\/(p|li|h1|h2|h3|h4|h5|h6|div|tr|section)>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        $documents = [];
        $seen = [];
        foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line, 'UTF-8') < 12) {
                continue;
            }
            if (mb_strlen($line, 'UTF-8') > 260) {
                $line = mb_substr($line, 0, 260, 'UTF-8');
            }

            $normalized = $this->normalize($line);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            $documents[] = [
                'title' => $title,
                'text' => $line,
                'source' => $source,
            ];
        }

        return $documents;
    }

    private function seedDocuments(): array
    {
        return [
            [
                'title' => 'اتصل بنا',
                'text' => 'المستشفى الأهلي يقع في فلسطين، الخليل، فرش الهوى. ساعات العمل الرسمية 24 ساعة / 7 أيام. هاتف المستشفى 9702229247+ وحجز العيادات الخارجية 1700200400.',
                'source' => 'hospital-info',
            ],
            [
                'title' => 'مواعيد العيادات الخارجية',
                'text' => 'العيادات الخارجية تعمل من السبت إلى الخميس من الساعة 8 صباحاً حتى 8 مساءً. يوم الجمعة إجازة. رقم الحجز المركزي 1700200400.',
                'source' => 'hospital-info',
            ],
            [
                'title' => 'أطباء مركز القلب',
                'text' => 'أطباء مركز القلب: د. أنس شاور (ثلاثاء وخميس)، د. باجس عمرو (إثنين)، د. طارق موسى (سبت وأربعاء)، د. بشر مرزوقة جراحة القلب المفتوح. حجز 1700200400.',
                'source' => 'hospital-info',
            ],
            [
                'title' => 'تعليمات بعد عملية القلب المفتوح',
                'text' => 'تعليمات بعد عملية قلب مفتوح: راحة تامة أسبوعين، مشي تدريجي بحد أقصى 500 متر. لا رفع أثقال أو جهد 3 أشهر. العناية بجرح القص جافاً نظيفاً لا احمرار ولا إفراز. استحمام بعد أسبوع. أدوية القلب والمميعات في مواعيدها. متابعة طبيب القلب بعد أسبوع من الخروج. للطوارئ اتصل 2229247.',
                'source' => 'post-op-instructions',
            ],
            [
                'title' => 'تعليمات بعد زراعة الصمام الاصطناعي',
                'text' => 'تعليمات بعد زراعة الصمام الاصطناعي: الاستمرار على مميعات الدم مدى الحياة. قياس INR بانتظام. تجنب الإصابات والنزيف. أبلغ أي طبيب بأنك على مميع. اتصل فوراً 2229247 عند نزيف أو كدمات غير عادية.',
                'source' => 'post-op-instructions',
            ],
            [
                'title' => 'تعليمات بعد القسطرة القلبية',
                'text' => 'تعليمات بعد قسطرة القلب: راحة تامة يوم كامل. ضغط مكان الدخول ساعة. لا رفع ثقيل 48 ساعة. شرب ماء وفير. أي تورم أو نزيف في مكان الدخول راجع الطوارئ فوراً. متابعة بعد أسبوع في مركز القلب.',
                'source' => 'post-op-instructions',
            ],
            [
                'title' => 'تعليمات بعد عملية المرارة بالمنظار',
                'text' => 'تعليمات بعد عملية المرارة: سوائل 6 ساعات ثم أكل خفيف. تجنب الدهون والمقلي أسبوعين. جروح المنظار جافة 3 أيام. عودة للعمل بعد أسبوع. ألم الكتف طبيعي ويزول. متابعة الجراح بعد أسبوع.',
                'source' => 'post-op-instructions',
            ],
            [
                'title' => 'تعليمات بعد عملية الزائدة الدودية',
                'text' => 'تعليمات بعد عملية الزائدة: راحة 48 ساعة. سوائل أولاً ثم تدريج الأكل. تغيير الضمادة يومياً. لا رفع ثقيل أسبوعين. للحرارة أو الاحمرار أو الإفراز من الجرح توجه للطوارئ.',
                'source' => 'post-op-instructions',
            ],
            [
                'title' => 'تعليمات بعد عملية العظام',
                'text' => 'تعليمات بعد عملية العظام أو الكسور: الجبيرة لا تبلل. ارفع الطرف فوق مستوى القلب. تمارين الأصابع كل ساعة. لا تحمّل على الساق حسب تعليمات الجراح. خدر أو زرقة في الجبيرة: طوارئ فوراً.',
                'source' => 'post-op-instructions',
            ],
            [
                'title' => 'تعليمات بعد الولادة القيصرية',
                'text' => 'تعليمات بعد الولادة القيصرية: راحة مع قيام بسيط بعد 24 ساعة. جرح البطن جاف ونظيف. استحمام بعد أسبوع. لا قيادة 6 أسابيع. متابعة طبيبة النسائية بعد أسبوع. للنزيف الشديد أو تورم الساق أو ضيق التنفس توجهي للطوارئ.',
                'source' => 'post-op-instructions',
            ],
            [
                'title' => 'حقوق المريض وواجباته',
                'text' => 'حقوق المريض: رعاية باحترام وكرامة، معلومات كاملة عن حالته، الموافقة على أو رفض العلاج، خصوصية المعلومات، تقديم شكوى. واجبات المريض: التعاون مع الفريق الطبي، إعطاء معلومات صحية دقيقة، الالتزام بتعليمات الطبيب.',
                'source' => 'patient-rights',
            ],
            [
                'title' => 'الكادر الطبي',
                'text' => 'من الكادر الطبي: د. إبراهيم الزعتري في الأشعة التشخيصية، ود. أنس يحيى شاور في القلب والقسطرة، ود. أمجد شهاب مجاهد في جراحة العيون.',
                'source' => 'hospital-info',
            ],
            [
                'title' => 'التأمينات المعتمدة',
                'text' => 'شركات التأمين الصحي المعتمدة: غلوب مد، العالمية المتحدة، ترست العالمية، سمارت هيلث، فلسطين للتأمين، التكافل الفلسطينية، الوطنية، تمكين، وي كير، المشرق، البركة.',
                'source' => 'insurance',
            ],
        ];
    }

    private function queryTokens(string $normalizedQuery): array
    {
        static $stopWords = [
            'بدي', 'اريد', 'احتاج', 'ابغي', 'انا', 'في', 'من', 'على', 'عن',
            'هاد', 'هاي', 'هو', 'هي', 'هم', 'كيف', 'ايش', 'شو', 'وين', 'ليش',
            'كم', 'لو', 'اذا', 'هل', 'ما', 'مش', 'لا', 'نعم', 'اه', 'مع',
            'عند', 'يعني', 'ممكن', 'تمام', 'بس', 'اكتب', 'احكي', 'قولي',
            'بقدر', 'يا', 'الي', 'لك', 'لي', 'كان', 'بده', 'بدها',
        ];

        $tokens = preg_split('/\s+/u', $normalizedQuery) ?: [];
        $tokens = array_values(array_filter($tokens, static function (string $token) use ($stopWords): bool {
            return mb_strlen($token, 'UTF-8') >= 2 && !in_array($token, $stopWords, true);
        }));
        return array_values(array_unique($tokens));
    }

    private function normalize(string $text): string
    {
        $text = $this->normalizer->normalizeForNlu($text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}\s:-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
