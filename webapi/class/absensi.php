<?php
date_default_timezone_set('Asia/Jakarta');
require_once dirname(__DIR__, 2) . '/shared/card_security.php';

class Absensi{
    private const DUPLICATE_WINDOW_SECONDS = 60;

	// Connection
	private $conn;

	// Table
	private $db_table = "data_absen";
	private $db_table1 = "data_karyawan";
	private $db_table2 = "data_invalid";

	// Columns
	public $id;
	public $tanggal;
	public $waktu;
	public $uid;
	public $nip;
	public $physical_uid;
	public $card_token;
	public $request_timestamp;
	public $request_signature;
	public $secure_mode = false;
	public $status;
	public $outlet_id;
	public $last_status;
	public $nama;
    public $message;
    public $absen_id;

	// Db connection
	public function __construct($db){
		$this->conn = $db;
	}

	// CREATE
	public function createData(){
        if ($this->secure_mode) {
            if (
                empty($this->physical_uid) ||
                empty($this->card_token) ||
                !card_verify_request_signature(
                    $this->physical_uid,
                    $this->card_token,
                    $this->request_timestamp,
                    $this->request_signature
                )
            ) {
                return false;
            }
        }

	//1. Cek user dari kartu aktif
		$sqlQuery = "SELECT * FROM ". $this->db_table1 ." WHERE uid = :uid AND status_karyawan = 'AKTIF' LIMIT 0,1";
		$stmt = $this->conn->prepare($sqlQuery);
		$stmt->bindParam(":uid", $this->uid);
		$stmt->execute();
		if($stmt->errorCode() == 0) {
			while(($dataRow = $stmt->fetch(PDO::FETCH_ASSOC)) != false) {
				$this->nama = $dataRow['nama'];
				$this->nip = $dataRow['nip'];
			}
		} else {
			$errors = $stmt->errorInfo();
			echo($errors[2]);
		}
		$itemCount = $stmt->rowCount();
		
		if($itemCount > 0){
            $todaySummary = $this->getTodaySummary();

            if ($todaySummary['latest_scan_at'] !== null) {
                $lastScanTs = strtotime($todaySummary['latest_scan_at']);
                $nowTs = time();

                if (
                    $lastScanTs !== false &&
                    $nowTs >= $lastScanTs &&
                    ($nowTs - $lastScanTs) <= self::DUPLICATE_WINDOW_SECONDS
                ) {
                    $this->status = "DUPLICATE";
                    $this->waktu = date("H:i:s");
                    $this->message = "Kartu baru saja discan. Silakan tunggu.";
                    $this->absen_id = null;
                    return true;
                }
            }

            if ((int) $todaySummary['count_in'] > 0 && (int) $todaySummary['count_out'] > 0) {
                $this->status = "COMPLETED";
                $this->waktu = date("H:i:s");
                $this->message = "Absensi hari ini sudah lengkap.";
                $this->absen_id = null;
                return true;
            }

			//set status harian
			if ((int) $todaySummary['count_in'] === 0){
				$this->status = "IN";
			}else{
				$this->status= "OUT";
			}

            $this->message = $this->status === "IN"
                ? "Absensi masuk berhasil."
                : "Absensi pulang berhasil.";

			//Insert Data to data_absen
			$todayDate = date("Y-m-d");
			$sqlQuery = "INSERT INTO ". $this->db_table ."
					SET	tanggal = :tanggal, waktu = :waktu, nip = :nip, uid = :uid, outlet_id = :outlet_id, status = :now_status, keterangan = :keterangan"; // uid disimpan sebagai snapshot kartu
						
			$this->waktu = date("H:i:s");
			
			$stmt = $this->conn->prepare($sqlQuery);
		
			// sanitize
			$this->uid=htmlspecialchars(strip_tags($this->uid));
		
			// bind data
			$stmt->bindParam(":tanggal", $todayDate);
			$stmt->bindParam(":nip", $this->nip);
			$stmt->bindParam(":uid", $this->uid);
            $stmt->bindValue(":outlet_id", $this->outlet_id ?: null, $this->outlet_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
			$stmt->bindParam(":now_status", $this->status);
			$stmt->bindParam(":waktu", $this->waktu);
			$keterangan = $this->resolveKeterangan($this->status, $todaySummary, $this->waktu); // Menetapkan keterangan
			$stmt->bindParam(":keterangan", $keterangan); // Mengikat keterangan
		
			if($stmt->execute()){
               $this->absen_id = (int) $this->conn->lastInsertId();
               if ($keterangan === "1/2 HARI") {
                   $this->markTodayHalfDay();
               }
			   return true;
			}
			return false;
		}
		else{
			//UID tidak terdaftar
			$this->status= "INVALID";
			$this->nama ="Invalid";
            $this->message = "Kartu belum terdaftar.";
            $this->absen_id = null;
			
			//Insert Data to data_invalid	
            if ($this->secure_mode) {
                $sqlQuery = "INSERT INTO
                            ". $this->db_table2 ."
                        SET
                            waktu = :waktu,
                            uid = :uid,
                            outlet_id = :outlet_id,
                            token_kartu = :token_kartu,
                            status = :now_status";
            } else {
			    $sqlQuery = "INSERT INTO
						    ". $this->db_table2 ."
					    SET
						    waktu = :waktu,
						    uid = :uid,
                            outlet_id = :outlet_id,
						    status = :now_status";
            }
			$this->waktu = date("H:i:s");
			
			$stmt = $this->conn->prepare($sqlQuery);
		
			// sanitize
            if ($this->secure_mode) {
                $this->uid = htmlspecialchars(strip_tags($this->physical_uid));
                $this->card_token = htmlspecialchars(strip_tags($this->card_token));
            } else {
			    $this->uid=htmlspecialchars(strip_tags($this->uid));
            }
		
			// bind data
			$stmt->bindParam(":uid", $this->uid);
            $stmt->bindValue(":outlet_id", $this->outlet_id ?: null, $this->outlet_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
			$stmt->bindParam(":now_status", $this->status);
			$stmt->bindParam(":waktu", $this->waktu);
            if ($this->secure_mode) {
                $stmt->bindParam(":token_kartu", $this->card_token);
            }
		
			if($stmt->execute()){
			   return true;
			}
			return false;
			
		}
		
	}

    private function getTodaySummary()
    {
        $sqlQuery = "SELECT
                        SUM(CASE WHEN status = 'IN' THEN 1 ELSE 0 END) AS count_in,
                        SUM(CASE WHEN status = 'OUT' THEN 1 ELSE 0 END) AS count_out,
                        MAX(TIMESTAMP(tanggal, waktu)) AS latest_scan_at,
                        MIN(CASE WHEN status = 'IN' THEN waktu ELSE NULL END) AS first_in_time,
                        MIN(CASE WHEN status = 'IN' THEN outlet_id ELSE NULL END) AS in_outlet_id
                    FROM " . $this->db_table . "
                    WHERE nip = :nip
                      AND tanggal = CURDATE()";

        $stmt = $this->conn->prepare($sqlQuery);
        $stmt->bindParam(":nip", $this->nip);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'count_in' => (int) ($row['count_in'] ?? 0),
            'count_out' => (int) ($row['count_out'] ?? 0),
            'latest_scan_at' => $row['latest_scan_at'] ?? null,
            'first_in_time' => $row['first_in_time'] ?? null,
            'in_outlet_id' => $row['in_outlet_id'] ?? null,
        ];
    }

    private function getSetting($key, $default)
    {
        $sqlQuery = "SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1";
        $stmt = $this->conn->prepare($sqlQuery);
        $stmt->bindParam(":setting_key", $key);
        if (!$stmt->execute()) {
            return $default;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row["setting_value"] : $default;
    }

    private function timeToMinutes($time)
    {
        if (!is_string($time) || !preg_match("/^\d{2}:\d{2}/", $time)) {
            return null;
        }

        $parts = explode(":", $time);
        return ((int) $parts[0] * 60) + (int) $parts[1];
    }

    private function isFlexibleOutlet($outletId)
    {
        $ids = array_filter(array_map("trim", explode(",", $this->getSetting("flexible_outlet_ids", "1"))), "strlen");
        return $outletId !== null && in_array((string) $outletId, $ids, true);
    }

    private function resolveKeterangan($status, $todaySummary, $currentTime)
    {
        if ($this->getSetting("halfday_enabled", "0") !== "1") {
            return "HADIR";
        }

        if ($status !== "OUT") {
            return "HADIR";
        }

        $inMinutes = $this->timeToMinutes($todaySummary["first_in_time"] ?? null);
        $outMinutes = $this->timeToMinutes($currentTime);

        if ($inMinutes === null || $outMinutes === null) {
            return "HADIR";
        }

        $workedMinutes = $outMinutes - $inMinutes;
        if ($workedMinutes < 0) {
            $workedMinutes += 24 * 60;
        }

        $isFlexible = $this->isFlexibleOutlet($todaySummary["in_outlet_id"] ?? null);
        $fullDayMinMinutes = (int) $this->getSetting($isFlexible ? "flexible_full_day_min_minutes" : "outlet_full_day_min_minutes", $isFlexible ? "420" : "480");
        if ($workedMinutes < $fullDayMinMinutes) {
            return "1/2 HARI";
        }

        return "HADIR";
    }

    private function markTodayHalfDay()
    {
        $sqlQuery = "UPDATE " . $this->db_table . " SET keterangan = '1/2 HARI' WHERE nip = :nip AND tanggal = CURDATE() AND status IN ('IN', 'OUT')";
        $stmt = $this->conn->prepare($sqlQuery);
        $stmt->bindParam(":nip", $this->nip);
        $stmt->execute();
    }

}
?>
