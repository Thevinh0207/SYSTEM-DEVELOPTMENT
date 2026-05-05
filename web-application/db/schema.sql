-- ─── SCHEMA — MariaDB 10.4+ ──────────────────────────────────────────────
-- Mirrors the ERD: User, Services, Appointment, Reviews, Payments.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS appointment;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS user;

SET FOREIGN_KEY_CHECKS = 1;

-- ─── USER ────────────────────────────────────────────────────────────────
CREATE TABLE user (
    userID       INT AUTO_INCREMENT PRIMARY KEY,
    firstName    VARCHAR(50)  NOT NULL,
    lastName     VARCHAR(50)  NOT NULL,
    email        VARCHAR(50)  NOT NULL UNIQUE,
    password     VARCHAR(60)  NOT NULL,
    phoneNumber  VARCHAR(50)  NOT NULL,
    role         VARCHAR(20)  NOT NULL DEFAULT 'client',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_user_role CHECK (role IN ('admin', 'client')),
    INDEX idx_user_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── SERVICES ────────────────────────────────────────────────────────────
CREATE TABLE services (
    ServiceID    INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(50)    NOT NULL,
    category     VARCHAR(50)    NOT NULL,
    description  VARCHAR(255)   NOT NULL,
    price        DECIMAL(10, 2) NOT NULL,
    duration     INT            NOT NULL COMMENT 'minutes',
    created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_services_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── APPOINTMENT ─────────────────────────────────────────────────────────
CREATE TABLE appointment (
    AppointmentID INT AUTO_INCREMENT PRIMARY KEY,
    serviceID     INT          NOT NULL,
    userID        INT          NULL,
    guestName     VARCHAR(101) NULL,
    guestEmail    VARCHAR(100) NULL,
    guestPhone    VARCHAR(50)  NULL,
    date          DATE         NOT NULL,
    time          TIME         NOT NULL,
    notes         VARCHAR(255) NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    activeSlot    TINYINT      AS (CASE WHEN status <> 'cancelled' THEN 1 ELSE NULL END) STORED,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_appointment_service FOREIGN KEY (serviceID) REFERENCES services (ServiceID) ON DELETE RESTRICT  ON UPDATE CASCADE,
    CONSTRAINT fk_appointment_user    FOREIGN KEY (userID)    REFERENCES user (userID)        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_appointment_status CHECK (status IN ('pending', 'confirmed', 'completed', 'cancelled')),
    UNIQUE KEY uq_active_appointment_slot (date, time, activeSlot),
    INDEX idx_appointment_user (userID),
    INDEX idx_appointment_service (serviceID),
    INDEX idx_appointment_date (date),
    INDEX idx_appointment_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── REVIEWS ─────────────────────────────────────────────────────────────
CREATE TABLE reviews (
    ReviewID      INT AUTO_INCREMENT PRIMARY KEY,
    userID        INT          NOT NULL,
    appointmentID INT          NOT NULL,
    rating        INT          NOT NULL,
    comment       VARCHAR(255) NULL,
    reviewDate    DATE         NOT NULL,
    reply         VARCHAR(500) NULL,
    repliedAt     DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_user        FOREIGN KEY (userID)        REFERENCES user (userID)               ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_review_appointment FOREIGN KEY (appointmentID) REFERENCES appointment (AppointmentID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    UNIQUE KEY uq_review_per_appointment (appointmentID),
    INDEX idx_review_user (userID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PAYMENTS ────────────────────────────────────────────────────────────
CREATE TABLE payments (
    paymentID     INT AUTO_INCREMENT PRIMARY KEY,
    appointmentID INT            NOT NULL,
    paymentFrom      INT          NULL ,
    paymentFromName  VARCHAR(101) NOT NULL ,
    paymentFromEmail VARCHAR(100) NULL     ,
    paymentFromPhone VARCHAR(50)  NULL     ,
    paymentType   VARCHAR(50)    NOT NULL,
    paymentAmount DECIMAL(10, 2) NOT NULL,
    paymentStatus VARCHAR(50)    NOT NULL DEFAULT 'pending',
    created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_appointment FOREIGN KEY (appointmentID) REFERENCES appointment (AppointmentID) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_payment_from        FOREIGN KEY (paymentFrom)   REFERENCES user (userID)               ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_payment_status (paymentStatus),
    INDEX idx_payment_from (paymentFrom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
