#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
generate_large_knowledge_base.py

Generates a large synthetic Arabic Knowledge Base for a hospital chatbot RAG system.

Default target: 5 GB raw Markdown text.

Recommended usage:
    python scripts/generate_large_knowledge_base.py --target-gb 0.5
    python scripts/generate_large_knowledge_base.py --target-gb 1
    python scripts/generate_large_knowledge_base.py --target-gb 5

Output:
    data/knowledge_large_5gb/

Important:
- This is synthetic/demo data.
- It is NOT real hospital information.
- It is NOT medical advice.
- For urgent symptoms, users must be directed to Emergency.
"""

import argparse
import os
import random
import time
from pathlib import Path
from typing import List


DEPARTMENTS = [
    ("emergency", "Emergency", "قسم الطوارئ"),
    ("cardiology", "Cardiology", "قسم القلب"),
    ("pediatrics", "Pediatrics", "قسم الأطفال"),
    ("obgyn", "Obstetrics & Gynecology", "قسم النسائية والتوليد"),
    ("orthopedics", "Orthopedics", "قسم العظام"),
    ("dermatology", "Dermatology", "قسم الجلدية"),
    ("radiology", "Radiology", "قسم الأشعة"),
    ("laboratory", "Laboratory", "قسم المختبر"),
    ("icu", "ICU", "قسم العناية المكثفة"),
    ("surgery", "General Surgery", "قسم الجراحة العامة"),
    ("urology", "Urology", "قسم المسالك البولية"),
    ("neurology", "Neurology", "قسم الأعصاب"),
    ("gastroenterology", "Gastroenterology", "قسم الجهاز الهضمي والمناظير"),
    ("pharmacy", "Pharmacy", "الصيدلية"),
    ("dental", "Dental", "قسم الأسنان"),
]

SYMPTOMS = [
    "ألم صدر", "ضيق نفس", "حرارة عالية", "دوخة", "إغماء", "نزيف", "كسر", "ألم بطن",
    "قيء مستمر", "إسهال", "صداع شديد", "ألم ظهر", "ألم مفاصل", "طفح جلدي",
    "حكة", "سعال", "ألم أذن", "ألم أسنان", "تعب عام", "ارتفاع ضغط",
    "هبوط سكر", "ألم حمل", "مغص كلوي", "جرح", "حرق بسيط", "تورم قدم",
    "خفقان", "ألم كتف", "ألم ركبة", "صعوبة تبول",
]

SERVICES = [
    "CBC", "Blood Sugar Test", "Liver Function Test", "Kidney Function Test", "Urine Test",
    "X-Ray", "CT Scan", "Ultrasound", "MRI", "ECG", "Cardiac Catheterization",
    "Pregnancy Follow-up", "Normal Delivery", "C-Section", "Wound Dressing",
    "Cast Application", "Nebulizer Session", "Physical Therapy", "Dental Checkup",
    "Dermatology Consultation", "Pediatric Consultation", "Internal Medicine Consultation",
]

AGE_GROUPS = [
    "طفل عمره سنتين", "طفل عمره 7 سنوات", "شاب عمره 22 سنة", "امرأة عمرها 30 سنة",
    "حامل في الشهر السابع", "رجل عمره 45 سنة", "سيدة عمرها 55 سنة", "مريض كبير سن عمره 70 سنة",
]

URGENCY_LEVELS = [
    ("emergency_now", "طارئة جدًا", "التوجه فورًا إلى Emergency / قسم الطوارئ"),
    ("urgent", "مستعجلة", "مراجعة الطوارئ أو أقرب طبيب حسب شدة الأعراض"),
    ("same_day", "يفضل اليوم", "حجز موعد في نفس اليوم أو مراجعة العيادات"),
    ("routine", "روتينية", "حجز موعد عادي في العيادات الخارجية"),
]

INSURANCES = [
    "GlobeMed", "Palestine Insurance", "Smart Health", "التكافل الفلسطينية",
    "الوطنية للتأمين", "ترست العالمية", "بدون تأمين", "تأمين خاص غير محدد",
]

LOCATIONS = [
    "المدخل الرئيسي", "الطابق الأرضي", "الطابق الأول", "الطابق الثاني",
    "الطابق الثالث", "قرب الاستقبال", "قرب الطوارئ", "منطقة العيادات الخارجية",
]

DOCTOR_NAMES = [
    "د. أحمد الخليل", "د. محمد الحشلمون", "د. أسامة البكري", "د. بانا أبو رجب",
    "د. بسام ناصر الدين", "د. ماجد دويك", "د. طاهر الشريف", "د. شهاب قواسمة",
    "د. رنا الجعبري", "د. ليلى النتشة", "د. محمود القواسمي", "د. ياسر أبو سنينة",
]


def kb_size_bytes(path: Path) -> int:
    total = 0
    if not path.exists():
        return 0
    for root, _, files in os.walk(path):
        for name in files:
            fp = Path(root) / name
            try:
                total += fp.stat().st_size
            except OSError:
                pass
    return total


def make_header(record_id: int, category: str, tags: List[str]) -> str:
    return (
        f"\n\n---\n"
        f"id: synthetic_kb_{record_id:012d}\n"
        f"category: {category}\n"
        f"language: ar\n"
        f"source: synthetic_demo_knowledge_base\n"
        f"reality_status: fictional_demo_data\n"
        f"tags: {', '.join(tags)}\n"
        f"disclaimer: هذه بيانات افتراضية للتجربة وليست معلومات طبية نهائية.\n"
        f"---\n\n"
    )


def scenario_record(record_id: int) -> str:
    dep_key, dep_en, dep_ar = random.choice(DEPARTMENTS)
    symptom = random.choice(SYMPTOMS)
    age = random.choice(AGE_GROUPS)
    urgency_key, urgency_ar, urgency_action = random.choice(URGENCY_LEVELS)
    insurance = random.choice(INSURANCES)
    location = random.choice(LOCATIONS)
    service = random.choice(SERVICES)
    doctor = random.choice(DOCTOR_NAMES)

    questions = [
        f"عندي {symptom}، وين أروح؟",
        f"هل حالة {symptom} تحتاج طوارئ؟",
        f"بدي أحجز عند {dep_ar} بسبب {symptom}.",
        f"هل {service} متوفر؟",
        f"معي {insurance}، هل ممكن أراجع؟",
        f"وين مكان {dep_ar}؟",
    ]

    return make_header(record_id, "patient_scenario", [dep_key, dep_en, symptom, urgency_key]) + f"""
