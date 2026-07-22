CREATE DATABASE IF NOT EXISTS `sbnt9777_absensi`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `sbnt9777_absensi`;

DROP TABLE IF EXISTS `data_outlet`;
DROP TABLE IF EXISTS `data_invalid`;
DROP TABLE IF EXISTS `data_absen_foto`;
DROP TABLE IF EXISTS `data_absen`;
DROP TABLE IF EXISTS `data_karyawan`;
DROP TABLE IF EXISTS `app_settings`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_karyawan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `nip` varchar(50) NOT NULL,
  `uid` varchar(100) NOT NULL,
  `uid_fisik` varchar(50) DEFAULT NULL,
  `token_kartu` varchar(100) DEFAULT NULL,
  `nama` varchar(150) NOT NULL,
  `no_hp` varchar(30) DEFAULT NULL,
  `division` varchar(100) NOT NULL,
  `jabatan` varchar(100) DEFAULT NULL,
  `mail` varchar(150) NOT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `alamat` text,
  `picture` varchar(255) DEFAULT NULL,
  `status_karyawan` enum('AKTIF','NONAKTIF') NOT NULL DEFAULT 'AKTIF',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_data_karyawan_nip` (`nip`),
  UNIQUE KEY `uq_data_karyawan_uid` (`uid`),
  KEY `idx_data_karyawan_nama` (`nama`),
  KEY `idx_data_karyawan_division` (`division`),
  KEY `idx_data_karyawan_jabatan` (`jabatan`),
  KEY `idx_data_karyawan_status` (`status_karyawan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_outlet` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nama_outlet` varchar(150) NOT NULL,
  `kode_alat` varchar(50) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_data_outlet_nama_outlet` (`nama_outlet`),
  UNIQUE KEY `uq_data_outlet_kode_alat` (`kode_alat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_absen` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tanggal` date NOT NULL DEFAULT (curdate()),
  `waktu` time NOT NULL,
  `nip` varchar(50) NOT NULL,
  `uid` varchar(100) NOT NULL,
  `outlet_id` int unsigned DEFAULT NULL,
  `status` enum('IN','OUT') NOT NULL,
  `keterangan` enum('HADIR','1/2 HARI','IZIN','SAKIT','CUTI','ALPA','WFH') NOT NULL DEFAULT 'HADIR',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_data_absen_nip_tanggal` (`nip`,`tanggal`),
  KEY `idx_data_absen_uid_tanggal` (`uid`,`tanggal`),
  KEY `idx_data_absen_outlet_id` (`outlet_id`),
  KEY `idx_data_absen_tanggal` (`tanggal`),
  KEY `idx_data_absen_status` (`status`),
  CONSTRAINT `fk_data_absen_outlet_id`
    FOREIGN KEY (`outlet_id`) REFERENCES `data_outlet` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_data_absen_karyawan_nip`
    FOREIGN KEY (`nip`) REFERENCES `data_karyawan` (`nip`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_invalid` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tanggal` date NOT NULL DEFAULT (curdate()),
  `waktu` time NOT NULL,
  `uid` varchar(100) NOT NULL,
  `outlet_id` int unsigned DEFAULT NULL,
  `token_kartu` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'INVALID',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_data_invalid_uid` (`uid`),
  KEY `idx_data_invalid_outlet_id` (`outlet_id`),
  KEY `idx_data_invalid_token_kartu` (`token_kartu`),
  KEY `idx_data_invalid_tanggal` (`tanggal`),
  CONSTRAINT `fk_data_invalid_outlet_id`
    FOREIGN KEY (`outlet_id`) REFERENCES `data_outlet` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_absen_foto` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `absen_id` bigint unsigned NOT NULL,
  `uid` varchar(100) NOT NULL,
  `status` enum('IN','OUT') NOT NULL,
  `foto_path` varchar(255) NOT NULL,
  `captured_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_data_absen_foto_absen_id` (`absen_id`),
  KEY `idx_data_absen_foto_uid` (`uid`),
  KEY `idx_data_absen_foto_status` (`status`),
  KEY `idx_data_absen_foto_captured_at` (`captured_at`),
  CONSTRAINT `fk_data_absen_foto_absen_id`
    FOREIGN KEY (`absen_id`) REFERENCES `data_absen` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Developer / Debug Log
-- Mencatat semua request API untuk keperluan debugging,
-- monitoring, dan analisis mismatch kartu RFID.
-- ============================================================
CREATE TABLE `data_debug_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tanggal` date NOT NULL DEFAULT (curdate()),
  `waktu` time NOT NULL,
  `endpoint` varchar(100) NOT NULL DEFAULT '',
  `raw_input` text,
  `normalized_input` varchar(255) DEFAULT NULL,
  `match_result` varchar(20) DEFAULT NULL COMMENT 'MATCH / NO_MATCH / ERROR',
  `matched_table` varchar(50) DEFAULT NULL,
  `matched_field` varchar(50) DEFAULT NULL,
  `matched_id` int unsigned DEFAULT NULL,
  `matched_nip` varchar(50) DEFAULT NULL,
  `matched_nama` varchar(150) DEFAULT NULL,
  `request_params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_params`)),
  `response_code` smallint DEFAULT NULL,
  `response_body` longtext,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `duration_ms` int unsigned DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_debug_tanggal` (`tanggal`),
  KEY `idx_debug_endpoint` (`endpoint`),
  KEY `idx_debug_match_result` (`match_result`),
  KEY `idx_debug_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`username`, `password`)
VALUES
  ('admin', '$2y$12$/KMUS9jSadZIttBoIzD/7ekI8tC4KsQtGYOkLW4UapFOyP4P50Yd2');

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('attendance_period_start_day', '25'),
('attendance_period_end_day', '24')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
