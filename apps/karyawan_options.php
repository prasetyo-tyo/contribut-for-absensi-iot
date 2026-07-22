<?php

function get_division_options()
{
    return [
        'Direksi',
        'Operasional',
        'Produksi / Kitchen',
        'Finance & Accounting',
        'Marketing',
        'HRGA',
        'Purchasing',
        'Quality Control',
        'Warehouse',
        'Engineering',
    ];
}

function get_jabatan_options()
{
    return [
        'Direktur',
        'MR',
        'Finance Manager',
        'Marketing Manager',
        'QC Manager',
        'Operasional Manager',
        'Purchasing Staff',
        'HRGA Staff',
        'Staff Finance',
        'Staff Accounting',
        'Staff Marketing',
        'Kepala Kitchen',
        'Kepala Outlet',
        'Engineering Staff',
        'Kepala Warehouse',
        'Admin',
        'Content Creator',
        'Kasir',
        'Koki',
        'Asisten Koki',
        'Pramusaji',
        'Kurir Delivery',
        'Dishwasher',
        'Cleaning Service',
        'Staff',
    ];
}

function render_options($options, $selectedValue)
{
    foreach ($options as $option) {
        $selected = ((string) $selectedValue === (string) $option) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars((string) $option, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars((string) $option, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}
