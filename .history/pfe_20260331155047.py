import paho.mqtt.client as mqtt
import mysql.connector
from datetime import datetime

# ====================== CONNEXION BASE DE DONNÉES ======================
db = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",                    # ton mot de passe phpMyAdmin
    database="gestion_utilisateurs"
)

cursor = db.cursor(dictionary=True)

# ====================== FONCTION MQTT ======================
def on_message(client, userdata, msg):
    uid = msg.payload.decode().strip().upper()   # nettoyage + majuscules
    print(f"\nCarte scannée → UID reçu : [{uid}]")

    # ====================== VÉRIFICATION DE LA CARTE ======================
    cursor.execute("""
        SELECT r.employe_id, reg.nom, reg.prenom 
        FROM rfid_db r
        JOIN registre reg ON r.employe_id = reg.id
        WHERE UPPER(r.rfid_code) = %s
    """, (uid,))

    employe = cursor.fetchone()

    if employe:
        emp_id = employe['employe_id']
        nom_complet = f"{employe['nom']} {employe['prenom']}"
        maintenant = datetime.now()

        print(f"Accès ACCORDÉ à : {nom_complet} (ID: {emp_id})")

        # ====================== ENREGISTREMENT PRÉSENCE ======================
        cursor.execute("""
            INSERT INTO presence (employe, date, heure_arrivee, statut, employe_id)
            VALUES (%s, %s, %s, 'présent', %s)
        """, (nom_complet, maintenant.date(), maintenant.time(), emp_id))

        # ====================== ENREGISTREMENT HISTORIQUE SALLE ======================
        cursor.execute("""
            INSERT INTO historique_salles (employe_id, salle_id, date_entree, heure_entree)
            VALUES (%s, 1, %s, %s)
        """, (emp_id, maintenant.date(), maintenant.time()))

        db.commit()
        print("Présence et historique enregistrés avec succès !")

    else:
        print(f"Carte INCONNUE → Accès REFUSÉ (UID: {uid})")


# ====================== CONNEXION MQTT ======================
client = mqtt.Client()
client.on_message = on_message

try:
    client.connect("localhost", 1883, 60)
    client.subscribe("rfid/scan")
    print("Connecté au broker MQTT | En attente de cartes RFID...")
except Exception as e:
    print(f"Erreur de connexion MQTT : {e}")
    exit()

client.loop_forever()