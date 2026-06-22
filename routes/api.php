<?php

require_once __DIR__ . '/../app/controllers/ChatController.php';
require_once __DIR__ . '/../app/controllers/StatsController.php';
require_once __DIR__ . '/../app/controllers/DepartmentController.php';
require_once __DIR__ . '/../app/controllers/DoctorController.php';
require_once __DIR__ . '/../app/controllers/ServiceController.php';
require_once __DIR__ . '/../app/controllers/AppointmentController.php';
require_once __DIR__ . '/../app/controllers/PatientController.php';
require_once __DIR__ . '/../app/controllers/LabTestController.php';
require_once __DIR__ . '/../app/controllers/InvoiceController.php';
require_once __DIR__ . '/../app/controllers/FeedbackController.php';
require_once __DIR__ . '/../app/controllers/InsuranceController.php';
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/ReminderController.php';
require_once __DIR__ . '/../app/controllers/DoctorAuthController.php';
require_once __DIR__ . '/../app/controllers/LabController.php';
require_once __DIR__ . '/../app/controllers/AdminController.php';

return [
    ['POST', '/api/chat',                       'ChatController',        'handle',          false],
    ['POST', '/api/auth/register',             'AuthController',        'register',        false],
    ['POST', '/api/auth/login',                'AuthController',        'login',           false],
    ['POST', '/api/auth/logout',               'AuthController',        'logout',          false],
    ['GET',  '/api/auth/me',                   'AuthController',        'me',              false],
    ['GET',  '/api/stats',                     'StatsController',       'index',           false],
    ['GET',  '/api/departments',               'DepartmentController',  'index',           false],
    ['GET',  '/api/departments/{id}',          'DepartmentController',  'show',            true],
    ['GET',  '/api/doctors',                   'DoctorController',      'index',           false],
    ['GET',  '/api/doctors/{id}',              'DoctorController',      'show',            true],
    ['GET',  '/api/services',                  'ServiceController',     'index',           false],
    ['GET',  '/api/services/{id}',             'ServiceController',     'show',            true],
    ['GET',  '/api/patients',                  'PatientController',     'index',           false],
    ['GET',  '/api/patients/{id}',             'PatientController',     'show',            true],
    ['GET',  '/api/appointments',              'AppointmentController', 'index',           false],
    ['GET',  '/api/appointments/available',    'AppointmentController', 'available',       false],
    ['POST', '/api/appointments',              'AppointmentController', 'store',           false],
    ['PUT',  '/api/appointments/{id}/cancel',  'AppointmentController', 'cancel',          true],
    ['PUT',  '/api/appointments/{id}/reschedule', 'AppointmentController', 'reschedule',   true],
    ['GET',  '/api/patients/{id}/lab-tests',   'LabTestController',     'indexByPatient',  true],
    ['GET',  '/api/patients/{id}/invoices',    'InvoiceController',     'indexByPatient',  true],
    ['GET',  '/api/patients/{id}/insurance',   'InsuranceController',   'indexByPatient',  true],
    ['GET',  '/api/patients/{id}/reminders',   'ReminderController',    'indexByPatient',  true],
    ['POST', '/api/feedback',                  'FeedbackController',    'store',           false],

    // Doctor Auth
    ['POST', '/api/doctor/login',             'DoctorAuthController',  'login',           false],
    ['POST', '/api/doctor/logout',            'DoctorAuthController',  'logout',          false],
    ['GET',  '/api/doctor/me',                'DoctorAuthController',  'me',              false],
    ['GET',  '/api/doctor/appointments',      'DoctorAuthController',  'myAppointments',  false],
    ['POST', '/api/doctor/change-password',   'DoctorAuthController',  'changePassword',  false],

    // Lab
    ['POST', '/api/lab/login',                'LabController',         'login',           false],
    ['POST', '/api/lab/logout',               'LabController',         'logout',          false],
    ['GET',  '/api/lab/me',                   'LabController',         'me',              false],
    ['GET',  '/api/lab/tests',                'LabController',         'listTests',       false],
    ['POST', '/api/lab/tests',                'LabController',         'addTest',         false],
    ['PUT',  '/api/lab/tests/{id}',           'LabController',         'updateResult',    true],

    // Admin Auth
    ['POST', '/api/admin/login',              'AdminController',       'login',           false],
    ['POST', '/api/admin/logout',             'AdminController',       'logout',          false],
    ['GET',  '/api/admin/me',                 'AdminController',       'me',              false],
    ['GET',  '/api/admin/stats',              'AdminController',       'stats',           false],
    ['GET',  '/api/admin/stats/detailed',     'AdminController',       'statsDetailed',   false],

    // Admin - Doctors
    ['GET',  '/api/admin/doctors',            'AdminController',       'listDoctors',     false],
    ['POST', '/api/admin/doctors',            'AdminController',       'createDoctor',    false],
    ['PUT',  '/api/admin/doctors/{id}',       'AdminController',       'updateDoctor',    true],
    ['DELETE','/api/admin/doctors/{id}',      'AdminController',       'deleteDoctor',    true],

    // Admin - Patients
    ['GET',  '/api/admin/patients',           'AdminController',       'listPatients',    false],
    ['POST', '/api/admin/patients',           'AdminController',       'createPatient',   false],
    ['PUT',  '/api/admin/patients/{id}',      'AdminController',       'updatePatient',   true],
    ['DELETE','/api/admin/patients/{id}',     'AdminController',       'deletePatient',   true],

    // Admin - Departments
    ['GET',  '/api/admin/departments',        'AdminController',       'listDepartments', false],
    ['POST', '/api/admin/departments',        'AdminController',       'createDepartment',false],
    ['PUT',  '/api/admin/departments/{id}',   'AdminController',       'updateDepartment',true],
    ['DELETE','/api/admin/departments/{id}',  'AdminController',       'deleteDepartment',true],

    // Admin - Appointments
    ['GET',  '/api/admin/appointments',       'AdminController',       'listAppointments',false],
    ['PUT',  '/api/admin/appointments/{id}',  'AdminController',       'updateAppointment',true],
    ['DELETE','/api/admin/appointments/{id}', 'AdminController',       'deleteAppointment',true],

    // Admin - Lab Tests
    ['GET',  '/api/admin/lab-tests',          'AdminController',       'listLabTests',    false],
    ['DELETE','/api/admin/lab-tests/{id}',    'AdminController',       'deleteLabTest',   true],

    // Admin - Invoices
    ['GET',  '/api/admin/invoices',           'AdminController',       'listInvoices',    false],
    ['PUT',  '/api/admin/invoices/{id}',      'AdminController',       'updateInvoice',   true],

    // Admin - Feedback
    ['GET',  '/api/admin/feedback',           'AdminController',       'listFeedback',    false],
    ['PUT',  '/api/admin/feedback/{id}',      'AdminController',       'updateFeedback',  true],

    // Admin - Lab Users
    ['GET',  '/api/admin/lab-users',          'AdminController',       'listLabUsers',    false],
    ['POST', '/api/admin/lab-users',          'AdminController',       'createLabUser',   false],
    ['PUT',  '/api/admin/lab-users/{id}',     'AdminController',       'updateLabUser',   true],

    // News (public)
    ['GET',  '/api/news',                     'AdminController',       'listPublishedNews', false],

    // Admin - News
    ['GET',  '/api/admin/news',               'AdminController',       'listNews',        false],
    ['POST', '/api/admin/news',               'AdminController',       'createNews',      false],
    ['PUT',  '/api/admin/news/{id}',          'AdminController',       'updateNews',      true],
    ['DELETE','/api/admin/news/{id}',         'AdminController',       'deleteNews',      true],
];
