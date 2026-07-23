/**
 * scan_register.ino
 * 
 * Firmware ESP8266 + RC522 dalam MODE READER untuk registrasi karyawan.
 * 
 * ALUR:
 * 1. ESP membaca kartu RFID (UID fisik dari Block 0, token dari Block 2)
 * 2. Kirim data ke scan-register.php via HTTPS POST
 * 3. LCD menampilkan "Kartu Terbaca!" + UID
 * 4. Web form (data_karyawan-create.php) poll scan-poll.php dan auto-fill
 * 
 * COLOK KARTU → LCD terisi → Form terisi otomatis!
 * 
 * WIRING RC522 → ESP8266:
 *   SDA  → D4
 *   SCK  → D5
 *   MOSI → D7
 *   MISO → D6
 *   RST  → D3
 *   3.3V → 3.3V
 *   GND  → GND
 * 
 * LCD I2C:
 *   SDA → D2
 *   SCL → D1
 *   VCC → 5V
 *   GND → GND
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
MFRC522::StatusCode status;

// Block 2 untuk membaca token kartu
int blockNum = 2;
byte bufferLen = 18;
byte readBlockData[18];

// ─── WiFi ──────────────────────────────────────────────────
#define WIFI_SSID     "SBN1"
#define WIFI_PASSWORD "17171717#"

// ─── Server URL ────────────────────────────────────────────
// URL endpoint scan-register.php (POST JSON)
const String register_url = "https://sbn-absensi.bakmibangkaasli17.com/webapi/api/scan-register.php";

// ─── LCD ───────────────────────────────────────────────────
LiquidCrystal_I2C lcd(0x27, 16, 2);

// ─── Timing ────────────────────────────────────────────────
unsigned long lastScanTime = 0;
const unsigned long SCAN_COOLDOWN = 5000; // 5 detik cooldown antar scan

// ─── Forward Declarations ──────────────────────────────────
String getUidFisik();
bool isBlockEmpty(byte blockData[]);
bool ReadDataFromBlock(int blockNum, byte readBlockData[]);
String urlEncode(const String &value);
bool sendToServer(const String &uidFisik, const String &tokenKartu);
void beepBuzzer(int times, int durationMs);

// ═════════════════════════════════════════════════════════════
// SETUP
// ═════════════════════════════════════════════════════════════
void setup() {
  Serial.begin(9600);
  
  // LCD init
  lcd.init();
  lcd.backlight();
  lcd.begin(16, 2);
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("MODE: READER");
  lcd.setCursor(0, 1);
  lcd.print("Initializing...");
  
  // WiFi connect
  Serial.println();
  Serial.println("=== RFID REGISTER READER ===");
  Serial.print("Connecting to WiFi");
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  while (WiFi.status() != WL_CONNECTED) {
    Serial.print(".");
    delay(200);
  }
  Serial.println("");
  Serial.println("WiFi connected!");
  Serial.print("IP: ");
  Serial.println(WiFi.localIP());
  
  // Buzzer
  pinMode(BUZZER, OUTPUT);
  
  // SPI + RC522
  SPI.begin();
  mfrc522.PCD_Init();
  mfrc522.PCD_DumpVersionToSerial();
  
  // Set default key
  for (byte i = 0; i < 6; i++) {
    key.keyByte[i] = 0xFF;
  }
  
  // Tampilan siap
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Siap Membaca");
  lcd.setCursor(0, 1);
  lcd.print("Tap Kartu...");
  
  Serial.println("Reader siap. Tap kartu untuk registrasi.");
}

// ═════════════════════════════════════════════════════════════
// LOOP
// ═════════════════════════════════════════════════════════════
void loop() {
  // Cek cooldown
  if (millis() - lastScanTime < SCAN_COOLDOWN) {
    return;
  }
  
  // Reset loop kalau tidak ada kartu baru
  if (!mfrc522.PICC_IsNewCardPresent()) {
    return;
  }
  
  // Select kartu
  if (!mfrc522.PICC_ReadCardSerial()) {
    return;
  }
  
  // ─── Baca UID Fisik dari Block 0 ───────────────────────
  String uidFisik = getUidFisik();
  Serial.println("==================================");
  Serial.print("UID Fisik: ");
  Serial.println(uidFisik);
  
  // ─── Baca Token dari Block 2 ──────────────────────────
  String tokenKartu = "";
  byte block2Data[18];
  byte size = 18;
  
  status = mfrc522.MIFARE_Read(blockNum, block2Data, &size);
  if (status == MFRC522::STATUS_OK) {
    // Convert block data to string
    String rawToken = "";
    for (byte i = 0; i < 16; i++) {
      if (block2Data[i] != 0) {
        rawToken += (char)block2Data[i];
      }
    }
    
    if (rawToken.length() > 0) {
      tokenKartu = rawToken;
      Serial.print("Token Block 2: ");
      Serial.println(tokenKartu);
    } else {
      Serial.println("Block 2 kosong (all zeros)");
    }
  } else {
    Serial.print("Gagal baca Block 2: ");
    Serial.println(mfrc522.GetStatusCodeName(status));
  }
  
  // ─── Tampilkan di LCD ─────────────────────────────────
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("KARTU TERBACA!");
  lcd.setCursor(0, 1);
  lcd.print(uidFisik.substring(0, 16));
  
  // Beep 2x untuk indikasi scan berhasil
  beepBuzzer(2, 100);
  
  // ─── Kirim ke Server ──────────────────────────────────
  if (sendToServer(uidFisik, tokenKartu)) {
    Serial.println("✓ Data terkirim ke server!");
    
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("DATA TERKIRIM");
    lcd.setCursor(0, 1);
    if (tokenKartu.length() > 0) {
      lcd.print("T:" + tokenKartu.substring(0, 13));
    } else {
      lcd.print("UID Only");
    }
  } else {
    Serial.println("✗ Gagal kirim ke server");
    
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("GAGAL KIRIM");
    lcd.setCursor(0, 1);
    lcd.print("Coba lagi...");
  }
  
  // ─── Halt kartu ───────────────────────────────────────
  mfrc522.PICC_HaltA();
  mfrc522.PCD_StopCrypto1();
  
  // Update cooldown
  lastScanTime = millis();
  
  // Delay sebelum kembali ke mode siap
  delay(3000);
  
  // Kembali ke tampilan siap
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Siap Membaca");
  lcd.setCursor(0, 1);
  lcd.print("Tap Kartu...");
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Kirim data ke server via HTTPS POST
// ═════════════════════════════════════════════════════════════
bool sendToServer(const String &uidFisik, const String &tokenKartu) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi tidak terhubung!");
    return false;
  }
  
  // Buat JSON payload
  StaticJsonDocument<512> doc;
  doc["uid_fisik"] = uidFisik;
  doc["token_kartu"] = tokenKartu;
  
  String payload;
  serializeJson(doc, payload);
  
  Serial.print("Payload: ");
  Serial.println(payload);
  
  // HTTPS POST (setInsecure karena self-signed / CA bundle issue)
  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  client->setInsecure();
  
  HTTPClient https;
  https.begin(*client, register_url);
  https.addHeader("Content-Type", "application/json");
  
  int httpCode = https.POST(payload);
  
  if (httpCode > 0) {
    Serial.print("HTTP Code: ");
    Serial.println(httpCode);
    
    String response = https.getString();
    Serial.print("Response: ");
    Serial.println(response);
    
    https.end();
    
    return (httpCode == 200);
  } else {
    Serial.print("HTTP Error: ");
    Serial.println(https.errorToString(httpCode));
    https.end();
    return false;
  }
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Baca UID fisik dari Block 0
// ═════════════════════════════════════════════════════════════
String getUidFisik() {
  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) {
      uid += "0";
    }
    uid += String(mfrc522.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  return uid;
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Cek apakah block kosong (all zeros)
// ═════════════════════════════════════════════════════════════
bool isBlockEmpty(byte blockData[]) {
  for (byte i = 0; i < 16; i++) {
    if (blockData[i] != 0) {
      return false;
    }
  }
  return true;
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Baca data dari block tertentu
// ═════════════════════════════════════════════════════════════
bool ReadDataFromBlock(int blockNum, byte readBlockData[]) {
  byte bufferLen = 18;
  
  status = mfrc522.MIFARE_Read(blockNum, readBlockData, &bufferLen);
  
  if (status == MFRC522::STATUS_OK) {
    return true;
  } else {
    Serial.print("Read block gagal: ");
    Serial.println(mfrc522.GetStatusCodeName(status));
    return false;
  }
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: URL Encode
// ═════════════════════════════════════════════════════════════
String urlEncode(const String &value) {
  String encoded = "";
  char c;
  char code0, code1;
  for (int i = 0; i < value.length(); i++) {
    c = value.charAt(i);
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded += c;
    } else {
      code1 = (c & 0xf) + '0';
      if ((c & 0xf) > 9) {
        code1 = (c & 0xf) - 10 + 'A';
      }
      code0 = ((c >> 4) & 0xf) + '0';
      if (((c >> 4) & 0xf) > 9) {
        code0 = ((c >> 4) & 0xf) - 10 + 'A';
      }
      code1 = (c & 0xf) + '0';
      if ((c & 0xf) > 9) {
        code1 = (c & 0xf) - 10 + 'A';
      }
      encoded += '%';
      encoded += code0;
      encoded += code1;
    }
  }
  return encoded;
}

// ═════════════════════════════════════════════════════════════
// FUNGSI: Beep buzzer
// ═════════════════════════════════════════════════════════════
void beepBuzzer(int times, int durationMs) {
  for (int i = 0; i < times; i++) {
    digitalWrite(BUZZER, HIGH);
    delay(durationMs);
    digitalWrite(BUZZER, LOW);
    if (i < times - 1) {
      delay(durationMs);
    }
  }
}