# سيناريو مريض افتراضي رقم {record_id}

## نوع السيناريو
Patient Scenario / سيناريو مريض

## الحالة
المريض: {age}
العرض الرئيسي: {symptom}
القسم المقترح: {dep_en} / {dep_ar}
الخدمة المرتبطة المحتملة: {service}
درجة الاستعجال: {urgency_ar}
التأمين المذكور: {insurance}
الموقع المتوقع داخل المستشفى: {location}
الطبيب الافتراضي المناسب: {doctor}

## أسئلة ممكن يسألها المستخدم
- {questions[0]}
- {questions[1]}
- {questions[2]}
- {questions[3]}
- {questions[4]}
- {questions[5]}

## إجابة شات بوت مقترحة
حسب الأعراض المذكورة، قد تكون المراجعة المناسبة في {dep_en} / {dep_ar}. 
إذا كان العرض شديدًا أو مفاجئًا أو معه علامات خطورة مثل فقدان وعي، ألم صدر قوي، ضيق نفس، نزيف، تشنجات، أو تدهور سريع، يجب التوجه فورًا إلى Emergency / قسم الطوارئ.

الإجراء المقترح: {urgency_action}.
يمكن للمراجع سؤال الاستقبال عن توفر {service} أو عن أقرب موعد مع {doctor} أو أي طبيب متاح في نفس التخصص.

## رد مختصر جدًا
لأعراض مثل {symptom}، القسم الأقرب هو {dep_ar}. إذا الحالة شديدة أو طارئة، توجه للطوارئ فورًا.

## تنبيه طبي
الشات بوت لا يعطي تشخيصًا نهائيًا ولا يصف علاجًا. في الحالات العاجلة يجب مراجعة Emergency / قسم الطوارئ أو الاتصال بالمستشفى.
"""


def faq_record(record_id: int) -> str:
    dep_key, dep_en, dep_ar = random.choice(DEPARTMENTS)
    service = random.choice(SERVICES)
    insurance = random.choice(INSURANCES)
    location = random.choice(LOCATIONS)
    topic = random.choice(["المواعيد", "الزيارة", "التأمين", "الخدمات", "الموقع", "الأسعار", "الطوارئ", "الفحوصات"])

    return make_header(record_id, "faq", [dep_key, dep_en, topic, service]) + f"""
