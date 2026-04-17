import paho.mqtt.client as mqtt
import mysql.connector
from datetime import date, datetime

# ------------------ DATABASE ------------------
def get_db():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="gestion_utilisateurs"
    )

# ------------------ RFID FUNCTION ------------------
def tester_rfid(rfid_code):
    try:
        rfid_code = rfid_code.strip().upper()
        print("\nRFID reçu :", rfid_code)

        db = get_db()
        cursor = db.cursor()

        query = """
            SELECT id, nom, prenom, statut 
            FROM employes 
            WHERE TRIM(UPPER(rfid_code)) = %s
        """
        cursor.execute(query, (rfid_code,))
        user = cursor.fetchone()

        today = date.today()
        now_time = datetime.now().strftime("%H:%M:%S")

        if user:
            user_id, nom, prenom, statut = user

            # Supprimer TOUTES les anciennes présences (avant aujourd'hui)
            cursor.execute("DELETE FROM presences WHERE employe_id = %s AND date < %s", (user_id, today))
            db.commit()
            if cursor.rowcount > 0:
                print(f"🗑️ {cursor.rowcount} ancienne(s) présence(s) supprimée(s)")

            if statut == "refuse":
                print(f"Accès refusé ❌ - {prenom} {nom} bloqué")
                return

            if statut == "en_attente":
                cursor.execute(
                    "UPDATE employes SET statut = 'actif' WHERE id = %s",
                    (user_id,)
                )
                db.commit()
                print(f"Compte activé pour {prenom} {nom} ✅")

            print(f"Bonjour {prenom} {nom} 👋")

            # Vérifier si présence existe aujourd'hui
            cursor.execute("""
                SELECT id, heure_arrivee, heure_depart
                FROM presences 
                WHERE employe_id = %s AND date = %s
            """, (user_id, today))
            presence = cursor.fetchone()

            if presence:
                presence_id, heure_arrivee, heure_depart = presence

                # Si pas encore de sortie -> enregistrer la sortie
                if heure_depart is None:
                    cursor.execute("""
                        UPDATE presences 
                        SET heure_depart = %s, statut = 'absent'
                        WHERE id = %s
                    """, (now_time, presence_id))

                    cursor.execute("""
                        UPDATE employes 
                        SET statut = 'en_attente'
                        WHERE id = %s
                    """, (user_id,))
                    db.commit()

                    fmt = "%H:%M:%S"
                    entree = datetime.strptime(str(heure_arrivee), fmt)
                    sortie = datetime.strptime(now_time, fmt)
                    duree = sortie - entree

                    heures, reste = divmod(duree.seconds, 3600)
                    minutes, secondes = divmod(reste, 60)

                    print(f"Sortie enregistrée à {now_time} 🟢")
                    print(f"Durée : {heures}h {minutes}min {secondes}s ⏱️")
                else:
                    print("Déjà pointé entrée et sortie aujourd'hui ✅")
            else:
                # Nouvelle entrée
                cursor.execute("""
                    INSERT INTO presences (employe_id, date, heure_arrivee, statut)
                    VALUES (%s, %s, %s, %s)
                """, (user_id, today, now_time, "present"))

                cursor.execute("""
                    UPDATE employes 
                    SET statut = 'actif'
                    WHERE id = %s
                """, (user_id,))
                db.commit()

                print(f"Entrée enregistrée à {now_time} 🟢")

        else:
            print("Carte inconnue ❌")

    except Exception as e:
        print("Erreur :", e)

    finally:
        try:
            cursor.close()
            db.close()
        except:
            pass

# ------------------ MQTT ------------------
def on_connect(client, userdata, flags, rc):
    print("Connecté au broker MQTT ✅")
    client.subscribe("rfid/scan")

def on_message(client, userdata, msg):
    try:
        uid = msg.payload.decode().strip().upper()
        tester_rfid(uid)
    except Exception as e:
        print("Erreur message MQTT :", e)

client = mqtt.Client()
client.on_connect = on_connect
client.on_message = on_message
client.connect("localhost", 1883, 60)

print("En attente des cartes RFID... 📡")
client.loop_forever()