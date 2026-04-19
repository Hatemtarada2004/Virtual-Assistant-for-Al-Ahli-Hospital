-- ============================================================
-- Virtual Assistant for Al-Ahli Hospital - Seed Data
-- Document-aligned sample data for the application flows
-- ============================================================

USE ahli_hospital;
SET NAMES utf8mb4;

-- Use DELETE instead of TRUNCATE because XAMPP/phpMyAdmin + foreign keys
-- can reject TRUNCATE even when FOREIGN_KEY_CHECKS is disabled.
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM AppointmentService;
DELETE FROM Reminder;
DELETE FROM Feedback;
DELETE FROM InvoiceItem;
DELETE FROM Invoice;
DELETE FROM LabTest;
DELETE FROM InsurancePolicy;
DELETE FROM InsuranceProvider;
DELETE FROM Appointment;
DELETE FROM Service;
DELETE FROM Patient;
DELETE FROM Doctor;
DELETE FROM Department;
DELETE FROM AdminUser;

ALTER TABLE Reminder AUTO_INCREMENT = 1;
ALTER TABLE Feedback AUTO_INCREMENT = 1;
ALTER TABLE InvoiceItem AUTO_INCREMENT = 1;
ALTER TABLE Invoice AUTO_INCREMENT = 1;
ALTER TABLE LabTest AUTO_INCREMENT = 1;
ALTER TABLE InsurancePolicy AUTO_INCREMENT = 1;
ALTER TABLE InsuranceProvider AUTO_INCREMENT = 1;
ALTER TABLE Appointment AUTO_INCREMENT = 1;
ALTER TABLE Service AUTO_INCREMENT = 1;
ALTER TABLE Patient AUTO_INCREMENT = 1;
ALTER TABLE Doctor AUTO_INCREMENT = 1;
ALTER TABLE Department AUTO_INCREMENT = 1;
ALTER TABLE AdminUser AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO AdminUser (username, password_hash, role, last_login) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', NULL),
('support', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'support', NULL);

INSERT INTO Department (name, location, working_hours) VALUES
('مركز الأهلي للقلب والشرايين', 'الطابق الأول', '08:00 - 20:00'),
('قسم الأطفال وحديثي الولادة', 'الطابق الثاني', '08:00 - 20:00'),
('قسم الأشعة', 'الطابق الأرضي', '08:00 - 20:00'),
('قسم الجهاز الهضمي والتنظير', 'الطابق الأول', '08:00 - 17:00'),
('الأقسام الجراحية', 'الطابق الثالث', '24/7'),
('قسم الأمراض الباطنية', 'الطابق الثاني', '08:00 - 20:00'),
('قسم النسائية والتوليد', 'الطابق الرابع', '24/7'),
('قسم الإسعاف والطوارئ', 'الطابق الأرضي', '24/7');

INSERT INTO Doctor (full_name, specialty, phone, email, department_id) VALUES
('د. أنس يحيى شاور', 'القلب والقسطرة', '0599000001', 'anas@ahli.org', 1),
('د. بشر مرزوق مرزوقة', 'جراحة القلب', '0599000002', 'bishr@ahli.org', 1),
('د. باسل أمين الغروز', 'طب أطفال', '0599000003', 'basel@ahli.org', 2),
('د. شهاب وليد القواسمي', 'الأطفال وحديثي الولادة', '0599000004', 'shehab@ahli.org', 2),
('د. إبراهيم الزعتري', 'الأشعة التشخيصية', '0599000005', 'ibrahim@ahli.org', 3),
('د. أحمد أكرم أبو عياش', 'الجهاز الهضمي والكبد', '0599000006', 'ahmed.abu@ahli.org', 4),
('د. عامر أبو رميلة', 'الجراحة العامة', '0599000007', 'amer@ahli.org', 5),
('د. أمجد ناصر النتشة', 'الأمراض الباطنية', '0599000008', 'amjad@ahli.org', 6),
('د. بسام غالب ناصر الدين', 'النسائية والتوليد', '0599000009', 'bassam@ahli.org', 7),
('د. عبد الناصر محمد أبوريان', 'النسائية والتوليد', '0599000010', 'abdelnasser@ahli.org', 7);

