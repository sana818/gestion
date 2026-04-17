import paho.mqtt.client as mqtt
import mysql.connector
from datetime import date, datetime

db = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",
    database="gestion_utilisateurs"
)

cursor = db.cursor()

def tester_rfid(rfid_code):
    rfid_code = rfid_code.strip().upper()
    print("\nRFID reçu :", rfid_code)

    query = "SELECT id, nom, prenom, statut FROM registre WHERE TRIM(rfid_code) = %s"
    cursor.execute(query, (rfid_code,))
    user = cursor.fetchone()

    today = date.today()
    now_time = datetime.now().strftime("%H:%M:%S")

    if user:
        user_id, nom, prenom, statut = user

        # Si statut est refuse → accès bloqué
        if statut == "refuse":
            print(f"Accès refusé ❌ - Compte de {prenom} {nom} est bloqué")
            return

        # Si statut est en_attente → activer automatiquement
        if statut == "en_attente":
            update_statut = "UPDATE registre SET statut = 'actif' WHERE id = %s"
            cursor.execute(update_statut, (user_id,))
            db.commit()
            print(f"Compte de {prenom} {nom} activé automatiquement ✅")

        print(f"Bonjour {prenom} {nom} ✅")

        # Vérifier présence du jour
        check = """
            SELECT id, heure_arrivee, heure_depart 
            FROM presence 
            WHERE employe_id = %s AND date = %s
        """
        cursor.execute(check, (user_id, today))
        presence = cursor.fetchone()

        if presence:
            presence_id, heure_arrivee, heure_depart = presence

            if heure_depart is None:
                update = """
                    UPDATE presence
                    SET heure_depart = %s
                    WHERE id = %s
                """
                cursor.execute(update, (now_time, presence_id))
                db.commit()
                print("Sortie enregistrée 🟢")
            else:
                print("Déjà pointé ✅")

        else:
            insert = """
                INSERT INTO presence 
                (employe, employe_id, date, heure_arrivee, statut)
                VALUES (%s, %s, %s, %s, %s)
            """
            cursor.execute(insert, (nom, user_id, today, now_time, "présent"))
            db.commit()
            print("Entrée enregistrée 🟢")

    else:
        print("Carte inconnue ❌ - RFID non enregistré dans la BD")


# MQTT
def on_message(client, userdata, msg):
    uid = msg.payload.decode().strip().upper()
    tester_rfid(uid)

client = mqtt.Client()
client.on_message = on_message

client.connect("localhost", 1883, 60)
client.subscribe("rfid/scan")

print("En attente des cartes RFID...")
client.loop_forever()