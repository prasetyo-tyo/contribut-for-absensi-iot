<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

include_once '../config/database.php';
include_once '../class/absensi.php';
require_once dirname(__DIR__, 2) . '/shared/card_security.php';

define('DEFAULT_OUTLET_ID', null);

$database = new Database();
$db = $database->getConnection();

$item = new Absensi($db);

if (
    !isset($_GET['uid_fisik'], $_GET['token'], $_GET['ts'], $_GET['sig']) ||
    $_GET['uid_fisik'] === '' ||
    $_GET['token'] === ''
) {
    http_response_code(400);
    echo json_encode([
        "error" => "Signed device payload is required.",
    ]);
    exit;
}

$item->physical_uid = card_normalize_value($_GET['uid_fisik']);
$item->card_token = card_normalize_value($_GET['token']);
$item->request_timestamp = $_GET['ts'];
$item->request_signature = strtolower($_GET['sig']);
$item->uid = card_build_internal_uid($item->physical_uid, $item->card_token);
$item->secure_mode = true;

$item->outlet_id = isset($_GET['outlet_id']) && ctype_digit((string) $_GET['outlet_id'])
    ? (int) $_GET['outlet_id']
    : DEFAULT_OUTLET_ID;
	
if($item->createData()){
	// create array
	$data_arr = array(
		"waktu" => $item->waktu,
		"nama" => $item->nama,
		"nip" => $item->nip,
		"uid" => $item->uid,
		"uid_fisik" => $item->physical_uid,
        "status" =>  $item->status,
        "outlet_id" => $item->outlet_id,
        "absen_id" => $item->absen_id,
        "message" => $item->message,
        "camera_triggered" => false
	);

	http_response_code(200);
	echo json_encode($data_arr);
} else{
	http_response_code(404);
	echo json_encode("Failed!");
}
?>
