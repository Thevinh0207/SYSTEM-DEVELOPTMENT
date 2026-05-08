-- ─── SCHEMA — MariaDB 10.4+ ──────────────────────────────────────────────
-- Mirrors the ERD: User, Services, Appointment, Reviews, Payments.
-- All columns use snake_case to match RedBeanPHP's convention. Bean access
-- in PHP can still use camelCase ($bean->firstName) — RedBean translates.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS appointment;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS user;
DROP TABLE IF EXISTS faq;
DROP TABLE IF EXISTS about_section;
DROP TABLE IF EXISTS service_category;

SET FOREIGN_KEY_CHECKS = 1;

-- ─── USER ────────────────────────────────────────────────────────────────
CREATE TABLE user (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    first_name   VARCHAR(50)  NOT NULL,
    last_name    VARCHAR(50)  NOT NULL,
    email        VARCHAR(50)  NOT NULL UNIQUE,
    password     VARCHAR(60)  NOT NULL,
    phone_number VARCHAR(50)  NOT NULL,
    role         VARCHAR(20)  NOT NULL DEFAULT 'client',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_user_role CHECK (role IN ('admin', 'client')),
    INDEX idx_user_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── SERVICE CATEGORIES ─────────────────────────────────────────────────
-- Admin manages this list via /admin/service-categories.
CREATE TABLE service_category (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50) NOT NULL UNIQUE,
    sort_order  INT         NOT NULL DEFAULT 0,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── SERVICES ────────────────────────────────────────────────────────────
CREATE TABLE services (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(50)    NOT NULL,
    category_id  INT            NOT NULL,
    description  VARCHAR(255)   NOT NULL,
    price        DECIMAL(10, 2) NOT NULL,
    duration     INT            NOT NULL,
    created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_category FOREIGN KEY (category_id) REFERENCES service_category (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_services_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── APPOINTMENT ─────────────────────────────────────────────────────────
CREATE TABLE appointment (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    service_id    INT          NOT NULL,
    user_id       INT          NULL,
    guest_name    VARCHAR(101) NOT NULL,
    guest_email   VARCHAR(100) NULL,
    guest_phone   VARCHAR(50)  NULL,
    date          DATE         NOT NULL,
    time          TIME         NOT NULL,
    notes         VARCHAR(255) NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_appointment_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT  ON UPDATE CASCADE,
    CONSTRAINT fk_appointment_user    FOREIGN KEY (user_id)    REFERENCES user (id)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_appointment_status CHECK (status IN ('pending', 'confirmed', 'completed', 'cancelled')),
    -- Note: a generated `active_slot` column + UNIQUE (date, time, active_slot)
    -- used to prevent double-bookings at the DB level, but RedBean's UPDATE
    -- writes every bean column, which conflicts with generated columns.
    -- The PHP-level isAvailable() check in AppointmentModel handles this now.
    INDEX idx_appointment_user (user_id),
    INDEX idx_appointment_service (service_id),
    INDEX idx_appointment_date (date),
    INDEX idx_appointment_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── REVIEWS ─────────────────────────────────────────────────────────────
CREATE TABLE reviews (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    appointment_id  INT          NOT NULL,
    rating          INT          NOT NULL,
    comment         VARCHAR(255) NULL,
    review_date     DATE         NOT NULL,
    reply           VARCHAR(500) NULL,
    replied_at      DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_user        FOREIGN KEY (user_id)        REFERENCES user (id)        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_review_appointment FOREIGN KEY (appointment_id) REFERENCES appointment (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    UNIQUE KEY uq_review_per_appointment (appointment_id),
    INDEX idx_review_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PAYMENTS ────────────────────────────────────────────────────────────
CREATE TABLE payments (
    id                  INT            AUTO_INCREMENT PRIMARY KEY,
    appointment_id      INT            NOT NULL,
    payment_from        INT            NULL,
    payment_from_name   VARCHAR(101)   NOT NULL,
    payment_from_email  VARCHAR(100)   NULL,
    payment_from_phone  VARCHAR(50)    NULL,
    payment_type        VARCHAR(50)    NOT NULL,
    payment_amount      DECIMAL(10, 2) NOT NULL,
    payment_status      VARCHAR(50)    NOT NULL DEFAULT 'pending',
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_appointment FOREIGN KEY (appointment_id) REFERENCES appointment (id) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_payment_from        FOREIGN KEY (payment_from)   REFERENCES user (id)        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_payment_status (payment_status),
    INDEX idx_payment_from (payment_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── FAQ ─────────────────────────────────────────────────────────────────
-- Editable FAQ entries. Admin can CRUD via /admin/faq.
-- `category` is a free-text grouping label (e.g. "Deposit & Cancellation").
CREATE TABLE faq (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    category    VARCHAR(50)  NOT NULL DEFAULT 'General',
    question    VARCHAR(255) NOT NULL,
    answer      TEXT         NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_faq_sort (sort_order),
    INDEX idx_faq_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── ABOUT SECTIONS ──────────────────────────────────────────────────────
-- Editable About-page story blocks. Admin can CRUD via /admin/about.
CREATE TABLE about_section (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    heading     VARCHAR(100) NOT NULL,
    body        TEXT         NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_about_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
