# Sistem Absensi RFID + ESP32-CAM

Aplikasi absensi berbasis PHP/MySQL untuk RFID MIFARE, ESP8266/NodeMCU, dan ESP32-CAM. Sistem mencatat absen masuk/keluar, lokasi alat/outlet, foto masuk/keluar, data karyawan, rekap harian/bulanan, export XLSX/PDF/ZIP, serta penandaan `ALPA` otomatis lewat cron.

## Fitur Utama

- Login admin web.
- Manajemen data karyawan dengan NIP sebagai identitas utama.
- UID kartu bisa diganti tanpa mengganti data karyawan.
- Data outlet/lokasi alat.
- Absensi RFID via ESP8266.
- Trigger kamera via UART/kabel ke ESP32-CAM.
- Upload foto absensi langsung dari ESP32-CAM ke server.
- Foto karyawan dengan upload, kamera browser, kompres otomatis, dan crop 4x6.
- Rekap data absen harian dan bulanan.
- Export rekap bulanan ke ZIP berisi XLSX/PDF.
- Link foto masuk/keluar pada jam masuk/jam keluar di export.
- Cron otomatis menandai `ALPA` untuk karyawan yang tidak punya data absen pada tanggal berjalan.

## Struktur Folder

```text
apps/                         Halaman admin web
webapi/                       API untuk ESP8266/ESP32-CAM
shared/                       Helper keamanan kartu/signature
scan_kartu/                   Firmware ESP8266, ESP32-CAM, dan writer kartu
scan_kartu/device_firmware/   Copy firmware untuk alat 01 sampai 13
cron/                         Script cron server
uploads/                      Upload foto absen dan foto karyawan
src/                          Asset template/admin
database.sql                  Schema database utama
```

## Database

Database utama: `sbnt9777_absensi`

Tabel penting:

- `users`: akun login admin.
- `app_settings`: pengaturan aplikasi.
- `data_karyawan`: master karyawan, NIP, UID internal, UID fisik, token kartu, divisi, jabatan, foto.
- `data_outlet`: data outlet/lokasi dan kode alat.
- `data_absen`: event absensi IN/OUT dan keterangan.
- `data_absen_foto`: foto masuk/keluar per event absen.
- `data_invalid`: kartu invalid/belum terdaftar.

Import awal:

```bash
mysql -u USER -p DATABASE_NAME < database.sql
```

Catatan: pada production, sesuaikan `apps/config.php` dengan kredensial database server.

## Konfigurasi Web

File konfigurasi database:

```text
apps/config.php
```

Contoh isi:

```php
define('DB_SERVER', '127.0.0.1');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'password');
define('DB_NAME', 'sbnt9777_absensi');
define('DB_PORT', '3306');
```

Jangan commit kredensial production ke repository publik.

## API IoT

Endpoint utama:

```text
webapi/api/create.php              ESP8266 kirim scan kartu
webapi/api/upload_foto_absen.php   ESP32-CAM upload foto
webapi/api/camera_debug_log.php    Log debug ESP32-CAM
```

Flow absensi:

1. ESP8266 membaca UID fisik dan token kartu.
2. ESP8266 membuat UID internal dari kombinasi UID fisik + token.
3. ESP8266 kirim request ke `webapi/api/create.php`.
4. Server membuat data absen `IN` atau `OUT`.
5. Server mengembalikan `absen_id`, `uid`, dan `status`.
6. ESP8266 mengirim payload kecil ke ESP32-CAM lewat UART.
7. ESP32-CAM mengambil foto dan upload ke `upload_foto_absen.php`.
8. Web menampilkan foto masuk/keluar dari `data_absen_foto`.

## Firmware

Firmware utama:

```text
scan_kartu/scan_kartu.ino
scan_kartu/esp32_cam_foto_absen/esp32_cam_foto_absen.ino
scan_kartu/write_kartu/write_kartu.ino
```

Firmware per alat:

```text
scan_kartu/device_firmware/alat_01/
scan_kartu/device_firmware/alat_02/
...
scan_kartu/device_firmware/alat_13/
```

Yang wajib diganti per alat:

```cpp
const int outlet_id = 1; // ganti sesuai outlet/lokasi alat
```

Konfigurasi WiFi aktif pada firmware utama:

```cpp
#define WIFI_SSID "infinix ZERO 5G 2023"
#define WIFI_PASSWORD "SCORPIO2510#"
```

Endpoint production aktif:

```text
https://absen.bba17.web.id/webapi/api/create.php
https://absen.bba17.web.id/webapi/api/upload_foto_absen.php
```

## Wiring Ringkas

RFID RC522 ke NodeMCU ESP8266:

```text
RC522 GND  -> ESP8266 GND
RC522 3.3V -> ESP8266 3V3
RC522 RST  -> ESP8266 D3
RC522 SDA  -> ESP8266 D4
RC522 SCK  -> ESP8266 D5
RC522 MISO -> ESP8266 D6
RC522 MOSI -> ESP8266 D7
```

ESP8266 ke ESP32-CAM:

```text
GND bersama
5V stabil ke ESP32-CAM 5V
UART ESP8266 -> ESP32-CAM sesuai firmware
```

Catatan power:

- ESP32-CAM butuh 5V stabil.
- Jangan mengandalkan power lemah dari USB/board jika ESP32-CAM sering gagal WiFi atau reset.
- Pastikan GND ESP8266 dan ESP32-CAM tersambung.

## Upload dan Proteksi Foto