# سؤال شائع افتراضي رقم {record_id}

## الموضوع
{topic}

## السؤال
هل أقدر أراجع {dep_ar} بخصوص خدمة {service} ومعي {insurance}؟

## الجواب
يمكنك مراجعة {dep_en} / {dep_ar} حسب توفر العيادة والطبيب. 
يفضل التأكد من الاستقبال أو قسم المواعيد قبل الحضور، خصوصًا إذا كانت الخدمة تحتاج حجزًا مسبقًا أو تحضيرًا.
بالنسبة للتأمين {insurance}، قد تختلف التغطية حسب نوع الخدمة والطبيب والاتفاقية، لذلك يجب التأكد من قسم التأمين أو الحسابات.

## موقع القسم
غالبًا يتم توجيه المراجع من الاستقبال إلى {location} أو إلى العيادات الخارجية حسب التخصص.

## صيغة إجابة قصيرة
نعم، ممكن تراجع {dep_ar}، لكن يفضل التأكد من الموعد والتأمين قبل الحضور.
"""


def service_record(record_id: int) -> str:
    service = random.choice(SERVICES)
    dep_key, dep_en, dep_ar = random.choice(DEPARTMENTS)
    prep = random.choice([
        "قد لا يحتاج تحضير خاص.",
        "قد يحتاج صيام حسب نوع الفحص وتعليمات الطبيب.",
        "يفضل إحضار الهوية وأي تقارير سابقة.",
        "يفضل الحضور قبل الموعد بوقت كافٍ للتسجيل.",
        "قد يحتاج تحويلة من الطبيب أو موافقة تأمين.",
    ])

    return make_header(record_id, "service", [dep_key, dep_en, service]) + f"""
# خدمة طبية افتراضية: {service} - سجل {record_id}

## وصف الخدمة
خدمة {service} هي خدمة طبية أو تشخيصية افتراضية ضمن قاعدة المعرفة التجريبية، وقد ترتبط بقسم {dep_en} / {dep_ar}.

## متى يحتاجها المريض؟
قد يحتاج المريض هذه الخدمة عندما يطلب الطبيب فحصًا أو متابعة أو تقييمًا لحالة معينة.

## التحضير قبل الخدمة
{prep}

## خطوات المراجع
1. التوجه إلى الاستقبال أو قسم المواعيد.
2. التأكد من وجود طلب طبي أو حجز إذا كان مطلوبًا.
3. مراجعة قسم {dep_ar}.
4. إجراء الخدمة أو الفحص.
5. العودة للطبيب بالنتيجة إذا لزم.

## أسئلة شائعة
س: هل خدمة {service} تحتاج موعد؟
ج: قد تحتاج موعدًا حسب توفر القسم وطبيعة الخدمة.

س: هل يغطي التأمين هذه الخدمة؟
ج: يعتمد ذلك على شركة التأمين ونوع التغطية.

## تنبيه
هذه معلومات افتراضية للتجربة ولا تغني عن تعليمات الطبيب أو المستشفى.
"""


def doctor_record(record_id: int) -> str:
    doctor = random.choice(DOCTOR_NAMES)
    dep_key, dep_en, dep_ar = random.choice(DEPARTMENTS)
    days = random.sample(["السبت", "الأحد", "الاثنين", "الثلاثاء", "الأربعاء", "الخميس"], k=3)
    shift = random.choice(["8:00 صباحًا - 12:00 ظهرًا", "9:00 صباحًا - 2:00 عصرًا", "4:00 مساءً - 8:00 مساءً"])

    return make_header(record_id, "doctor_profile", [dep_key, dep_en, doctor]) + f"""
# ملف طبيب افتراضي رقم {record_id}

## الاسم
{doctor}

## التخصص
{dep_en} / {dep_ar}

## أيام الدوام الافتراضية
{', '.join(days)}

## وقت الدوام الافتراضي
{shift}

## نوع الحالات
يتعامل الطبيب مع مراجعات مرتبطة بتخصص {dep_ar}، مثل الفحوصات الأولية، المتابعة، مراجعات ما بعد العلاج، والتحويلات من أقسام أخرى.

## أسئلة المستخدم المتوقعة
- متى دوام {doctor}؟
- هل {doctor} متخصص في {dep_ar}؟
- كيف أحجز موعد مع {doctor}؟
- هل يوجد دوام مسائي عند {doctor}؟

