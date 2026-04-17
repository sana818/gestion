import mysql.connector
import paho.mqtt.client as mqtt

# ── Connexion à MySQL ──
conn = mysql.connector.connect(
    host="localhost",      # ou l'IP de ton serveur MySQL
    user="root",           # ton utilisateur MySQL
    password="",           # ton mot de passe MySQL
    database="rfid_db"     # nom de la base que tu as créée dans XAMPP
)
c = conn.cursor()

# ── Création de la table si elle n'existe pas ──
c.execute("""
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(100)
)
""")
conn.commit()

# ── Fonction lorsqu'on se connecte au broker MQTT ──
def on_connect(client, userdata, flags, rc):
    print("Connecté avec le code de résultat "+str(rc))
    client.subscribe("rfid/check")

# ── Fonction lorsqu'un message est reçu ──
def on_message(client, userdata, msg):
    card_id = msg.payload.decode()
    c.execute("SELECT * FROM users WHERE id=%s", (card_id,))
    if c.fetchone():
        client.publish("rfid/response", "autorisé")
        print(f"ID {card_id} autorisé")
    else:
        client.publish("rfid/response", "refusé")
        print(f"ID {card_id} refusé")

# ── Configuration du client MQTT ──
client = mqtt.Client()
client.on_connect = on_connect
client.on_message = on_message
client.connect("IP_du_broker", 1883, 60)

# ── Boucle infinie MQTT ──
client.loop_forever()