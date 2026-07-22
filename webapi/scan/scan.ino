#include <SPI.h>
#include <MFRC522.h>
#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <LiquidCrystal_I2C.h>
#include <ArduinoJson.h>
//-----------------------------------------
#define RST_PIN  D3
#define SS_PIN   D4
#define BUZZER   D8
//-----------------------------------------
MFRC522 mfrc522(SS_PIN, RST_PIN);
MFRC522::MIFARE_Key key;  
MFRC522::StatusCode status;      
//-----------------------------------------
/* Be aware of Sector Trailer Blocks */
int blockNum = 2;  
/* Create another array to read data from Block */
/* Legthn of buffer should be 2 Bytes more than the size of Block (16 Bytes) */
byte bufferLen = 18;
byte readBlockData[18];
//-----------------------------------------
String card_holder_name;
// HTTPS langsung (server redirect HTTP->HTTPS, HTTPS works)
const String sheet_url = "https://sbn-absensi.bakmibangkaasli17.com/webapi/api/create_legacy.php?uid=";
 
//-----------------------------------------
#define WIFI_SSID "SBN1"           // Ganti dengan SSID WiFi Anda
#define WIFI_PASSWORD "17171717"   // Ganti dengan Password WiFi Anda
//-----------------------------------------

//Initialize the LCD display
LiquidCrystal_I2C lcd(0x27, 16, 2);  //Change LCD Address to 0x27 if 0x3F doesnt work

// ─── Forward declarations ─────────────────────────────────────────
String getUidFisik();
bool isBlockEmpty(byte blockData[]);
bool ReadDataFromBlock(int blockNum, byte readBlockData[]);
String urlEncode(const String &value);
bool doHttpsRequest(const String &url, String &responsePayload, int &httpCode);

/****************************************************************************************************
 * setup() function
 ****************************************************************************************************/
void setup()
{
  //--------------------------------------------------
  /* Initialize serial communications with the PC */
  Serial.begin(9600);
  //Serial.setDebugOutput(true);
  lcd.init();
  lcd.backlight();
  lcd.begin(16,2);
  lcd.clear();
  lcd.setCursor(0, 1);
  lcd.print("  Initializing  ");
  for (int a = 5; a <= 10; a++) {
    lcd.setCursor(a, 1);
    lcd.print(".");
    delay(500);
  }
  
  //--------------------------------------------------
  //WiFi Connectivity
  Serial.println();
  Serial.print("Connecting to AP");
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  while (WiFi.status() != WL_CONNECTED){
    Serial.print(".");
    delay(200);
  }
  Serial.println("");
  Serial.println("WiFi connected.");
  Serial.println("IP address: ");
  Serial.println(WiFi.localIP());
  Serial.println();
  //--------------------------------------------------
  /* Set BUZZER as OUTPUT */
  pinMode(BUZZER, OUTPUT);
  //--------------------------------------------------
  /* Initialize SPI bus */
  SPI.begin();
  //--------------------------------------------------

}




