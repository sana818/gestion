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

        # Carte bloquée
        if statut == "refuse":
            print(f"Accès refusé ❌ - Compte de {prenom} {nom} est bloqué")
            cursor.close()
            db.close()
            return

        # Activer si en_attente
        if statut == "en_attente":
            cursor.execute("UPDATE employes SET statut = 'actif' WHERE id = %s", (user_id,))
            db.commit()
            print(f"Compte de {prenom} {nom} activé ✅")

        print(f"Bonjour {prenom} {nom} ✅")

        # Vérifier présence du jour
        cursor.execute("""
            SELECT id, heure_arrivee, heure_depart, statut
            FROM presences 
            WHERE employe_id = %s AND date = %s
        """, (user_id, today))
        presence = cursor.fetchone()

        if presence:
            presence_id, heure_arrivee, heure_depart, statut_presence = presence

            if heure_depart is None:
                # 2ème scan → sortie
                cursor.execute("""
                    UPDATE presences 
                    SET heure_depart = %s, statut = 'absent'
                    WHERE id = %s
                """, (now_time, presence_id))
                db.commit()

                # Statut employes → en_attente
                cursor.execute("UPDATE employes SET statut = 'en_attente' WHERE id = %s", (user_id,))
                db.commit()

                # Calcul durée
                fmt = "%H:%M:%S"
                entree = datetime.strptime(str(heure_arrivee), fmt)
                sortie = datetime.strptime(now_time, fmt)
                duree = sortie - entree
                heures, reste = divmod(duree.seconds, 3600)
                minutes, secondes = divmod(reste, 60)

                print(f"Sortie enregistrée à {now_time} 🟢")
                print(f"Durée de présence : {heures}h {minutes}min {secondes}s ⏱️")
                print(f"Statut → 'absent' dans presences ⚠️")
                print(f"Statut → 'en_attente' dans employes ⚠️")

            else:
                # Déjà sorti aujourd'hui
                print(f"Déjà pointé entrée ET sortie aujourd'hui ✅")
                print(f"Revenez demain 📅")

        else:
            # 1er scan du jour → entrée
            cursor.execute("UPDATE employes SET statut = 'actif' WHERE id = %s", (user_id,))
            db.commit()

            cursor.execute("""
                INSERT INTO presences (employe_id, date, heure_arrivee, statut)
                VALUES (%s, %s, %s, %s)
            """, (user_id, today, now_time, "présent"))
            db.commit()

            print(f"Entrée enregistrée à {now_time} 🟢")
            print(f"Statut → 'présent' dans presences ✅")
            print(f"Statut → 'actif' dans employes ✅")

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