## إجابة شات بوت مقترحة
يمكنك طلب حجز موعد مع {doctor} في تخصص {dep_ar}. 
الدوام المذكور هنا افتراضي للتجربة، ويجب تأكيد الموعد من نظام المواعيد أو الاستقبال.
"""


def instruction_record(record_id: int) -> str:
    service = random.choice(SERVICES)
    dep_key, dep_en, dep_ar = random.choice(DEPARTMENTS)

    instructions = [
        "أحضر بطاقة الهوية أو رقم الملف إن وجد.",
        "أحضر أي تقارير أو صور أو تحاليل سابقة.",
        "اسأل الاستقبال إذا كان الفحص يحتاج دفعًا مسبقًا أو موافقة تأمين.",
        "لا توقف أي دواء إلا بتعليمات الطبيب.",
        "في حال ظهور أعراض شديدة أو مفاجئة توجه إلى Emergency / الطوارئ.",
    ]

    return make_header(record_id, "patient_instruction", [dep_key, dep_en, service]) + f"""
# تعليمات مريض افتراضية رقم {record_id}

## الخدمة أو القسم
{service} - {dep_en} / {dep_ar}

## قبل الحضور
- {instructions[0]}
- {instructions[1]}
- {instructions[2]}

## ملاحظات مهمة
- {instructions[3]}
- {instructions[4]}

## سؤال شائع
س: ماذا أحضر معي قبل مراجعة {dep_ar}؟
ج: أحضر الهوية، التقارير السابقة، وأي طلب طبي أو تحويلة أو معلومات تأمين متوفرة.

## تنبيه
هذه تعليمات عامة افتراضية وليست بديلًا عن تعليمات الطبيب أو القسم المختص.
"""


GENERATORS = [
    scenario_record,
    faq_record,
    service_record,
    doctor_record,
    instruction_record,
]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--target-gb", type=float, default=5.0, help="Target raw KB size in GB. Default: 5")
    parser.add_argument("--output", type=str, default="data/knowledge_large_5gb", help="Output directory")
    parser.add_argument("--records-per-file", type=int, default=500, help="How many records per Markdown shard")
    parser.add_argument("--seed", type=int, default=42, help="Random seed")
    args = parser.parse_args()

    random.seed(args.seed)
    out = Path(args.output)
    out.mkdir(parents=True, exist_ok=True)

    target_bytes = int(args.target_gb * 1024 * 1024 * 1024)
    current_size = kb_size_bytes(out)

    print(f"Output directory: {out}")
    print(f"Current size: {current_size / (1024**3):.3f} GB")
    print(f"Target size:  {args.target_gb:.3f} GB")
    print("Generating synthetic Knowledge Base... Press Ctrl+C to stop safely.")

    record_id = 1
    shard_id = 1

    # Continue numbering if directory already has shards.
    existing_shards = sorted(out.glob("kb_shard_*.md"))
    if existing_shards:
        shard_id = len(existing_shards) + 1
        record_id = shard_id * args.records_per_file

    start = time.time()

    try:
        while current_size < target_bytes:
            shard_path = out / f"kb_shard_{shard_id:06d}.md"
            with shard_path.open("w", encoding="utf-8") as f:
                f.write(f"# Synthetic Hospital Knowledge Base Shard {shard_id}\n\n")
                f.write("هذه بيانات افتراضية كبيرة لتجربة RAG في شات بوت مستشفى.\n")
                f.write("ليست معلومات طبية نهائية وليست بيانات حقيقية للمرضى.\n\n")

                for _ in range(args.records_per_file):
                    gen = random.choice(GENERATORS)
                    f.write(gen(record_id))
                    record_id += 1

            current_size = kb_size_bytes(out)
            elapsed = max(time.time() - start, 1)
            speed_mb_s = (current_size / (1024**2)) / elapsed
            print(
                f"Shard {shard_id:06d} created | "
                f"Size: {current_size / (1024**3):.3f} GB / {args.target_gb:.3f} GB | "
                f"Speed: {speed_mb_s:.2f} MB/s"
            )
            shard_id += 1

        print("Done.")
        print(f"Final size: {current_size / (1024**3):.3f} GB")
        print(f"Files created in: {out}")

    except KeyboardInterrupt:
        print("\nStopped by user.")
        print(f"Current size: {current_size / (1024**3):.3f} GB")
        print(f"Files are saved in: {out}")


if __name__ == "__main__":
    main()
