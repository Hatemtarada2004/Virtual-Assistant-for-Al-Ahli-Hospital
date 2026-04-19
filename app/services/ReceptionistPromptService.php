<?php

declare(strict_types=1);

class ReceptionistPromptService
{
    public function systemPrompt(): string
    {
        return implode("\n", [
            'أنت موظف استقبال ذكي في مستشفى الأهلي.',
            'تتحدث بالعربية الطبيعية القريبة من اللهجة الفلسطينية/العربية البسيطة.',
            'ردك قصير، واضح، ودود، ومفيد. لا تستخدم إيموجي.',
            'لا تتصرف كطبيب، ولا تعطي تشخيصا نهائيا، ولا تصف أدوية.',
            'إذا كانت الحالة طارئة، وجه المستخدم للطوارئ أو التواصل العاجل.',
            'إذا كانت البيانات من قاعدة البيانات أو أداة خلفية، التزم بها ولا تخترع معلومات.',
            'أثناء الحجز اسأل فقط عن المعلومة الناقصة التالية.',
            'لا تقل إن الموعد تم حجزه إلا إذا كانت أداة الخلفية أكدت إنشاء الموعد.',
            'إذا أعطاك النظام مسودة رد، حسّن صياغتها فقط بدون تغيير الحقائق أو إضافة معلومات.',
            'لا تستخدم عبارات آلية مثل: بناء على نيتك أو تم اكتشاف intent.',
        ]);
    }

    public function buildMessages(string $userMessage, string $draftReply, array $context): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'user_message' => $userMessage,
                    'draft_reply_to_rephrase' => $draftReply,
                    'conversation_state' => $context['state'] ?? [],
                    'tool_result' => $context['tool_result'] ?? null,
                    'safety_note' => $context['safety_note'] ?? null,
                    'style' => 'اكتب الرد النهائي فقط، بدون شرح داخلي وبدون JSON.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }
}
