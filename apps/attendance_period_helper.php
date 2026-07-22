<?php

require_once __DIR__ . '/app_settings.php';

function attendance_period_normalize_day($value, $default)
{
    $day = (int) $value;
    if ($day < 1 || $day > 31) {
        return (int) $default;
    }

    return $day;
}

function attendance_period_settings($link)
{
    return [
        'start_day' => attendance_period_normalize_day(
            get_app_setting($link, 'attendance_period_start_day', '25'),
            25
        ),
        'end_day' => attendance_period_normalize_day(
            get_app_setting($link, 'attendance_period_end_day', '24'),
            24
        ),
    ];
}

function attendance_period_clamped_date(DateTime $month, $day)
{
    $year = (int) $month->format('Y');
    $monthNumber = (int) $month->format('m');
    $lastDay = (int) $month->format('t');
    $safeDay = min((int) $day, $lastDay);

    return sprintf('%04d-%02d-%02d', $year, $monthNumber, $safeDay);
}

function attendance_period_from_month($link, $value)
{
    $value = trim((string) $value);
    if (!preg_match('/^(0[1-9]|1[0-2])-(\d{4})$/', $value, $matches)) {
        $value = date('m-Y');
        preg_match('/^(0[1-9]|1[0-2])-(\d{4})$/', $value, $matches);
    }

    $settings = $link ? attendance_period_settings($link) : ['start_day' => 25, 'end_day' => 24];
    $selectedMonth = DateTime::createFromFormat('!Y-m-d', $matches[2] . '-' . $matches[1] . '-01');
    if (!$selectedMonth) {
        $selectedMonth = new DateTime(date('Y-m-01'));
    }

    $startMonth = clone $selectedMonth;
    $endMonth = clone $selectedMonth;

    if ($settings['start_day'] > $settings['end_day']) {
        $startMonth->modify('-1 month');
    }

    return [
        'value' => $value,
        'month' => $matches[1],
        'year' => $matches[2],
        'start' => attendance_period_clamped_date($startMonth, $settings['start_day']),
        'end' => attendance_period_clamped_date($endMonth, $settings['end_day']),
        'start_day' => $settings['start_day'],
        'end_day' => $settings['end_day'],
    ];
}

function attendance_period_label(array $period)
{
    return date('d/m/Y', strtotime($period['start'])) . ' - ' . date('d/m/Y', strtotime($period['end']));
}
