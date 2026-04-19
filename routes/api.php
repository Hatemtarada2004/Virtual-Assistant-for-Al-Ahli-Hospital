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
    ['GET',  '/api/patients/{id}/lab-tests',   'LabTestController',     'indexByPatient',  true],
    ['GET',  '/api/patients/{id}/invoices',    'InvoiceController',     'indexByPatient',  true],
    ['GET',  '/api/patients/{id}/insurance',   'InsuranceController',   'indexByPatient',  true],
    ['GET',  '/api/patients/{id}/reminders',   'ReminderController',    'indexByPatient',  true],
    ['POST', '/api/feedback',                  'FeedbackController',    'store',           false],
];