INSERT INTO Patient (full_name, national_id, phone, email, date_of_birth, gender, created_at) VALUES
('محمد خالد الجعبري', '401234567', '0597000001', 'm.jaabari@example.com', '1991-05-14', 'Male', '2026-03-15 09:10:00'),
('آية سامر القواسمي', '402345678', '0597000002', 'aya.qawasmi@example.com', '1996-11-03', 'Female', '2026-03-18 11:25:00'),
('ليان فادي النتشة', '403456789', '0597000003', 'layan.netsha@example.com', '2001-07-21', 'Female', '2026-03-19 15:40:00'),
('أحمد يوسف التميمي', '404567890', '0597000004', 'ahmad.tamimi@example.com', '1988-01-30', 'Male', '2026-03-20 08:15:00'),
('مريم نايف الشوامرة', '405678901', '0597000005', 'mariam.shawareh@example.com', '1993-09-12', 'Female', '2026-03-23 10:55:00');

INSERT INTO Service (name, description, base_cost, department_id) VALUES
('قسطرة قلبية', 'فحص تشخيصي وعلاجي لأمراض الشرايين التاجية', 2500.00, 1),
('فحص إيكو للقلب', 'تصوير صدى القلب لتقييم وظيفة القلب والصمامات', 300.00, 1),
('فحص أطفال عام', 'زيارة تقييم عام للأطفال مع متابعة النمو', 120.00, 2),
('أشعة سينية', 'تصوير أشعة بسيط للأجزاء المختلفة من الجسم', 80.00, 3),
('أشعة مقطعية CT', 'تصوير مقطعي تشخيصي', 800.00, 3),
('تنظير الجهاز الهضمي', 'تنظير علوي أو سفلي حسب الحالة', 1200.00, 4),
('استشارة باطنية', 'زيارة تقييم وتشخيص في العيادة الباطنية', 150.00, 6),
('ولادة طبيعية', 'متابعة وإجراء الولادة الطبيعية', 2000.00, 7),
('ولادة قيصرية', 'إجراء ولادة قيصرية مع الرعاية اللازمة', 4000.00, 7),
('عملية جراحة عامة', 'إجراء جراحي ضمن الأقسام الجراحية', 5000.00, 5);

INSERT INTO Appointment (patient_id, doctor_id, department_id, appointment_datetime, status, reason, created_at) VALUES
(1, 1, 1, '2026-04-12 09:00:00', 'Booked', 'متابعة ألم صدر متكرر', '2026-04-08 10:00:00'),
(2, 3, 2, '2026-04-12 10:30:00', 'Booked', 'فحص روتيني للطفل', '2026-04-08 10:15:00'),
(3, 8, 6, '2026-04-05 11:00:00', 'Completed', 'متابعة ضغط وسكر', '2026-03-31 09:20:00'),
(4, 6, 4, '2026-04-07 13:00:00', 'Completed', 'ألم معدة مزمن', '2026-04-01 12:05:00'),
(5, 9, 7, '2026-04-14 12:00:00', 'Booked', 'متابعة حمل', '2026-04-09 08:35:00'),
(1, 7, 5, '2026-04-15 14:00:00', 'Cancelled', 'استشارة جراحة عامة', '2026-04-02 16:00:00'),
(2, 5, 3, '2026-04-16 09:30:00', 'NoShow', 'مراجعة أشعة سابقة', '2026-04-03 14:45:00');

INSERT INTO AppointmentService (appointment_id, service_id, notes) VALUES
(1, 1, 'حجز خدمة قسطرة تشخيصية مبدئية'),
(1, 2, 'إيكو قبل المعاينة'),
(2, 3, 'فحص أطفال مع متابعة تطعيمات'),
(3, 7, 'استشارة باطنية دورية'),
(4, 6, 'تنظير تشخيصي'),
(5, 8, 'متابعة ما قبل الولادة');

INSERT INTO LabTest (patient_id, ordered_by_doctor_id, test_name, test_date, result_text, status) VALUES
(1, 1, 'Troponin Test', '2026-04-08', 'القيم ضمن الحدود الطبيعية', 'Ready'),
(2, 3, 'CBC', '2026-04-07', 'الهيموغلوبين طبيعي، لا توجد مؤشرات التهاب', 'Ready'),
(3, 8, 'HbA1c', '2026-04-06', '7.1% مع توصية بمتابعة غذائية', 'Ready'),
(4, 6, 'H. pylori Antigen', '2026-04-09', NULL, 'Pending'),
(5, 9, 'Pregnancy Profile', '2026-04-09', NULL, 'Pending');

