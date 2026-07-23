-- ============================================================
-- Migration: Buat tabel rfid_scan_queue untuk registrasi karyawan
-- 
-- Jalankan di phpMyAdmin atau MySQL CLI:
--   mysql -u user -p database_name < migrations/add_rfid_scan_queue.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS `rfid_scan_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid_fisik` varchar(50) NOT NULL,
  `token_kartu` varchar(100) DEFAULT NULL,
  `internal_uid` varchar(100) DEFAULT NULL,
  `is_consumed` tinyint(1) NOT NULL DEFAULT 0,
  `scanned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scan_consumed` (`is_consumed`),
  KEY `idx_scan_scanned_at` (`scanned_at`),
  KEY `idx_scan_uid_fisik` (`uid_fisik`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
