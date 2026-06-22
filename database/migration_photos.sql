-- ============================================================
-- إضافة عمود صورة الطبيب
-- ============================================================

USE ahli_hospital;
SET NAMES utf8mb4;

ALTER TABLE Doctor ADD COLUMN IF NOT EXISTS photo_url VARCHAR(512) DEFAULT NULL;

-- تحديث صور الأطباء من موقع المستشفى الأهلي
UPDATE Doctor SET photo_url = 'https://ahli.org/images/باجس.webp'            WHERE full_name LIKE '%باجس%عمرو%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/محمد نصر.webp'        WHERE full_name LIKE '%محمد علي نصر%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/شريف.webp'             WHERE full_name LIKE '%شريف عيسى بصل%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/هاني حور.webp'         WHERE full_name LIKE '%هاني محمد حور%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/نضال الجبريني.webp'    WHERE full_name LIKE '%نضال%الجبريني%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/انس شاور.webp'         WHERE full_name LIKE '%أنس%شاور%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/بسام ناصر الدين.webp'  WHERE full_name LIKE '%بسام%ناصر الدين%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/عبد الناصر ابو ريان.webp' WHERE full_name LIKE '%عبد الناصر%أبو ريان%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/امجد مجاهد.webp'       WHERE full_name LIKE '%أمجد%مجاهد%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/امجد النتشة.webp'      WHERE full_name LIKE '%أمجد%النتشة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/باسل الغروز.webp'      WHERE full_name LIKE '%باسل%الغروز%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/بدوي.webp'             WHERE full_name LIKE '%بدوي%التميمي%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/بشر.webp'              WHERE full_name LIKE '%بشر%مرزوقة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/بلال الشوامرة.webp'    WHERE full_name LIKE '%بلال%الشوامرة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/جعافرة.webp'           WHERE full_name LIKE '%مراد%الجعافرة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/حازم الاشهب.webp'      WHERE full_name LIKE '%حازم%الاشهب%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/خضر حسونة.webp'        WHERE full_name LIKE '%خضر%حسونة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/رجائي.webp'            WHERE full_name LIKE '%رجائي%الحسيني%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/رشاد.webp'             WHERE full_name LIKE '%رشاد%الزرو%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/دبابسة.webp'           WHERE full_name LIKE '%إسماعيل%دبابسة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/سائد العطاونة.webp'    WHERE full_name LIKE '%سائد%العطاونة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/سعيد.webp'             WHERE full_name LIKE '%سعيد%اتكيدك%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/سفيان ابو عوض.webp'    WHERE full_name LIKE '%سفيان%أبو عوض%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/شريف ابو عوض.webp'     WHERE full_name LIKE '%شريف%أبو عوض%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/شهاب القواسمي.webp'    WHERE full_name LIKE '%شهاب%القواسمي%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/ضرار سميرات.webp'      WHERE full_name LIKE '%ضرار%سميرات%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/طارق.webp'             WHERE full_name LIKE '%طارق علي موسى%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/طاهر الشريفق.webp'     WHERE full_name LIKE '%طاهر%الشريف%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/عامر ابو رميلة.webp'   WHERE full_name LIKE '%عامر%أبو رميلة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/عبد الفتاح نوفل.webp'  WHERE full_name LIKE '%عبد الفتاح%نوفل%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/عبد الودود.webp'       WHERE full_name LIKE '%عبدالودود%أبوتركي%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/عز الدين قطيط.webp'    WHERE full_name LIKE '%عز الدين%اقطيط%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/عمرو ابو زينة.webp'    WHERE full_name LIKE '%عمرو%أبو زينة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/فاطمة الناظر.webp'     WHERE full_name LIKE '%فاطمة%الناظر%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/الجنيدي.webp'          WHERE full_name LIKE '%محمد الجنيدي%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/محمد العويوي.webp'     WHERE full_name LIKE '%محمد%العويوي%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/محمد الشريف.webp'      WHERE full_name LIKE '%محمد صلاح الشريف%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/ماجد.webp'             WHERE full_name LIKE '%محمد ماجد الدويك%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/محمود الهور.webp'      WHERE full_name LIKE '%محمود%الهور%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/مراد قباجة.webp'       WHERE full_name LIKE '%مراد%قباجة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/مطيع ابو عواد.webp'    WHERE full_name LIKE '%مطيع%أبو عواد%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/معتز الكركي.webp'      WHERE full_name LIKE '%معتز%الكركي%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/محمد علامة.webp'       WHERE full_name LIKE '%مهند%علامة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/نبيل عاشور.webp'       WHERE full_name LIKE '%نبيل%عاشور%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/هشام الدويك.webp'      WHERE full_name LIKE '%هشام%الدويك%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/ابراهيم الزعتري.webp'  WHERE full_name LIKE '%إبراهيم%الزعتري%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/احمد ابو عياش.webp'    WHERE full_name LIKE '%أحمد%أبو عياش%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/احمد العطاونة.webp'    WHERE full_name LIKE '%أحمد%عطاونة%';
UPDATE Doctor SET photo_url = 'https://ahli.org/images/احمد رزيقات.webp'      WHERE full_name LIKE '%أحمد%أرزيقات%';
