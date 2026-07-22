<?php
require_once "config.php";

$term = isset($_GET['term']) ? trim((string) $_GET['term']) : '';

$data = array();
if ($term !== '') {
    $query = "SELECT id, nama, nip FROM data_karyawan WHERE nama LIKE ? OR nip LIKE ? LIMIT 10";
    if ($stmt = mysqli_prepare($link, $query)) {
        $termParam = '%' . $term . '%';
        mysqli_stmt_bind_param($stmt, "ss", $termParam, $termParam);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $data[] = array(
                    'label' => $row['nama'] . ' - ' . $row['nip'],
                    'value' => $row['nip']
                );
            }
        }

        mysqli_stmt_close($stmt);
    }
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($data);
mysqli_close($link);
