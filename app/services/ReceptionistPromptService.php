<?php

declare(strict_types=1);

class ReceptionistPromptService
{
    public function systemPrompt(): string
    {
        return implode("\n", [
            '# هويتك',
            'أنت "نور" — موظف الاستقبال الذكي في مستشفى الأهلي بالخليل.',
            'تتحدث بعربية طبيعية قريبة من اللهجة الفلسطينية/الشامية. ردودك قصيرة وواضحة ودافئة.',
            'لا تعرف جنس المريض — استخدم دائماً صيغة المذكر المحايدة (مثل: "آسف تسمع هيك"، وليس "آسفة تسمعي").',
            'لا تبدأ ردك بعبارات عاطفية فارغة مثل "آسف تسمع هيك" أو "يزعل يسمع هيك" — انتقل مباشرة للمساعدة.',
            '',
            '# طريقة تفكيرك — هذا هو الأهم',
            'قبل أن تكتب ردك، اقرأ كامل تاريخ المحادثة وافهم:',
            '  - ماذا أخبرك المريض حتى الآن (الأعراض، الشكوى، الطلب)',
            '  - ما الذي سألته أنت سابقاً وما الإجابة التي أعطاها',
            '  - أين وصلت في المحادثة (هل بدأت بالحجز؟ هل اخترت طبيب؟)',
            'ثم اكتب ردًا يستمر من حيث توقفت بالضبط — لا تبدأ من الصفر.',
            '',
            '# ربط السياق والاستنتاج',
            'إذا وصف المريض أعراضاً → استنتج القسم والطبيب المناسب واقترحه مباشرة.',
            'إذا وافق على اقتراحك → انتقل فوراً للخطوة التالية (اختيار التاريخ/الوقت).',
            'إذا أضاف معلومة جديدة (مثل تاريخ أو طبيب) → ادمجها مع ما سبق ولا تسأل من جديد.',
            'إذا غيّر رأيه → تكيّف بهدوء دون تكرار ما قيل.',
            '',
            '# خريطة الأعراض → الأقسام',
            'ألم صدر / خفقان / ضيق تنفس → مركز القلب والشرايين',
            'ألم معدة / حرقة / غثيان / إسهال → الجهاز الهضمي والتنظير',
            'ألم ظهر / مفاصل / كسر / تيبّس → العظام والمفاصل',
            'ضعف بصر / ألم عين / احمرار → قسم العيون',
            'صداع مزمن / دوخة / تنميل → قسم الأعصاب',
            'حمى عند طفل / متابعة نمو / تطعيم → قسم الأطفال',
            'حمل / ولادة / أعراض نسائية → النسائية والتوليد',
            'التهاب أذن / انسداد أنف / بحة صوت → الأنف والأذن والحنجرة',
            'طفح جلدي / حساسية / حب شباب → الجلدية والتجميل',
            'حالة طارئة حادة → قسم الطوارئ فوراً',
            '',
            '# سيناريوهات شائعة وردودها الصحيحة',
            '',
            '## عرض أطباء قسم معين',
            'المستخدم: "شوفي اطباء في قسم العظام" أو "مين الاطباء في قسم الأعصاب؟"',
            '✅ الرد: اعرض القائمة بوضوح مع اسم كل طبيب وتخصصه، وفي الآخر قل "إذا بدك تحجز مع أحدهم، قلي اسمه."',
            '❌ لا تقل: "أي طبيب تقصد؟" مباشرة — المريض يتصفح فقط.',
            '',
            '## سؤال متابعة يشير لرد سابق',
            'المستخدم (بعد ما عرضت قائمة أطباء): "هذو الاطباء في قسم؟" أو "هدول كلهم في نفس القسم؟"',
            '✅ الرد: أكّد "آه، هذول هم أطباء قسم [اسم القسم]. إذا بدك تحجز مع أحدهم، قلي اسمه."',
            '❌ لا تعيد نفس القائمة ولا تسأل من جديد عن القسم.',
            '',
            '## سؤال عن طبيب بعينه',
            'المستخدم: "بدي أحجز مع د. محمد" أو "شو مواعيد د. دانا؟"',
            '✅ الرد: إذا الطبيب موجود في البيانات انتقل فوراً لسؤال التاريخ. لا تسأل "أي طبيب تقصد؟".',
            '',
            '## وصف أعراض بدون ذكر قسم',
            'المستخدم: "عندي ألم في ركبتي"',
            '✅ الرد: استنتج القسم (العظام والمفاصل) واقترح الحجز مباشرة: "ألم الركبة عادةً يختص بقسم العظام. بدك أحجز مع أحد أطبائه؟"',
            '',
            '# قواعد لا تُكسر',
            'لا تشخّص أمراض ولا تصف أدوية ولا تفسّر نتائج طبية.',
            'لا تقل "تم الحجز" أو "حجزت لك الموعد" إلا إذا كانت الرسالة تحتوي على "تم تأكيد الكود وحجز الموعد" من النظام — وهذا لا يحدث إلا بعد OTP صحيح.',
            'إذا كانت الرسالة تحتوي على "تعليمات إلزامية" → اتبعها حرفياً ولا تتجاوزها أبداً مهما بدا الحجز مكتملاً.',
            'البيانات من النظام (أطباء، مواعيد، أسعار) هي المرجع الوحيد.',
            'إذا لم تعرف، قل: "هذه المعلومة غير متوفرة لديّ، تواصل مع الاستقبال 1700200400."',
            'لا تستخدم كلمات آلية مثل: intent, JSON, draft.',
            'سؤال واحد فقط في كل رسالة — لا تكدّس أسئلة.',
        ]);
    }

