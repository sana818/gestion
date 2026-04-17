import paho.mqtt.client as mqtt
import mysql.connector
from datetime import date, datetime

def get_db():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="gestion_utilisateurs"
    )

def tester_rfid(rfid_code):
    rfid_code = rfid_code.strip().upper()
    print("\nRFID reçu :", rfid_code)

    db = get_db()
    cursor = db.cursor()

    query = "SELECT id, nom, prenom, statut FROM employes WHERE TRIM(rfid_code) = %s"
    cursor.execute(query, (rfid_code,))
    user = cursor.fetchone()

    today = date.today()
    now_time = datetime.now().strftime("%H:%M:%S")

    if user:
        user_id, nom, prenom, statut = user

        if statut == "refuse":
            print(f"Accès refusé ❌ - Compte de {prenom} {nom} est bloqué")
            cursor.close()
            db.close()
            return

        if statut == "en_attente":
            cursor.execute("UPDATE employes SET statut = 'actif' WHERE id = %s", (user_id,))
            db.commit()
            print(f"Compte de {prenom} {nom} activé ✅")

        print(f"Bonjour {prenom} {nom} ✅")

        cursor.execute("""
            SELECT id, heure_arrivee, heure_depart, statut
            FROM presences 
            WHERE employe_id = %s AND date = %s
        """, (user_id, today))
        presence = cursor.fetchone()

        if presence:
            presence_id, heure_arrivee, heure_depart, statut_presence = presence

            if heure_depart is None:
                cursor.execute("""
                    UPDATE presences 
                    SET heure_depart = %s, statut = 'absent'
                    WHERE id = %s
                """, (now_time, presence_id))
                db.commit()

                cursor.execute("UPDATE employes SET statut = 'en_attente' WHERE id = %s", (user_id,))
                db.commit()

                fmt = "%H:%M:%S"
                entree = datetime.strptime(str(heure_arrivee), fmt)
                sortie = datetime.strptime(now_time, fmt)
                duree = sortie - entree
                heures, reste = divmod(duree.seconds, 3600)
                minutes, secondes = divmod(reste, 60)

                print(f"Sortie enregistrée à {now_time} 🟢")
                print(f"Durée de présence : {heures}h {minutes}min {secondes}s ⏱️")

            else:
                print(f"Déjà pointé entrée ET sortie aujourd'hui ✅")
                print(f"Revenez demain 📅")

        else:
            cursor.execute("UPDATE employes SET statut = 'actif' WHERE id = %s", (user_id,))
            db.commit()

            cursor.execute("""
                INSERT INTO presences (employe_id, date, heure_arrivee, statut)
                VALUES (%s, %s, %s, %s)
            """, (user_id, today, now_time, "présent"))
            db.commit()

            print(f"Entrée enregistrée à {now_time} 🟢")

    else:
        print("Carte inconnue ❌ - RFID non enregistré dans la BD")

    cursor.close()
    db.close()


def on_connect(client, userdata, flags, reason_code, properties):
    print(f"Connecté au broker MQTT ✅ (code: {reason_code})")
    client.subscribe("rfid/scan")

def on_message(client, userdata, msg):
    uid = msg.payload.decode().strip().upper()
    print(f"Message reçu sur {msg.topic}: {uid}")
    tester_rfid(uid)

client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
client.on_connect = on_connect
client.on_message = on_message
client.connect("localhost", 1883, 60)

print("En attente des cartes RFID...")

try:
    client.loop_forever()
except KeyboardInterrupt:
    print("\nArrêt du programme ⛔")
    client.disconnect()