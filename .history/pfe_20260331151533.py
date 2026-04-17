    import paho.mqtt.client as mqtt
import mysql.connector
from datetime import datetime

# --- Connexion à ta base de données ---
db = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",               # ton mot de passe phpMyAdmin
    database="gestion_utilisateurs"
)

# --- Cette fonction se déclenche automatiquement quand une carte est scannée ---
def on_message(client, userdata, msg):
    uid = msg.payload.decode()   # uid = le numéro de la carte RFID reçu
    print("Carte scannée, UID reçu :", uid)

    cursor = db.cursor(dictionary=True)

    # =============================================
    # REQUETE 1 : Est-ce que cette carte existe ?
    # On cherche dans rfid_db si l'UID est connu
    # =============================================
    cursor.execute("""
        SELECT r.employe_id, reg.nom, reg.prenom
        FROM rfid_db r
        JOIN registre reg ON r.employe_id = reg.id
        WHERE r.id = %s
    """, (uid,))

    employe = cursor.fetchone()  # employe = les infos trouvées, ou None si inconnu

    if employe:
        # L'UID existe dans la base => accès accordé
        emp_id = employe['employe_id']
        nom_complet = employe['nom'] + " " + employe['prenom']
        maintenant = datetime.now()

        print("Accès accordé à :", nom_complet)

        # =============================================
        # REQUETE 2 : Enregistrer la présence
        # =============================================
        cursor.execute("""
            INSERT INTO presence (employe, date, heure_arrivee, statut, employe_id)
            VALUES (%s, %s, %s, 'présent', %s)
        """, (nom_complet, maintenant.date(), maintenant.time(), emp_id))

        # =============================================
        # REQUETE 3 : Enregistrer dans historique_salles
        # =============================================
        cursor.execute("""
            INSERT INTO historique_salles (employe_id, salle_id, date_entree, heure_entree)
            VALUES (%s, 1, %s, %s)
        """, (emp_id, maintenant.date(), maintenant.time()))

        db.commit()  # Sauvegarder dans la base
        print("Présence enregistrée dans la base !")

    else:
        # L'UID n'existe pas dans rfid_db
        print("Carte inconnue, accès refusé :", uid)

# --- Connexion au broker MQTT et démarrage ---
client = mqtt.Client()
client.on_message = on_message
client.connect("localhost", 1883)
client.subscribe("rfid/scan")   # Écoute le topic où l'ESP32 envoie l'UID

print("En attente de cartes RFID...")
client.loop_forever()           # Tourne en permanence