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

    query = "SELECT id, nom, prenom, statut FROM registre WHERE TRIM(rfid_code) = %s"
    cursor.execute(query, (rfid_code,))
    user = cursor.fetchone()

    today = date.today()
    now_time = datetime.now().strftime("%H:%M:%S")

    if user:
        user_id, nom, prenom, statut = user

        # Carte bloquée
        if statut == "refuse":
            print(f"Accès refusé ❌ - Compte de {prenom} {nom} est bloqué")
            cursor.close()
            db.close()
            return

        # 1er scan → activer le compte
        if statut == "en_attente":
            cursor.execute("UPDATE registre SET statut = 'actif' WHERE id = %s", (user_id,))
            db.commit()
            print(f"Compte de {prenom} {nom} activé ✅")

        print(f"Bonjour {prenom} {nom} ✅")

        # Vérifier présence du jour
        cursor.execute("""
            SELECT id, heure_arrivee, heure_depart 
            FROM presence 
            WHERE employe_id = %s AND date = %s
        """, (user_id, today))
        presence = cursor.fetchone()

        if presence:
            presence_id, heure_arrivee, heure_depart = presence

            if heure_depart is None:
                # 2ème scan → sortie + statut revient en_attente
                cursor.execute("""
                    UPDATE presence SET heure_depart = %s WHERE id = %s
                """, (now_time, presence_id))
                db.commit()

                cursor.execute("UPDATE registre SET statut = 'en_attente' WHERE id = %s", (user_id,))
                db.commit()

                print(f"Sortie enregistrée à {now_time} 🟢")
                print(f"Statut revenu en 'en_attente' pour {prenom} {nom} ⚠️")

            else:
                print("Déjà pointé ✅")

        else:
            # 1er scan → enregistrer l'entrée
            cursor.execute("""
                INSERT INTO presence (employe, employe_id, date, heure_arrivee, statut)
                VALUES (%s, %s, %s, %s, %s)
            """, (nom, user_id, today, now_time, "présent"))
            db.commit()
            print(f"Entrée enregistrée à {now_time} 🟢")

    else:
        print("Carte inconnue ❌ - RFID non enregistré dans la BD")

    cursor.close()
    db.close()


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