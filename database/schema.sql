-- ============================================================
-- Virtual Assistant for Al-Ahli Hospital - Document-Aligned Schema
-- Database: ahli_hospital
-- ============================================================

DROP DATABASE IF EXISTS ahli_hospital;
CREATE DATABASE ahli_hospital
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ahli_hospital;

CREATE TABLE AdminUser (
    admin_id      INT NOT NULL AUTO_INCREMENT,
    username      VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('super_admin', 'admin', 'support') NOT NULL DEFAULT 'admin',
    last_login    DATETIME NULL,
    PRIMARY KEY (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Department (
    department_id INT NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    location      VARCHAR(255) NULL,
    working_hours VARCHAR(100) NULL,
    PRIMARY KEY (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Doctor (
    doctor_id      INT NOT NULL AUTO_INCREMENT,
    full_name      VARCHAR(150) NOT NULL,
    specialty      VARCHAR(150) NOT NULL,
    phone          VARCHAR(30) NULL,
    email          VARCHAR(150) NULL,
    department_id  INT NOT NULL,
    PRIMARY KEY (doctor_id),
    CONSTRAINT fk_doctor_department
        FOREIGN KEY (department_id) REFERENCES Department(department_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_doctor_department ON Doctor(department_id);
CREATE INDEX idx_doctor_specialty ON Doctor(specialty);

CREATE TABLE Patient (
    patient_id     INT NOT NULL AUTO_INCREMENT,
    full_name      VARCHAR(150) NOT NULL,
    national_id    VARCHAR(30) NULL UNIQUE,
    phone          VARCHAR(30) NOT NULL UNIQUE,
    email          VARCHAR(150) NULL,
    date_of_birth  DATE NULL,
    gender         ENUM('Male', 'Female') NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_patient_name ON Patient(full_name);

CREATE TABLE Service (
    service_id     INT NOT NULL AUTO_INCREMENT,
    name           VARCHAR(150) NOT NULL,
    description    TEXT NULL,
    base_cost      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    department_id  INT NOT NULL,
    PRIMARY KEY (service_id),
    CONSTRAINT fk_service_department
        FOREIGN KEY (department_id) REFERENCES Department(department_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_service_department ON Service(department_id);

CREATE TABLE Appointment (
    appointment_id        INT NOT NULL AUTO_INCREMENT,
    patient_id            INT NOT NULL,
    doctor_id             INT NOT NULL,
    department_id         INT NOT NULL,
    appointment_datetime  DATETIME NOT NULL,
    status                ENUM('Booked', 'Cancelled', 'Completed', 'NoShow') NOT NULL DEFAULT 'Booked',
    reason                TEXT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (appointment_id),
    CONSTRAINT fk_appointment_patient
        FOREIGN KEY (patient_id) REFERENCES Patient(patient_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_appointment_doctor
        FOREIGN KEY (doctor_id) REFERENCES Doctor(doctor_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_appointment_department
        FOREIGN KEY (department_id) REFERENCES Department(department_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_appointment_patient ON Appointment(patient_id);
CREATE INDEX idx_appointment_doctor ON Appointment(doctor_id);
CREATE INDEX idx_appointment_status ON Appointment(status);
CREATE INDEX idx_appointment_datetime ON Appointment(appointment_datetime);

CREATE TABLE AppointmentService (
    appointment_id INT NOT NULL,
    service_id     INT NOT NULL,
    notes          TEXT NULL,
    PRIMARY KEY (appointment_id, service_id),
    CONSTRAINT fk_appointmentservice_appointment
        FOREIGN KEY (appointment_id) REFERENCES Appointment(appointment_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_appointmentservice_service
        FOREIGN KEY (service_id) REFERENCES Service(service_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE LabTest (
    lab_test_id             INT NOT NULL AUTO_INCREMENT,
    patient_id              INT NOT NULL,
    ordered_by_doctor_id    INT NULL,
    test_name               VARCHAR(150) NOT NULL,
    test_date               DATE NOT NULL,
    result_text             TEXT NULL,
    status                  ENUM('Pending', 'Ready') NOT NULL DEFAULT 'Pending',
    PRIMARY KEY (lab_test_id),
    CONSTRAINT fk_labtest_patient
        FOREIGN KEY (patient_id) REFERENCES Patient(patient_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_labtest_doctor
        FOREIGN KEY (ordered_by_doctor_id) REFERENCES Doctor(doctor_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_labtest_patient ON LabTest(patient_id);
CREATE INDEX idx_labtest_status ON LabTest(status);

CREATE TABLE Invoice (
    invoice_id      INT NOT NULL AUTO_INCREMENT,
    patient_id      INT NOT NULL,
    issue_date      DATE NOT NULL,
    total_amount    DECIMAL(10,2) NOT NULL,
    status          ENUM('Unpaid', 'Paid', 'PartiallyPaid') NOT NULL DEFAULT 'Unpaid',
    PRIMARY KEY (invoice_id),
    CONSTRAINT fk_invoice_patient
        FOREIGN KEY (patient_id) REFERENCES Patient(patient_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_invoice_patient ON Invoice(patient_id);
CREATE INDEX idx_invoice_status ON Invoice(status);

CREATE TABLE InvoiceItem (
    invoice_item_id INT NOT NULL AUTO_INCREMENT,
    invoice_id      INT NOT NULL,
    service_id      INT NOT NULL,
    qty             INT NOT NULL DEFAULT 1,
    unit_price      DECIMAL(10,2) NOT NULL,
    line_total      DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (invoice_item_id),
    CONSTRAINT fk_invoiceitem_invoice
        FOREIGN KEY (invoice_id) REFERENCES Invoice(invoice_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_invoiceitem_service
        FOREIGN KEY (service_id) REFERENCES Service(service_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Feedback (
    feedback_id     INT NOT NULL AUTO_INCREMENT,
    patient_id      INT NULL,
    type            ENUM('Feedback', 'Complaint') NOT NULL,
    message         TEXT NOT NULL,
    status          ENUM('New', 'InReview', 'Closed') NOT NULL DEFAULT 'New',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (feedback_id),
    CONSTRAINT fk_feedback_patient
        FOREIGN KEY (patient_id) REFERENCES Patient(patient_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_feedback_patient ON Feedback(patient_id);
CREATE INDEX idx_feedback_status ON Feedback(status);

CREATE TABLE Reminder (
    reminder_id     INT NOT NULL AUTO_INCREMENT,
    patient_id      INT NOT NULL,
    reminder_type   ENUM('Appointment', 'Medication') NOT NULL,
    message         TEXT NOT NULL,
    remind_at       DATETIME NOT NULL,
    sent_flag       BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (reminder_id),
    CONSTRAINT fk_reminder_patient
        FOREIGN KEY (patient_id) REFERENCES Patient(patient_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_reminder_patient ON Reminder(patient_id);
CREATE INDEX idx_reminder_when ON Reminder(remind_at);

CREATE TABLE InsuranceProvider (
    provider_id INT NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    phone       VARCHAR(30) NULL,
    PRIMARY KEY (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE InsurancePolicy (
    policy_id         INT NOT NULL AUTO_INCREMENT,
    patient_id        INT NOT NULL,
    provider_id       INT NOT NULL,
    policy_number     VARCHAR(100) NOT NULL,
    coverage_details  TEXT NULL,
    valid_from        DATE NOT NULL,
    valid_to          DATE NOT NULL,
    PRIMARY KEY (policy_id),
    CONSTRAINT fk_policy_patient
        FOREIGN KEY (patient_id) REFERENCES Patient(patient_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_policy_provider
        FOREIGN KEY (provider_id) REFERENCES InsuranceProvider(provider_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_policy_patient ON InsurancePolicy(patient_id);
CREATE INDEX idx_policy_provider ON InsurancePolicy(provider_id);

INSERT INTO AdminUser (username, password_hash, role, last_login) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', NULL);
