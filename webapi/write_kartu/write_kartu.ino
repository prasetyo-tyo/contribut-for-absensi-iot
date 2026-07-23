#include <SPI.h>
#include <MFRC522.h>

#define RST_PIN  D3
#define SS_PIN   D4

MFRC522 mfrc522(SS_PIN, RST_PIN);
MFRC522::MIFARE_Key key;
MFRC522::StatusCode status;

// ============================================================
// KONFIGURASI - Sesuaikan dengan UID fisik kartu!
// ============================================================
// Cara dapat UID fisik: tap kartu di serial monitor scan.ino
// atau jalankan firmware ini, nanti ditampilkan di Serial.
//
// UID fisik kartu TYO: D005A55F
// ============================================================

int blockNum = 2;  // Block tempat token disimpan

void setup() {
  Serial.begin(9600);
  SPI.begin();
  mfrc522.PCD_Init();
  
  Serial.println();
  Serial.println(F("=================================="));
  Serial.println(F("   WRITE TOKEN KE BLOCK 2 KARTU"));
  Serial.println(F("=================================="));
  Serial.println(F("Tap kartu di RFID reader..."));
  Serial.println();
}

void loop() {
  // Cari kartu baru
  if (!mfrc522.PICC_IsNewCardPresent()) return;
  if (!mfrc522.PICC_ReadCardSerial()) return;

  Serial.print(F("UID Fisik: "));
  String uidFisik = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) Serial.print("0");
    Serial.print(mfrc522.uid.uidByte[i], HEX);
    uidFisik += String(mfrc522.uid.uidByte[i], HEX);
  }
  uidFisik.toUpperCase();
  Serial.println();

  // ============================================================
  // ISI TOKEN YANG MAU DITULIS KE KARTU
  // ============================================================
  // Token ini nantinya disimpan di database kolom uid atau token_kartu
  // Cocokkan dengan data karyawan di web admin.
  //
  // Contoh: pakai UID fisik sebagai token (biar gampang)
  String tokenToWrite = uidFisik;  // <-- GANTI INI KALAU MAU TOKEN LAIN
  // ============================================================

  Serial.print(F("Token yang akan ditulis: "));
  Serial.println(tokenToWrite);

  // Siapkan autentikasi (default key FFFFFFFFFFFF)
  for (byte i = 0; i < 6; i++) key.keyByte[i] = 0xFF;

  // Autentikasi Block 2
  status = mfrc522.PCD_Authenticate(MFRC522::PICC_CMD_MF_AUTH_KEY_A, blockNum, &key, &(mfrc522.uid));
  if (status != MFRC522::STATUS_OK) {
    Serial.print(F("Auth gagal: "));
    Serial.println(mfrc522.GetStatusCodeName(status));
    mfrc522.PICC_HaltA();
    return;
  }
  Serial.println(F("Auth sukses!"));

  // Siapkan buffer 16 byte
  byte dataBlock[16];
  memset(dataBlock, 0, sizeof(dataBlock));

  // Copy token ke buffer, max 16 karakter
  int len = tokenToWrite.length();
  if (len > 16) len = 16;  // Block 2 cuma muat 16 byte
  for (int i = 0; i < len; i++) {
    dataBlock[i] = tokenToWrite[i];
  }

  // Tulis ke Block 2
  status = mfrc522.MIFARE_Write(blockNum, dataBlock, 16);
  if (status != MFRC522::STATUS_OK) {
    Serial.print(F("Gagal nulis: "));
    Serial.println(mfrc522.GetStatusCodeName(status));
  } else {
    Serial.println(F("✅ BERHASIL! Token tertulis ke Block 2!"));
  }

  // Baca balik untuk verifikasi
  Serial.println(F("\nVerifikasi baca balik Block 2:"));
  byte buffer[18];
  byte bufferLen = 18;
  status = mfrc522.MIFARE_Read(blockNum, buffer, &bufferLen);
  if (status == MFRC522::STATUS_OK) {
    Serial.print(F("HEX: "));
    for (byte i = 0; i < 16; i++) {
      if (buffer[i] < 0x10) Serial.print("0");
      Serial.print(buffer[i], HEX);
      Serial.print(" ");
    }
    Serial.println();
    Serial.print(F("STR: "));
    for (byte i = 0; i < 16; i++) {
      if (buffer[i] >= 32 && buffer[i] < 127)
        Serial.write(buffer[i]);
      else
        Serial.print(".");
    }
    Serial.println();
  }

  Serial.println(F("\nSelesai! Lepas kartu..."));

  mfrc522.PICC_HaltA();
  delay(3000);
}