INSERT INTO Invoice (patient_id, issue_date, total_amount, status) VALUES
(1, '2026-04-08', 2800.00, 'Unpaid'),
(2, '2026-04-07', 120.00, 'Paid'),
(3, '2026-04-06', 150.00, 'PartiallyPaid'),
(4, '2026-04-07', 1200.00, 'Paid'),
(5, '2026-04-09', 2000.00, 'Unpaid');

INSERT INTO InvoiceItem (invoice_id, service_id, qty, unit_price, line_total) VALUES
(1, 1, 1, 2500.00, 2500.00),
(1, 2, 1, 300.00, 300.00),
(2, 3, 1, 120.00, 120.00),
(3, 7, 1, 150.00, 150.00),
(4, 6, 1, 1200.00, 1200.00),
(5, 8, 1, 2000.00, 2000.00);

INSERT INTO Feedback (patient_id, type, message, status, created_at) VALUES
(1, 'Feedback', 'تجربة الحجز عبر الموقع كانت واضحة وسريعة.', 'Closed', '2026-04-08 12:00:00'),
(2, 'Complaint', 'تأخر بسيط في موعد الأشعة وأتمنى تحسين الالتزام بالمواعيد.', 'InReview', '2026-04-08 13:30:00'),
(NULL, 'Feedback', 'واجهة الدردشة مفيدة للزوار الجدد.', 'New', '2026-04-09 09:45:00');

INSERT INTO Reminder (patient_id, reminder_type, message, remind_at, sent_flag) VALUES
(1, 'Appointment', 'تذكير: لديك موعد مع د. أنس يحيى شاور بتاريخ 2026-04-12 الساعة 09:00.', '2026-04-11 18:00:00', 0),
(2, 'Appointment', 'تذكير: موعد فحص الأطفال غدًا الساعة 10:30.', '2026-04-11 19:00:00', 0),
(3, 'Medication', 'تذكير: يرجى تناول العلاج المسائي بعد العشاء.', '2026-04-09 20:00:00', 1),
(5, 'Appointment', 'تذكير: متابعة الحمل يوم 2026-04-14 الساعة 12:00.', '2026-04-13 18:30:00', 0);

INSERT INTO InsuranceProvider (name, phone) VALUES
('القدس للتأمين الصحي', '022229999'),
('المستقبل للتأمين', '022228888'),
('الوطنية للرعاية الصحية', '022227777');

INSERT INTO InsurancePolicy (patient_id, provider_id, policy_number, coverage_details, valid_from, valid_to) VALUES
(1, 1, 'QUDS-2026-001', 'يغطي الاستشارات وأغلب الفحوصات القلبية بنسبة 80%', '2026-01-01', '2026-12-31'),
(2, 2, 'MUSTAQBAL-2026-041', 'يغطي زيارات الأطفال والفحوصات الأساسية بنسبة 90%', '2026-02-01', '2026-11-30'),
(5, 3, 'WATANIA-2026-113', 'يغطي خدمات النسائية والولادة الطبيعية بنسبة 70%', '2026-03-01', '2026-12-31');

INSERT INTO Department (name, location, working_hours) VALUES
('قسم العيون', 'الطابق الثالث', '08:00 - 18:00');

INSERT INTO Doctor (full_name, specialty, phone, email, department_id) VALUES
('د. يوسف الخطيب', 'طب وجراحة العيون', '0599000011', 'yousef.eye@ahli.org', 9);

INSERT INTO Service (name, description, base_cost, department_id) VALUES
('فحص عيون', 'فحص نظر وتشخيص أولي لأمراض العيون', 140.00, 9);

SELECT 'Departments' AS table_name, COUNT(*) AS total_rows FROM Department
UNION ALL
SELECT 'Doctors', COUNT(*) FROM Doctor
UNION ALL
SELECT 'Patients', COUNT(*) FROM Patient
UNION ALL
SELECT 'Services', COUNT(*) FROM Service
UNION ALL
SELECT 'Appointments', COUNT(*) FROM Appointment
UNION ALL
SELECT 'LabTests', COUNT(*) FROM LabTest
UNION ALL
SELECT 'Invoices', COUNT(*) FROM Invoice
UNION ALL
SELECT 'Feedback', COUNT(*) FROM Feedback
UNION ALL
SELECT 'Reminders', COUNT(*) FROM Reminder
UNION ALL
SELECT 'InsurancePolicies', COUNT(*) FROM InsurancePolicy;