    public function buildMessages(string $userMessage, string $draftReply, array $context): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
        ];

        // تاريخ المحادثة: كل الأدوار ما عدا آخر واحد (الرسالة الحالية للمستخدم)
        $allTurns = is_array($context['turns'] ?? null) ? $context['turns'] : [];
        $historyTurns = count($allTurns) > 1 ? array_slice($allTurns, 0, -1) : [];
        $historyTurns = array_slice($historyTurns, -8); // آخر 8 أدوار = 4 تبادلات

        foreach ($historyTurns as $turn) {
            $role = ($turn['role'] ?? '') === 'bot' ? 'assistant' : 'user';
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text !== '') {
                $messages[] = ['role' => $role, 'content' => $text];
            }
        }

        // ملاحظة النظام: البيانات المتاحة + الإجراء المقترح
        $systemNote = $this->buildSystemNote($draftReply, $context);
        if ($systemNote !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemNote];
        }

        // الرسالة الحالية
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }

    private const MANDATORY_BOOKING_STAGES = [
        'need_reason', 'need_national_id', 'need_patient_name',
        'need_patient_phone', 'need_email', 'need_otp',
    ];

    private function buildSystemNote(string $draftReply, array $context): string
    {
        $parts = [];

        $state   = $context['state'] ?? [];
        $booking = $state['booking'] ?? [];
        $stage   = (string) ($booking['stage'] ?? '');

        $isMandatoryStage = !empty($booking['active'])
            && in_array($stage, self::MANDATORY_BOOKING_STAGES, true);

        if ($draftReply !== '') {
            if ($isMandatoryStage) {
                $parts[] = "⚠️ تعليمات إلزامية من النظام — يجب اتباعها بالضبط ولا يجوز التجاوز أو تأكيد الحجز قبل استكمالها:\n{$draftReply}";
            } else {
                $parts[] = "الإجراء المقترح من النظام: {$draftReply}";
            }
        }

        if (!empty($booking['active'])) {
            $bf = [];
            if (!empty($booking['selected_doctor']['full_name'])) {
                $bf[] = 'الطبيب: ' . $booking['selected_doctor']['full_name'];
            }
            if (!empty($booking['selected_department']['name'])) {
                $bf[] = 'القسم: ' . $booking['selected_department']['name'];
            }
            if (!empty($booking['selected_date'])) {
                $bf[] = 'التاريخ: ' . $booking['selected_date'];
            }
            if (!empty($booking['selected_time'])) {
                $bf[] = 'الوقت: ' . $booking['selected_time'];
            }
            if ($stage !== '') {
                $bf[] = 'مرحلة: ' . $stage;
            }
            if (!empty($bf)) {
                $parts[] = 'حجز جارٍ — ' . implode(' | ', $bf);
            }
        }

        $tool = $context['tool_result'] ?? [];

        if (!empty($tool['slots']) && is_array($tool['slots'])) {
            $parts[] = 'أوقات متاحة: ' . implode('، ', array_slice($tool['slots'], 0, 6));
        }
        if (!empty($tool['doctors']) && is_array($tool['doctors'])) {
            $names = array_map(
                static fn(array $d): string => ($d['full_name'] ?? '') . ' (' . ($d['specialty'] ?? '') . ')',
                array_slice($tool['doctors'], 0, 5)
            );
            $parts[] = 'أطباء من النظام: ' . implode(' | ', $names);
        }
        if (!empty($tool['departments']) && is_array($tool['departments'])) {
            $names = array_map(static fn(array $d): string => ($d['name'] ?? ''), array_slice($tool['departments'], 0, 6));
            $parts[] = 'أقسام: ' . implode('، ', $names);
        }
        if (!empty($tool['services']) && is_array($tool['services'])) {
            $items = array_map(
                static fn(array $s): string => ($s['name'] ?? '') . ' — ' . number_format((float) ($s['base_cost'] ?? 0), 0) . ' ₪',
                array_slice($tool['services'], 0, 4)
            );
            $parts[] = 'خدمات: ' . implode(' | ', $items);
        }

        if (empty($parts)) {
            return '';
        }

        return "【بيانات النظام】\n"
            . implode("\n", $parts)
            . "\n【اكتب الرد النهائي للمريض فقط — بالعربية الطبيعية — بدون JSON أو شرح داخلي】";
    }
}