Folder upload:

```text
uploads/absen/
uploads/karyawan/
```

Proteksi:

- `uploads/.htaccess` mematikan directory listing dengan `Options -Indexes`.
- File foto tetap bisa dibuka jika URL file diketahui.
- `apps/view_upload.php` tersedia untuk akses foto via session login jika dibutuhkan.

Foto karyawan:

- Upload dari file/galeri.
- Tombol kamera browser menggunakan `getUserMedia()`.
- Kompres otomatis sebelum upload.
- Crop wajib rasio 4x6.
- Hasil crop: JPEG 800x1200 px.

## Cron ALPA Otomatis

Script:

```text
cron/mark_alpa.php
```

Cara kerja:

- Mengecek semua karyawan.
- Jika karyawan belum punya data apa pun di `data_absen` pada tanggal tersebut, sistem insert `ALPA`.
- Tetap jalan hari Minggu.
- Insert hanya sekali per karyawan per tanggal.
- Row ALPA memakai `status='IN'`, `waktu='00:00:00'`, `keterangan='ALPA'`.
- Rekap/export sudah disesuaikan agar `ALPA` terbaca walau tidak punya `OUT`.

Test manual:

```bash
/usr/bin/php /var/www/absen-iot/cron/mark_alpa.php
```

Test tanggal tertentu:

```bash
/usr/bin/php /var/www/absen-iot/cron/mark_alpa.php 2026-07-11
```

Cron production jam 23:59 WIB:

```bash
sudo crontab -u www-data -e
```

Isi:

```cron
59 23 * * * /usr/bin/php /var/www/absen-iot/cron/mark_alpa.php >> /var/www/absen-iot/cron/mark_alpa.log 2>&1
```

Jika test manual butuh redirect sebagai `www-data`:

```bash
sudo -u www-data sh -c '/usr/bin/php /var/www/absen-iot/cron/mark_alpa.php >> /var/www/absen-iot/cron/mark_alpa.log 2>&1'
```

Cek log:

```bash
sudo tail -n 50 /var/www/absen-iot/cron/mark_alpa.log
```

Pastikan timezone server:

```bash
timedatectl
```

Disarankan:

```text
Asia/Jakarta
```

## Rekap dan Export

Halaman penting:

```text
apps/data_absen-index.php
apps/rekap_data_absen-index.php
apps/rekap_absen_bulanan-index.php
apps/rekap_bulanan-view.php
apps/rekap_bulanan-cetak.php
apps/rekap_bulanan-export-helper.php
```

Aturan rekap saat ini:

- `ALPA` dihitung sebagai Alpa.
- `IZIN`, `SAKIT`, `CUTI`, `WFH`, `1/2 HARI` dihitung berdasarkan keterangan manual.
- Data yang hanya punya jam masuk tetap dihitung sebagai `Hadir`, selama bukan `ALPA/CUTI/IZIN/SAKIT/1/2 HARI`.
- `ALPA` pada export ditampilkan dengan lokasi/jam `-`.

## Operasional Kartu

Kartu memakai kombinasi:

```text
UID fisik kartu + token kartu
```

Jika kartu hilang:

1. Tulis token baru ke kartu baru memakai `scan_kartu/write_kartu/write_kartu.ino`.
2. Ambil UID fisik dan token kartu baru.
3. Update data karyawan lama dengan UID fisik + token baru.
4. NIP dan data karyawan tetap sama.

## Checklist Production

- Pastikan `apps/config.php` production benar.
- Pastikan timezone server `Asia/Jakarta`.
- Pastikan cron ALPA berjalan sebagai `www-data`.
- Pastikan folder `uploads/` writable oleh web server.
- Pastikan folder `cron/` writable untuk log.
- Pastikan `uploads/` tidak menampilkan `Index of`.
- Pastikan domain sudah HTTPS.
- Pastikan admin password bukan default.
- Pastikan semua firmware alat memakai `outlet_id` yang benar.
- Test scan IN dan OUT.
- Test foto masuk dan foto keluar.
- Test kartu invalid dan registrasi kartu.
- Test export rekap bulanan ZIP/XLSX/PDF.
- Test cron ALPA dengan tanggal tertentu sebelum jadwal production.
- Setup backup database dan folder uploads.

## Perintah Debug Server

Cek cron:

```bash
sudo systemctl status cron
sudo crontab -u www-data -l
sudo tail -n 50 /var/www/absen-iot/cron/mark_alpa.log
```

Cek error Apache:

```bash
sudo tail -n 100 /var/log/apache2/error.log
```

Cek permission:

```bash
ls -lah /var/www/absen-iot
ls -lah /var/www/absen-iot/uploads
ls -lah /var/www/absen-iot/cron
```

Cek PHP syntax:

```bash
php -l apps/data_karyawan-read.php
php -l apps/rekap_bulanan-cetak.php
php -l cron/mark_alpa.php
```

## Catatan Penting

- Firmware ESP8266/ESP32-CAM tidak perlu diubah saat hanya mengubah label web seperti divisi/jabatan/lokasi.
- Jika domain API berubah, firmware ESP8266 dan ESP32-CAM harus diubah dan flash ulang.
- Jika WiFi berubah, firmware harus disesuaikan atau SSID/password hotspot dibuat sama.
- `ALPA` otomatis tidak menggantikan kebutuhan kalender libur. Jika ada libur nasional/cuti bersama, perlu pengaturan tambahan agar cron tidak menandai `ALPA` pada hari tersebut.
