/**
 * scan.ino — Firmware ESP8266 + RC522 DUAL-MODE
 * 
 * MODE ABSEN (default):
 *   Tap kartu → create_legacy.php → absensi IN/OUT
 * 
 * MODE REGISTER (aktif otomatis saat admin buka form Tambah Karyawan):
 *   Tap kartu → scan-register.php → form auto-fill UID + Token
 * 
 * REMOTE WiFi CONFIG (v2.0):
 *   - WiFi tersimpan di EEPROM, bukan hardcode
 *   - First-time setup: AP mode dengan captive portal (WiFiManager)
 *   - Poll server setiap 15 detik untuk config (scan WiFi, ganti WiFi)
 *   - Scan WiFi dari dashboard → ESP scan → kirim hasil ke server
 *   - Ganti WiFi dari dashboard → ESP simpan ke EEPROM → reboot
 * 
 * Mode dikontrol via register-mode.php (ESP cek sebelum kirim).
 * Auto-reset ke ABSEN setelah 2 menit jika register mode stuck.
 * 
 * Wiring RC522 → ESP8266:
 *   SDA  → D4,  SCK  → D5,  MOSI → D7,  MISO → D6
 *   RST  → D3,  3.3V → 3.3V, GND → GND
 *   LCD I2C: SDA → D2, SCL → D1
 */

#include <SPI.h>
#include <MFRC522.h>
#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <LiquidCrystal_I2C.h>
#include <ArduinoJson.h>
#include <EEPROM.h>
#include <WiFiManager.h>

// ─── Pin Configuration ─────────────────────────────────────
#define RST_PIN  D3
#define SS_PIN   D4
#define BUZZER   D8

// ─── EEPROM Layout ─────────────────────────────────────────
// Address 0-63:   WiFi SSID (null-terminated, max 63 chars)
// Address 64-127: WiFi Password (null-terminated, max 63 chars)
// Address 128-191: Device ID (null-terminated, max 63 chars)
// Address 192:    Magic byte (0xAA = valid config)
#define EEPROM_SIZE 256
#define EEPROM_MAGIC 0xAA
#define ADDR_WIFI_SSID    0
#define ADDR_WIFI_PASS    64
#define ADDR_DEVICE_ID    128
#define ADDR_MAGIC        192

// ─── RFID Reader ───────────────────────────────────────────
MFRC522 mfrc522(SS_PIN, RST_PIN);
MFRC522::MIFARE_Key key;
MFRC522::StatusCode rfid_status;

int blockNum = 2;
byte bufferLen = 18;
byte readBlockData[18];

// ─── Server URLs ───────────────────────────────────────────
const String server_base = "https://sbn-absensi.bakmibangkaasli17.com/webapi/api/";
const String device_config_url = server_base + "device-config.php";
const String device_register_url = server_base + "device-register.php";
const String device_scan_report_url = server_base + "device-scan-report.php";
const String legacy_url_prefix = server_base + "create_legacy.php?uid=";
const String register_url = server_base + "scan-register.php";
const String mode_url = server_base + "register-mode.php?check=1";

// ─── Device Identity ───────────────────────────────────────
String current_device_id = "";
String card_holder_name;

// ─── LCD ───────────────────────────────────────────────────
LiquidCrystal_I2C lcd(0x27, 16, 2);

// ─── Cooldown ──────────────────────────────────────────────
unsigned long lastScanTime = 0;
const unsigned long SCAN_COOLDOWN = 5000; // 5 detik antar scan kartu

// ─── Config Polling ─────────────────────────────────────────
unsigned long lastConfigPoll = 0;
const unsigned long CONFIG_POLL_INTERVAL = 15000; // 15 detik
int lastConfigVersion = 0;

// ─── Forward Declarations ──────────────────────────────────
String getUidFisik();
bool isBlockEmpty(byte blockData[]);
bool ReadDataFromBlock(int bNum, byte readBlockData[]);
String urlEncode(const String &value);
bool doHttpsRequest(const String &url, String &responsePayload, int &httpCode);
bool checkRegisterMode();
bool sendRegisterData(const String &uidFisik, const String &tokenKartu);
void pollDeviceConfig();
void doDeviceRegister();

// ─── EEPROM Helpers ────────────────────────────────────────
void eepromReadString(int addr, char* buf, int maxLen) {
  for (int i = 0; i < maxLen; i++) {
    buf[i] = EEPROM.read(addr + i);
    if (buf[i] == '\0') break;
  }
  buf[maxLen - 1] = '\0';
}

