/*
 * read_block2.ino
 * Sketch minimal untuk membaca Block 2 kartu MIFARE
 * Tanpa WiFi, tanpa HTTP, tanpa LCD — hanya Serial Monitor
 */
// TEST UPLOAD BARU

#include <SPI.h>
#include <MFRC522.h>

#define RST_PIN  D3
#define SS_PIN   D4

MFRC522 mfrc522(SS_PIN, RST_PIN);
MFRC522::MIFARE_Key key;
MFRC522::StatusCode status;

int blockNum = 2;
byte bufferLen = 18;
byte readBlockData[18];

void setup() {
  Serial.begin(9600);
  SPI.begin();
  mfrc522.PCD_Init();
  Serial.println(F("=== READ BLOCK 2 ==="));
  Serial.println(F("Tap kartu ke sensor..."));
}

void loop() {
  // Cari kartu baru
  if (!mfrc522.PICC_IsNewCardPresent()) {
    return;
  }
  if (!mfrc522.PICC_ReadCardSerial()) {
    return;
  }

  Serial.println();
  Serial.println(F("Kartu terdeteksi!"));
  Serial.print(F("UID: "));
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) Serial.print("0");
    Serial.print(mfrc522.uid.uidByte[i], HEX);
    Serial.print(" ");
  }
  Serial.println();

  // Setup key (default factory key FFFFFFFFFFFF)
  for (byte i = 0; i < 6; i++) {
    key.keyByte[i] = 0xFF;
  }

  // Authenticate Block 2
  status = mfrc522.PCD_Authenticate(MFRC522::PICC_CMD_MF_AUTH_KEY_A, blockNum, &key, &(mfrc522.uid));
  if (status != MFRC522::STATUS_OK) {
    Serial.print(F("AUTH FAILED: "));
    Serial.println(mfrc522.GetStatusCodeName(status));
    delay(1000);
    return;
  }
  Serial.println(F("AUTH OK"));

  // Read Block 2
  status = mfrc522.MIFARE_Read(blockNum, readBlockData, &bufferLen);
  if (status != MFRC522::STATUS_OK) {
    Serial.print(F("READ FAILED: "));
    Serial.println(mfrc522.GetStatusCodeName(status));
    delay(1000);
    return;
  }
  Serial.println(F("READ OK"));

  // === PRINT HEX ===
  Serial.print(F("Block 2 HEX: "));
  for (int j = 0; j < 16; j++) {
    if (readBlockData[j] < 0x10) Serial.print("0");
    Serial.print(readBlockData[j], HEX);
    Serial.print(" ");
  }
  Serial.println();

  // === PRINT RAW ===
  Serial.print(F("Block 2 RAW: "));
  for (int j = 0; j < 16; j++) {
    Serial.print((int)readBlockData[j]);
    Serial.print(" ");
  }
  Serial.println();

  // === PRINT ASCII ===
  Serial.print(F("Block 2 STR: ["));
  for (int j = 0; j < 16; j++) {
    if (readBlockData[j] >= 32 && readBlockData[j] < 127)
      Serial.write(readBlockData[j]);
    else
      Serial.print(".");
  }
  Serial.println("]");

  Serial.println(F("--- Selesai, tap kartu lain ---"));
  delay(2000);
}