/****************************************************************************************************
 * loop() function
 ****************************************************************************************************/
 void loop()
{
  //--------------------------------------------------
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(" Scan your Card ");
  /* Initialize MFRC522 Module */
  mfrc522.PCD_Init();
  /* Look for new cards */
  /* Reset the loop if no new card is present on RC522 Reader */
  if ( ! mfrc522.PICC_IsNewCardPresent()) {return;}
  /* Select one of the cards */
  if ( ! mfrc522.PICC_ReadCardSerial()) {return;}
  /* Read data from the same block */
  //--------------------------------------------------
  Serial.println();
  Serial.println(F("Reading last data from RFID..."));
  bool readOk = ReadDataFromBlock(blockNum, readBlockData);
  
  if (!readOk) {
    Serial.println(F("Gagal baca Block 2, fallback ke UID fisik"));
  }
  
  /* Print the data read from block in HEX and ASCII */
  Serial.println();
  Serial.print(F("Block "));
  Serial.print(blockNum);
  Serial.print(F(" HEX: "));
  for (int j=0 ; j<16 ; j++)
  {
    if (readBlockData[j] < 0x10) Serial.print("0");
    Serial.print(readBlockData[j], HEX);
    Serial.print(" ");
  }
  Serial.println();
  Serial.print(F("Block "));
  Serial.print(blockNum);
  Serial.print(F(" ASCII: ["));
  for (int j=0 ; j<16 ; j++)
  {
    if (readBlockData[j] >= 32 && readBlockData[j] < 127)
      Serial.write(readBlockData[j]);
    else
      Serial.print(".");
  }
  Serial.println("]");
  Serial.print(F("Block "));
  Serial.print(blockNum);
  Serial.print(F(" raw bytes: "));
  for (int j=0 ; j<16 ; j++)
  {
    Serial.print((int)readBlockData[j]);
    Serial.print(" ");
  }
  Serial.println();
  
  /* UID fisik dari chip */
  String uidFisik = getUidFisik();
  Serial.print(F("UID Fisik: "));
  Serial.println(uidFisik);
  //--------------------------------------------------
  digitalWrite(BUZZER, HIGH);
  delay(200);
  digitalWrite(BUZZER, LOW);
  delay(200);
  digitalWrite(BUZZER, HIGH);
  delay(200);
  digitalWrite(BUZZER, LOW);
  //--------------------------------------------------
  
  //MMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMM
  if (WiFi.status() == WL_CONNECTED) {
    //-----------------------------------------------------------------
    // Fallback: kalau Block 2 kosong atau gagal baca, kirim UID fisik
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
    
    card_holder_name = sheet_url + urlEncode(uidParam);
    Serial.print(F("URL: "));
    Serial.println(card_holder_name);

    //-----------------------------------------------------------------
    // Langsung HTTPS (server redirect HTTP->HTTPS, HTTPS works dari curl)
    String payload;
    int httpCode = 0;
    bool success = doHttpsRequest(card_holder_name, payload, httpCode);
    
    if (success && httpCode == 200) {
      // SUCCESS!
      Serial.println(payload);
      
      // Parse JSON response
      DynamicJsonDocument doc(1024);
      deserializeJson(doc, payload);
      
      String name = doc["nama"].as<String>();
      String status = doc["status"].as<String>();
      
      lcd.clear();
      lcd.setCursor(0, 0);
      if (status == "IN") {
        lcd.print("Selamat Datang,");
      } else if (status == "OUT") {
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
    delay(3000);
  }
  //MMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMM
}




/****************************************************************************************************
 * ReadDataFromBlock() function
 ****************************************************************************************************/
bool ReadDataFromBlock(int blockNum, byte readBlockData[]) 
{ 
  //----------------------------------------------------------------------------
  /* Prepare the key for authentication */
  /* All keys are set to FFFFFFFFFFFFh at chip delivery from the factory */
  for (byte i = 0; i < 6; i++) {
    key.keyByte[i] = 0xFF;
  }
  //----------------------------------------------------------------------------
  /* Authenticating the desired data block for Read access using Key A */
  status = mfrc522.PCD_Authenticate(MFRC522::PICC_CMD_MF_AUTH_KEY_A, blockNum, &key, &(mfrc522.uid));
  //----------------------------------------------------------------------------
  if (status != MFRC522::STATUS_OK){
     Serial.print("Authentication failed for Read: ");
     Serial.println(mfrc522.GetStatusCodeName(status));
     return false;
  }
  //----------------------------------------------------------------------------
  else {
    Serial.println("Authentication success");
  }
  //----------------------------------------------------------------------------
  /* Reading data from the Block */
  status = mfrc522.MIFARE_Read(blockNum, readBlockData, &bufferLen);
  if (status != MFRC522::STATUS_OK) {
    Serial.print("Reading failed: ");
    Serial.println(mfrc522.GetStatusCodeName(status));
    return false;
  }
  //----------------------------------------------------------------------------
  else {
    Serial.println("Block was read successfully");
    Serial.print("Block HEX: ");
    for (int j=0; j<16; j++) {
      if (readBlockData[j] < 0x10) Serial.print("0");
      Serial.print(readBlockData[j], HEX);
      Serial.print(" ");
    }
    Serial.println();
    Serial.print("Block RAW: ");
    for (int j=0; j<16; j++) {
      Serial.print((int)readBlockData[j]);
      Serial.print(" ");
    }
    Serial.println();
    Serial.print("Block STR: [");
    for (int j=0; j<16; j++) {
      if (readBlockData[j] >= 32 && readBlockData[j] < 127)
        Serial.write(readBlockData[j]);
      else
        Serial.print(".");
    }
    Serial.println("]");
  }
  //----------------------------------------------------------------------------
  return true;
}


// ─── HTTPS helpers ────────────────────────────────────────────

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


// ─── Fungsi tambahan ─────────────────────────────────────────────

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

bool isBlockEmpty(byte blockData[]) {
  for (int j = 0; j < 16; j++) {
    if (blockData[j] != 0x00 && blockData[j] != 0xFF) {
      return false;
    }
  }
  return true;
}

String urlEncode(const String &value) {
  String encoded = "";
  char c;
  char buf[4];

  for (unsigned int i = 0; i < value.length(); i++) {
    c = value.charAt(i);
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded += c;
    } else {
      snprintf(buf, sizeof(buf), "%%%02X", (unsigned char) c);
      encoded += buf;
    }
  }

  return encoded;
}
