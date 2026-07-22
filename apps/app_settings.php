<?php

function ensure_app_settings_table($link)
{
    static $ensured = false;

    if ($ensured) {
        return true;
    }

    $sql = "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key varchar(100) NOT NULL,
                setting_value text DEFAULT NULL,
                created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $ensured = mysqli_query($link, $sql) === true;
    return $ensured;
}

function get_app_setting($link, $key, $default = null)
{
    if (!ensure_app_settings_table($link)) {
        return $default;
    }

    $sql = "SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1";
    if (!$stmt = mysqli_prepare($link, $sql)) {
        return $default;
    }

    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return $row ? (string) $row['setting_value'] : $default;
}

function set_app_setting($link, $key, $value)
{
    if (!ensure_app_settings_table($link)) {
        return false;
    }

    $sql = "INSERT INTO app_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";

    if (!$stmt = mysqli_prepare($link, $sql)) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $key, $value);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function get_absen_view_pin_hash($link)
{
    return get_app_setting($link, 'absen_view_pin_hash', '');
}
