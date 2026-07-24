-- ============================================================
-- Migration: Tabel device_config + kolom device_id di data_outlet
-- 
-- Fitur: Remote WiFi Config untuk ESP8266
-- Admin bisa scan WiFi, pilih SSID, set password dari dashboard
-- tanpa perlu menyentuh ESP.
--
-- Jalankan di phpMyAdmin atau MySQL CLI:
--   mysql -u user -p database_name < migrations/add_device_config.sql
-- ============================================================

-- 1. Tambah kolom device_id ke data_outlet
ALTER TABLE `data_outlet`
  ADD COLUMN `device_id` VARCHAR(50) DEFAULT NULL AFTER `kode_alat`,
  ADD UNIQUE KEY `uq_data_outlet_device_id` (`device_id`);

-- 2. Tabel device_config — konfigurasi ESP per device
CREATE TABLE IF NOT EXISTS `device_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `mac_address` varchar(20) NOT NULL COMMENT 'MAC unik ESP (format: AC:67:B2:XX:XX:XX)',
  `device_id` varchar(50) DEFAULT NULL COMMENT 'Label device (misal: ALAT-01)',
  `outlet_id` int unsigned DEFAULT NULL COMMENT 'FK ke data_outlet',
  
  -- WiFi config aktif
  `wifi_ssid` varchar(100) DEFAULT NULL COMMENT 'SSID WiFi yang sedang dipakai',
  `wifi_signal` int DEFAULT NULL COMMENT 'Signal strength terakhir (dBm)',
  
  -- WiFi pending (menunggu ESP apply)
  `wifi_pending` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ada WiFi baru menunggu diterapkan',
  `wifi_pending_ssid` varchar(100) DEFAULT NULL,
  `wifi_pending_password` varchar(255) DEFAULT NULL,
  `wifi_config_version` int NOT NULL DEFAULT 0 COMMENT 'Naik setiap kali config berubah',
  `wifi_applied_version` int NOT NULL DEFAULT 0 COMMENT 'Version terakhir yang diterapkan ESP',
  
  -- Scan command
  `scan_command` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ESP disuruh scan WiFi',
  `scan_results` JSON DEFAULT NULL COMMENT 'Hasil scan terakhir [{ssid, rssi, encr}]',
  `scan_requested_at` datetime DEFAULT NULL,
  `scan_completed_at` datetime DEFAULT NULL,
  
  -- Status
  `last_seen_at` datetime DEFAULT NULL COMMENT 'Last poll dari ESP',
  `firmware_version` varchar(20) DEFAULT NULL,
  `current_mode` enum('absen','register') DEFAULT 'absen',
  
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_mac` (`mac_address`),
  KEY `idx_device_outlet` (`outlet_id`),
  KEY `idx_device_id` (`device_id`),
  CONSTRAINT `fk_device_outlet`
    FOREIGN KEY (`outlet_id`) REFERENCES `data_outlet` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