void eepromWriteString(int addr, const String &str, int maxLen) {
  for (int i = 0; i < maxLen - 1; i++) {
    EEPROM.write(addr + i, i < str.length() ? str.charAt(i) : '\0');
  }
}

bool isEepromValid() {
  return EEPROM.read(ADDR_MAGIC) == EEPROM_MAGIC;
}

void saveWifiToEeprom(const String &ssid, const String &pass, const String &deviceId) {
  eepromWriteString(ADDR_WIFI_SSID, ssid, 64);
  eepromWriteString(ADDR_WIFI_PASS, pass, 64);
  eepromWriteString(ADDR_DEVICE_ID, deviceId, 64);
  EEPROM.write(ADDR_MAGIC, EEPROM_MAGIC);
  EEPROM.commit();
  Serial.println(F("Config saved to EEPROM"));
}

String eepromReadSSID() {
  char buf[64];
  eepromReadString(ADDR_WIFI_SSID, buf, 64);
  return String(buf);
}

String eepromReadPass() {
  char buf[64];
  eepromReadString(ADDR_WIFI_PASS, buf, 64);
  return String(buf);
}

String eepromReadDeviceId() {
  char buf[64];
  eepromReadString(ADDR_DEVICE_ID, buf, 64);
  return String(buf);
}

// ═════════════════════════════════════════════════════════════
// SETUP
// ═════════════════════════════════════════════════════════════
void setup()
{
  Serial.begin(9600);
  EEPROM.begin(EEPROM_SIZE);
  
  lcd.init();
  lcd.backlight();
  lcd.begin(16, 2);
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("  RFID Attendance  ");
  lcd.setCursor(0, 1);
  lcd.print("   Initializing... ");
  
  pinMode(BUZZER, OUTPUT);
  SPI.begin();

  // ─── Step 1: Coba WiFi dari EEPROM ──────────────────────────
  String savedSSID = eepromReadSSID();
  String savedPass = eepromReadPass();
  current_device_id = eepromReadDeviceId();

  Serial.println();
  Serial.print(F("EEPROM valid: "));
  Serial.println(isEepromValid() ? "YES" : "NO");
  Serial.print(F("Saved SSID: '"));
  Serial.print(savedSSID);
  Serial.println("'");
  Serial.print(F("Device ID: '"));
  Serial.print(current_device_id);
  Serial.println("'");
  
  bool connected = false;

  if (isEepromValid() && savedSSID.length() > 0) {
    Serial.print(F("Connecting to EEPROM WiFi: "));
    Serial.println(savedSSID);
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Connect: ");
    lcd.setCursor(0, 1);
    lcd.print(savedSSID.substring(0, 16));

    WiFi.mode(WIFI_STA);
    WiFi.begin(savedSSID.c_str(), savedPass.c_str());

    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 40) {
      delay(500);
      Serial.print(".");
      attempts++;
    }
    Serial.println();

    if (WiFi.status() == WL_CONNECTED) {
      connected = true;
      Serial.println(F("WiFi EEPROM connected!"));
    } else {
      Serial.println(F("EEPROM WiFi failed"));
    }
  }

  // ─── Step 2: Fallback WiFi hardcoded ────────────────────────
  if (!connected) {
    Serial.println(F("Trying hardcoded WiFi..."));
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Fallback WiFi...");

    WiFi.begin("TYO", "SCORPIO2510#");
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
      delay(500);
      Serial.print(".");
      attempts++;
    }
    Serial.println();

    if (WiFi.status() == WL_CONNECTED) {
      connected = true;
      Serial.println(F("Hardcoded WiFi connected!"));
      // Simpan ke EEPROM agar next boot langsung connect
      String fallbackDeviceId = current_device_id.length() > 0 ? current_device_id : "";
      saveWifiToEeprom("TYO", "SCORPIO2510#", fallbackDeviceId);
    }
  }

  // ─── Step 3: Jika masih gagal → AP Mode dengan WiFiManager ──
  if (!connected) {
    Serial.println(F("Starting WiFiManager AP Mode..."));
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Setup WiFi Mode");
    lcd.setCursor(0, 1);
    lcd.print("AP: ESP-RFID-XXXX");

    WiFiManager wm;
    wm.setTitle("Konfigurasi WiFi ESP");
    wm.setConfigPortalTimeout(300); // 5 menit timeout

    // AP name dari MAC
    String apName = "ESP-RFID-" + WiFi.macAddress().substring(12);
    apName.replace(":", "");

    // Custom field untuk Device ID
    WiFiManagerParameter deviceParam("device_id", "Device ID (misal: ALAT-01)", current_device_id.c_str(), 50);
    wm.addParameter(&deviceParam);

    if (wm.autoConnect(apName.c_str())) {
      // WiFiManager berhasil — simpan config
      String newSSID = wm.getWiFiSSID();
      String newPass = wm.getWiFiPass();
      String newDeviceId = String(deviceParam.getValue());
      newDeviceId.trim();

      saveWifiToEeprom(newSSID, newPass, newDeviceId);
      current_device_id = newDeviceId;

      Serial.println(F("WiFi saved via WiFiManager!"));
      Serial.println("SSID: " + newSSID);
      Serial.println("Device ID: " + newDeviceId);

      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("WiFi Disimpan!");
      lcd.setCursor(0, 1);
      lcd.print("Rebooting...");
      delay(2000);
      ESP.restart();
    } else {
      Serial.println(F("WiFiManager timeout"));
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("Setup Timeout");
      lcd.setCursor(0, 1);
      lcd.print("Reboot to retry");
      delay(5000);
      ESP.restart();
    }
  }

  // ─── WiFi Connected! ─────────────────────────────────────────
  Serial.println();
  Serial.println(F("=== WiFi Connected ==="));
  Serial.print(F("IP: "));
  Serial.println(WiFi.localIP());
  Serial.print(F("MAC: "));
  Serial.println(WiFi.macAddress());
  Serial.print(F("Device ID: "));
  Serial.println(current_device_id);

  // Daftar ke server
  doDeviceRegister();

  // HIDUP: Buzzer 2x tanda siap
  digitalWrite(BUZZER, HIGH);
  delay(150);
  digitalWrite(BUZZER, LOW);
  delay(100);
  digitalWrite(BUZZER, HIGH);
  delay(150);
  digitalWrite(BUZZER, LOW);

  mfrc522.PCD_Init();
}

