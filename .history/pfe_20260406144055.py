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

    today = date.today()
    now_time = datetime.now().strftime("%H:%M:%S")
    now_datetime = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    cursor.execute("SELECT id, nom, prenom, statut FROM employes WHERE TRIM(rfid_code) = %s", (rfid_code,))
    user = cursor.fetchone()

    if user:
        user_id, nom, prenom, statut = user

        if statut == "en_attente":
            print(f"Carte {rfid_code} en attente de validation RH ⏳")
            cursor.close()
            db.close()
            return

        if statut == "refuse":
            print(f"Accès refusé ❌ - Carte refusée par le RH")
            cursor.close()
            db.close()
            return

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
        cursor.execute("""
            SELECT id, statut FROM employes 
            WHERE rfid_code = %s AND nom = 'Inconnu'
        """, (rfid_code,))
        deja_existant = cursor.fetchone()

        if deja_existant:
            emp_id, emp_statut = deja_existant
            if emp_statut == "en_attente":
                print(f"Carte {rfid_code} déjà en attente de validation RH ⏳")
            elif emp_statut == "refuse":
                print(f"Carte {rfid_code} refusée par le RH ❌")
        else:
            cursor.execute("""
                INSERT INTO employes 
                    (nom, prenom, email, rfid_code, statut, date_embauche, created_at)
                VALUES 
                    ('Inconnu', 'Inconnu', %s, %s, 'en_attente', %s, %s)
            """, (
                f"rfid_{rfid_code}@inconnu.local",
                rfid_code,
                today,
                now_datetime
            ))
            db.commit()
            print(f"Carte inconnue {rfid_code} → employé temporaire créé, en attente RH ⏳")

    cursor.close()
    db.close()


# ✅ Nouvelle signature avec 5 paramètres (VERSION2)
def on_connect(client, userdata, flags, reason_code, properties):
    print(f"Connecté au broker MQTT ✅ (code: {reason_code})")
    client.subscribe("rfid/scan")

def on_message(client, userdata, msg):
    uid = msg.payload.decode().strip().upper()
    print(f"Message reçu sur {msg.topic}: {uid}")
    tester_rfid(uid)

# ✅ VERSION2 pour supprimer le warning
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