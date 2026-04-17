import mysql.connector
import paho.mqtt.client as mqtt
from datetime import datetime

# Connexion MySQL
conn = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",
    database="gestion_utilisateurs"
)
c = conn.cursor()

# Connexion MQTT
def on_connect(client, userdata, flags, rc):
    print("Connecté :", rc)
    client.subscribe("rfid/check")

# Réception carte
def on_message(client, userdata, msg):
    card_id = msg.payload.decode().upper()
    print("Carte :", card_id)

    c.execute("SELECT employe_id FROM rfid_db WHERE card_id=%s", (card_id,))
    result = c.fetchone()

    if result:
        employe_id = result[0]
        now = datetime.now()

        # Vérifier présence
        c.execute("SELECT * FROM presence WHERE employe_id=%s AND date=%s",
                  (employe_id, now.date()))
        exist = c.fetchone()

        if not exist:
            # entrée
            c.execute("""
            INSERT INTO presence (employe_id, date, heure_arrivee, statut)
            VALUES (%s, %s, %s, %s)
            """, (employe_id, now.date(), now.time(), "présent"))
        else:
            # sortie
            c.execute("""
            UPDATE presence 
            SET heure_depart=%s 
            WHERE employe_id=%s AND date=%s
            """, (now.time(), employe_id, now.date()))

        conn.commit()

        client.publish("rfid/response", "autorisé")
        print("Autorisé")

    else:
        client.publish("rfid/response", "refusé")
        print("Refusé")

client = mqtt.Client()
client.on_connect = on_connect
client.on_message = on_message

client.connect("127.0.0.1", 1883, 60)
client.loop_forever()