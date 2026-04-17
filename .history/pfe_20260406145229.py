import paho.mqtt.client as mqtt
import mysql.connector
from datetime import date, datetime

# --- اتصال قاعدة البيانات ---
def get_db():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="gestion_utilisateurs"
    )

# --- معالجة RFID ---
def tester_rfid(rfid_code):
    rfid_code = rfid_code.strip().upper()
    print("\nRFID reçu :", rfid_code)

    db = get_db()
    cursor = db.cursor()

    today = date.today()
    now_time = datetime.now().strftime("%H:%M:%S")
    now_datetime = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    cursor.execute("""
        SELECT id, nom, prenom, statut 
        FROM employes 
        WHERE TRIM(rfid_code) = %s
    """, (rfid_code,))
    
    user = cursor.fetchone()

    if user:
        user_id, nom, prenom, statut = user

        if statut == "en_attente":
            print("⏳ En attente validation RH")
            return

        if statut == "refuse":
            print("❌ Accès refusé")
            return

        print(f"Bonjour {prenom} {nom} ✅")

        cursor.execute("""
            SELECT id, heure_arrivee, heure_depart
            FROM presences 
            WHERE employe_id = %s AND date = %s
        """, (user_id, today))

        presence = cursor.fetchone()

        if presence:
            presence_id, heure_arrivee, heure_depart = presence

            if heure_depart is None:
                cursor.execute("""
                    UPDATE presences 
                    SET heure_depart = %s
                    WHERE id = %s
                """, (now_time, presence_id))

                db.commit()
                print("🟢 Sortie enregistrée")
            else:
                print("⚠️ Déjà pointé aujourd'hui")

        else:
            cursor.execute("""
                INSERT INTO presences (employe_id, date, heure_arrivee, statut)
                VALUES (%s, %s, %s, %s)
            """, (user_id, today, now_time, "présent"))

            db.commit()
            print("🟢 Entrée enregistrée")

    else:
        print("❓ Carte inconnue")

    cursor.close()
    db.close()


# --- MQTT ---
def on_connect(client, userdata, flags, reason_code, properties):
    print("Connecté au broker MQTT ✅")
    client.subscribe("rfid/scan")


def on_message(client, userdata, msg):
    uid = msg.payload.decode().strip().upper()
    print(f"📩 Topic: {msg.topic} | UID: {uid}")
    tester_rfid(uid)


# --- CLIENT MQTT (IMPORTANT) ---
client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)

client.on_connect = on_connect
client.on_message = on_message

# 🔥 IMPORTANT: IP du PC (pas localhost)
client.connect("10.253.180.92", 1883, 60)

print("⏳ En attente des cartes RFID...")

try:
    client.loop_forever()
except KeyboardInterrupt:
    print("\n🛑 Arrêt")
    client.disconnect()