// ═════════════════════════════════════════════════════════════
// LOOP
// ═════════════════════════════════════════════════════════════
void loop()
{
  // ─── Config Polling (setiap 15 detik) ──────────────────────
  if (millis() - lastConfigPoll >= CONFIG_POLL_INTERVAL) {
    pollDeviceConfig();
    lastConfigPoll = millis();
  }

  // ─── Cooldown RFID ─────────────────────────────────────────
  if (millis() - lastScanTime < SCAN_COOLDOWN) {
    return;
  }

  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(" Scan your Card ");
  mfrc522.PCD_Init();

  if (!mfrc522.PICC_IsNewCardPresent()) { return; }
  if (!mfrc522.PICC_ReadCardSerial()) { return; }

  Serial.println();
  Serial.println(F("Reading last data from RFID..."));
  bool readOk = ReadDataFromBlock(blockNum, readBlockData);

  if (!readOk) {
    Serial.println(F("Gagal baca Block 2, fallback ke UID fisik"));
  }

  // Print Block 2 HEX
  Serial.println();
  Serial.print(F("Block "));
  Serial.print(blockNum);
  Serial.print(F(" HEX: "));
  for (int j = 0; j < 16; j++) {
    if (readBlockData[j] < 0x10) Serial.print("0");
    Serial.print(readBlockData[j], HEX);
    Serial.print(" ");
  }
  Serial.println();

  // Print Block 2 ASCII
  Serial.print(F("Block "));
  Serial.print(blockNum);
  Serial.print(F(" ASCII: ["));
  for (int j = 0; j < 16; j++) {
    if (readBlockData[j] >= 32 && readBlockData[j] < 127)
      Serial.write(readBlockData[j]);
    else
      Serial.print(".");
  }
  Serial.println("]");

  // UID fisik
  String uidFisik = getUidFisik();
  Serial.print(F("UID Fisik: "));
  Serial.println(uidFisik);

  // Buzzer beep
  digitalWrite(BUZZER, HIGH);
  delay(200);
  digitalWrite(BUZZER, LOW);
  delay(200);
  digitalWrite(BUZZER, HIGH);
  delay(200);
  digitalWrite(BUZZER, LOW);

  if (WiFi.status() == WL_CONNECTED) {
    // Cek mode dari server
    bool isRegisterMode = checkRegisterMode();

    if (isRegisterMode) {
      // ═══════ MODE REGISTER ═══════
      String tokenKartu = "";
      if (readOk && !isBlockEmpty(readBlockData)) {
        tokenKartu = String((char*)readBlockData);
        tokenKartu.trim();
      }

      Serial.println(F("=== MODE REGISTER ==="));
      Serial.print(F("UID Fisik: "));
      Serial.println(uidFisik);
      Serial.print(F("Token: "));
      Serial.println(tokenKartu);

      if (sendRegisterData(uidFisik, tokenKartu)) {
        Serial.println(F("Register data terkirim!"));
        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print("MODE REGISTER");
        lcd.setCursor(0, 1);
        lcd.print("Data dikirim!");
      } else {
        Serial.println(F("Register gagal kirim"));
        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print("MODE REGISTER");
        lcd.setCursor(0, 1);
        lcd.print("Gagal kirim...");
      }
    } else {
      // ═══════ MODE ABSEN ═══════
      // Selalu kirim UID FISIK — token Block 2 TIDAK dipakai untuk absensi
      Serial.print(F("UID fisik dikirim ke absensi: "));
      Serial.println(uidFisik);

      card_holder_name = legacy_url_prefix + urlEncode(uidFisik);
      Serial.print(F("URL: "));
      Serial.println(card_holder_name);

      String payload;
      int httpCode = 0;
      bool success = doHttpsRequest(card_holder_name, payload, httpCode);

      if (success && httpCode == 200) {
        Serial.println(payload);

        DynamicJsonDocument doc(1024);
        deserializeJson(doc, payload);

        String name = doc["nama"].as<String>();
        String absenStatus = doc["status"].as<String>();

        lcd.clear();
        lcd.setCursor(0, 0);
        if (absenStatus == "IN") {
          lcd.print("Selamat Datang,");
        } else if (absenStatus == "OUT") {
          lcd.print("Sampai Jumpa,");
        } else {
          lcd.print("Welcome,");
        }
        lcd.setCursor(0, 1);
        lcd.print(name);

      } else {
        Serial.printf("[HTTPS] failed, code: %d\n", httpCode);
        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print("Error:");
        lcd.setCursor(0, 1);
        lcd.print("HTTPS failed");
      }
    }
    delay(3000);
  }

  lastScanTime = millis();
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Daftar MAC address ke server
// ═════════════════════════════════════════════════════════════
void doDeviceRegister() {
  if (WiFi.status() != WL_CONNECTED) return;

  String url = device_register_url + "?mac=" + WiFi.macAddress();
  if (current_device_id.length() > 0) {
    url += "&did=" + urlEncode(current_device_id);
  }

  String payload;
  int httpCode = 0;
  if (doHttpsRequest(url, payload, httpCode) && httpCode == 200) {
    DynamicJsonDocument doc(512);
    if (deserializeJson(doc, payload) == DeserializationError::Ok) {
      if (doc.containsKey("device_id") && !doc["device_id"].isNull()) {
        String serverDeviceId = doc["device_id"].as<String>();
        if (serverDeviceId.length() > 0 && serverDeviceId != current_device_id) {
          current_device_id = serverDeviceId;
          // Simpan device ID dari server ke EEPROM
          String ssid = eepromReadSSID();
          String pass = eepromReadPass();
          saveWifiToEeprom(ssid, pass, current_device_id);
          Serial.print(F("Device ID updated from server: "));
          Serial.println(current_device_id);
        }
      }
      Serial.print(F("Register response: "));
      Serial.println(payload);
    }
  } else {
    Serial.println(F("Device register failed (will retry on poll)"));
  }
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Poll device config dari server
// ═════════════════════════════════════════════════════════════
void pollDeviceConfig() {
  if (WiFi.status() != WL_CONNECTED) return;

  String url = device_config_url + "?mac=" + WiFi.macAddress();

  String payload;
  int httpCode = 0;
  if (!doHttpsRequest(url, payload, httpCode) || httpCode != 200) {
    return;
  }

  DynamicJsonDocument doc(1024);
  if (deserializeJson(doc, payload) != DeserializationError::Ok) {
    return;
  }

  // Jika belum registered, coba register
  if (doc.containsKey("registered") && doc["registered"] == false) {
    doDeviceRegister();
    return;
  }

  // ─── Cek WiFi Pending ─────────────────────────────────────────
  if (doc.containsKey("wifi_pending") && doc["wifi_pending"].as<bool>()) {
    String newSSID = doc["wifi_ssid"].as<String>();
    String newPass = doc["wifi_password"].as<String>();
    int newVersion = doc["config_version"].as<int>();

    if (newVersion > lastConfigVersion && newSSID.length() > 0) {
      Serial.println("========================================");
      Serial.print(F("WiFi CHANGE DETECTED! New SSID: "));
      Serial.println(newSSID);
      Serial.print(F("New Version: "));
      Serial.println(newVersion);
      Serial.println("========================================");

      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("  WiFi Change!  ");
      lcd.setCursor(0, 1);
      lcd.print(newSSID.substring(0, 16));

      saveWifiToEeprom(newSSID, newPass, current_device_id);
      delay(1500);
      Serial.println(F("Rebooting to apply new WiFi..."));
      ESP.restart();
    }
  }

  // ─── Cek Scan Command ─────────────────────────────────────────
  if (doc.containsKey("scan_wifi") && doc["scan_wifi"].as<bool>()) {
    Serial.println(F("Scan WiFi command received — scanning..."));
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Scanning WiFi...");
    lcd.setCursor(0, 1);
    lcd.print("Please wait...");

    // Scan networks (briefly disconnects WiFi, auto-reconnects)
    int n = WiFi.scanNetworks();
    Serial.print(F("Scan complete: "));
    Serial.print(n);
    Serial.println(F(" networks found"));

    if (n > 0) {
      // Bangun JSON hasil scan
      // Format: {"mac_address":"XX:XX:XX:XX:XX","results":[{"ssid":"...","rssi":-45,"encr":"WPA2"},...]}
      // Estimasi: setiap network ~60 byte JSON
      size_t jsonSize = 256 + (n * 80);
      DynamicJsonDocument scanDoc(jsonSize);

      scanDoc["mac_address"] = WiFi.macAddress();
      JsonArray results = scanDoc.createNestedArray("results");

      for (int i = 0; i < n; i++) {
        JsonObject net = results.createNestedObject();
        net["ssid"] = WiFi.SSID(i);
        net["rssi"] = WiFi.RSSI(i);
        // encryptionType: ENC_TYPE_NONE=Open, ENC_TYPE_TKIP=WPA, ENC_TYPE_CCMP=WPA2, ENC_TYPE_AUTO=Auto
        uint8_t encType = WiFi.encryptionType(i);
        if (encType == ENC_TYPE_NONE) {
          net["encr"] = "Open";
        } else if (encType == ENC_TYPE_TKIP) {
          net["encr"] = "WPA";
        } else {
          net["encr"] = "WPA2";
        }
      }

      WiFi.scanDelete();

      String finalPayload;
      serializeJson(scanDoc, finalPayload);

      // POST hasil scan ke server
      std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
      client->setInsecure();
      HTTPClient http;
      if (http.begin(*client, device_scan_report_url)) {
        http.addHeader("Content-Type", "application/json");
        http.setTimeout(15000);
        int postCode = http.POST(finalPayload);
        Serial.print(F("Scan report POST code: "));
        Serial.println(postCode);
        http.end();
      }

      Serial.println(F("Scan results sent to server"));
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("Scan Complete!");
      lcd.setCursor(0, 1);
      lcd.print(String(n) + " networks found");
      delay(2000);
    } else {
      WiFi.scanDelete();
      Serial.println(F("No networks found"));
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("No WiFi found");
      delay(1500);
    }
  }

  // ─── Update config version ─────────────────────────────────────
  if (doc.containsKey("config_version")) {
    lastConfigVersion = doc["config_version"].as<int>();
  }

  // ─── Update device ID dari server jika ada ─────────────────────
  if (doc.containsKey("device_id") && !doc["device_id"].isNull()) {
    String serverDeviceId = doc["device_id"].as<String>();
    if (serverDeviceId.length() > 0 && serverDeviceId != current_device_id) {
      current_device_id = serverDeviceId;
      String ssid = eepromReadSSID();
      String pass = eepromReadPass();
      saveWifiToEeprom(ssid, pass, current_device_id);
      Serial.print(F("Device ID sync from poll: "));
      Serial.println(current_device_id);
    }
  }
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Cek mode register dari server
// ═════════════════════════════════════════════════════════════
bool checkRegisterMode() {
  String payload;
  int httpCode = 0;

  if (!doHttpsRequest(mode_url, payload, httpCode) || httpCode != 200) {
    return false;
  }

  DynamicJsonDocument doc(256);
  DeserializationError error = deserializeJson(doc, payload);

  if (error) {
    Serial.print(F("JSON parse error mode: "));
    Serial.println(error.c_str());
    return false;
  }

  String mode = doc["mode"].as<String>();
  Serial.print(F("Server mode: "));
  Serial.println(mode);

  return (mode == "register");
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Kirim data registrasi ke server (POST JSON)
// ═════════════════════════════════════════════════════════════
bool sendRegisterData(const String &uidFisik, const String &tokenKartu) {
  String payload = "{\"uid_fisik\":\"" + uidFisik + "\",\"token_kartu\":\"" + tokenKartu + "\"}";

  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  client->setInsecure();

  HTTPClient http;
  if (!http.begin(*client, register_url)) {
    Serial.println(F("[HTTPS] begin register failed"));
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  http.setTimeout(15000);

  int httpCode = http.POST(payload);

  if (httpCode > 0) {
    String response = http.getString();
    Serial.print(F("[HTTPS] Register POST code: "));
    Serial.println(httpCode);
    Serial.print(F("Response: "));
    Serial.println(response);
    http.end();
    return (httpCode == 200);
  } else {
    Serial.printf("[HTTPS] Register POST failed: %s\n", http.errorToString(httpCode).c_str());
    http.end();
    return false;
  }
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Baca data dari block
// ═════════════════════════════════════════════════════════════
bool ReadDataFromBlock(int bNum, byte readBlockData[])
{
  for (byte i = 0; i < 6; i++) {
    key.keyByte[i] = 0xFF;
  }

  rfid_status = mfrc522.PCD_Authenticate(MFRC522::PICC_CMD_MF_AUTH_KEY_A, bNum, &key, &(mfrc522.uid));
  if (rfid_status != MFRC522::STATUS_OK) {
    Serial.print("Authentication failed for Read: ");
    Serial.println(mfrc522.GetStatusCodeName(rfid_status));
    return false;
  } else {
    Serial.println("Authentication success");
  }

  rfid_status = mfrc522.MIFARE_Read(bNum, readBlockData, &bufferLen);
  if (rfid_status != MFRC522::STATUS_OK) {
    Serial.print("Reading failed: ");
    Serial.println(mfrc522.GetStatusCodeName(rfid_status));
    return false;
  } else {
    Serial.println("Block was read successfully");
  }
  return true;
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: HTTPS GET helper
// ═════════════════════════════════════════════════════════════
bool doHttpsRequest(const String &url, String &responsePayload, int &httpCode) {
  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  client->setInsecure();
  client->setTimeout(15000);

  HTTPClient http;

  Serial.print(F("[HTTPS] begin...\n"));
  if (!http.begin(*client, url)) {
    Serial.println(F("[HTTPS] begin() failed"));
    return false;
  }

  http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
  http.setTimeout(15000);
  http.useHTTP10(true);

  Serial.print(F("[HTTPS] GET...\n"));
  httpCode = http.GET();

  if (httpCode > 0) {
    Serial.printf("[HTTPS] GET... code: %d\n", httpCode);
    responsePayload = http.getString();
    http.end();
    return true;
  }

  Serial.printf("[HTTPS] GET... failed, error: %s\n", http.errorToString(httpCode).c_str());
  http.end();
  return false;
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: UID fisik dari chip
// ═════════════════════════════════════════════════════════════
String getUidFisik() {
  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(mfrc522.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  uid.trim();
  return uid;
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Cek block kosong
// ═════════════════════════════════════════════════════════════
bool isBlockEmpty(byte blockData[]) {
  for (int j = 0; j < 16; j++) {
    if (blockData[j] != 0x00 && blockData[j] != 0xFF) {
      return false;
    }
  }
  return true;
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: URL Encode
// ═════════════════════════════════════════════════════════════
String urlEncode(const String &value) {
  String encoded = "";
  char c;
  char buf[4];

  for (unsigned int i = 0; i < value.length(); i++) {
    c = value.charAt(i);
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded += c;
    } else {
      snprintf(buf, sizeof(buf), "%%%02X", (unsigned char)c);
      encoded += buf;
    }
  }

  return encoded;
}
