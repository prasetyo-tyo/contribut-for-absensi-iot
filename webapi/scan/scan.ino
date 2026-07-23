/**
 * scan.ino — Firmware ESP8266 + RC522 DUAL-MODE
 * 
 * MODE ABSEN (default):
 *   Tap kartu → create_legacy.php → absensi IN/OUT
 * 
 * MODE REGISTER (aktif otomatis saat admin buka form Tambah Karyawan):
 *   Tap kartu → scan-register.php → form auto-fill UID + Token
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

// ─── Pin Configuration ─────────────────────────────────────
#define RST_PIN  D3
#define SS_PIN   D4
#define BUZZER   D8

// ─── RFID Reader ───────────────────────────────────────────
MFRC522 mfrc522(SS_PIN, RST_PIN);
MFRC522::MIFARE_Key key;
MFRC522::StatusCode rfid_status;  // renamed to avoid conflict

int blockNum = 2;
byte bufferLen = 18;
byte readBlockData[18];

// ─── WiFi ──────────────────────────────────────────────────
#define WIFI_SSID     "SBN1"
#define WIFI_PASSWORD "17171717#"

// ─── Server URLs ───────────────────────────────────────────
const String legacy_url = "https://sbn-absensi.bakmibangkaasli17.com/webapi/api/create_legacy.php?uid=";
const String register_url = "https://sbn-absensi.bakmibangkaasli17.com/webapi/api/scan-register.php";
const String mode_url = "https://sbn-absensi.bakmibangkaasli17.com/webapi/api/register-mode.php?check=1";

// ─── LCD ───────────────────────────────────────────────────
LiquidCrystal_I2C lcd(0x27, 16, 2);

// ─── Cooldown ──────────────────────────────────────────────
unsigned long lastScanTime = 0;
const unsigned long SCAN_COOLDOWN = 5000; // 5 detik

String card_holder_name;

// ─── Forward Declarations ──────────────────────────────────
String getUidFisik();
bool isBlockEmpty(byte blockData[]);
bool ReadDataFromBlock(int bNum, byte readBlockData[]);
String urlEncode(const String &value);
bool doHttpsRequest(const String &url, String &responsePayload, int &httpCode);
bool checkRegisterMode();
bool sendRegisterData(const String &uidFisik, const String &tokenKartu);

// ═════════════════════════════════════════════════════════════
// SETUP
// ═════════════════════════════════════════════════════════════
void setup()
{
  Serial.begin(9600);
  lcd.init();
  lcd.backlight();
  lcd.begin(16, 2);
  lcd.clear();
  lcd.setCursor(0, 1);
  lcd.print("  Initializing  ");
  for (int a = 5; a <= 10; a++) {
    lcd.setCursor(a, 1);
    lcd.print(".");
    delay(500);
  }
  
  Serial.println();
  Serial.print("Connecting to AP");
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  while (WiFi.status() != WL_CONNECTED) {
    Serial.print(".");
    delay(200);
  }
  Serial.println("");
  Serial.println("WiFi connected.");
  Serial.println("IP address: ");
  Serial.println(WiFi.localIP());
  Serial.println();
  
  pinMode(BUZZER, OUTPUT);
  SPI.begin();
}

// ═════════════════════════════════════════════════════════════
// LOOP
// ═════════════════════════════════════════════════════════════
void loop()
{
  // Cooldown check
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
      String uidParam;
      if (readOk && !isBlockEmpty(readBlockData)) {
        uidParam = String((char*)readBlockData);
        uidParam.trim();
        Serial.print(F("Block 2 ada data, mengirim: "));
      } else {
        uidParam = uidFisik;
        Serial.print(F("Block 2 kosong, fallback UID fisik: "));
      }
      Serial.println(uidParam);

      card_holder_name = legacy_url + urlEncode(uidParam);
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
