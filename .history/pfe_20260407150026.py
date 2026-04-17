import paho.mqtt.client as mqtt
import mysql.connector
from datetime import date, datetime

# منع التكرار
last_scan = {}

# الاتصال بقاعدة البيانات
def get_db():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="gestion_utilisateurs"
    )

def tester_rfid(rfid_code):
    global last_scan

    try:
        rfid_code = rfid_code.strip().upper()
        now = datetime.now()
        today = date.today()
        now_time = now.strftime("%H:%M:%S")

        # منع تكرار القراءة
        if rfid_code in last_scan:
            diff = (now - last_scan[rfid_code]).seconds
            if diff < 3:
                return
        last_scan[rfid_code] = now

        print("\nRFID reçu :", rfid_code)

        db = get_db()
        cursor = db.cursor()

        # البحث عن المستخدم
        cursor.execute("""
            SELECT id, nom, prenom, statut 
            FROM employes 
            WHERE TRIM(UPPER(rfid_code)) = %s
        """, (rfid_code,))
        user = cursor.fetchone()

        if not user:
            print("Carte inconnue ❌")
            return

        user_id, nom, prenom, statut = user

        # منع الحسابات غير المفعلة
        if statut == "refuse":
            print("Accès refusé ❌")
            return

        print(f"Bonjour {prenom} {nom} 👋")

        # 🔥 مهم: نبحث على présence لليوم
        cursor.execute("""
            SELECT id, heure_arrivee, heure_depart
            FROM presences 
            WHERE employe_id = %s AND DATE(date) = %s
        """, (user_id, today))

        presence = cursor.fetchone()

        # ------------------ SORTIE ------------------
        if presence:
            presence_id, heure_arrivee, heure_depart = presence

            if heure_depart is None:
                cursor.execute("""
                    UPDATE presences 
                    SET heure_depart = %s
                    WHERE id = %s
                """, (now_time, presence_id))

                db.commit()

                # حساب الوقت
                fmt = "%H:%M:%S"
                entree = datetime.strptime(str(heure_arrivee), fmt)
                sortie = datetime.strptime(now_time, fmt)

                duree = sortie - entree
                h, rem = divmod(duree.seconds, 3600)
                m, s = divmod(rem, 60)

                print(f"Sortie enregistrée à {now_time} 🟢")
                print(f"Durée : {h}h {m}min {s}s ⏱️")

            else:
                print("Déjà pointé aujourd'hui ✅")

        # ------------------ ENTREE ------------------
        else:
            cursor.execute("""
                INSERT INTO presences (employe_id, date, heure_arrivee, statut)
                VALUES (%s, %s, %s, %s)
            """, (user_id, today, now_time, "present"))

            db.commit()

            print(f"Entrée enregistrée à {now_time} 🟢")

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
    uid = msg.payload.decode().strip().upper()
    tester_rfid(uid)

client = mqtt.Client()

client.on_connect = on_connect
client.on_message = on_message

client.connect("localhost", 1883, 60)

print("En attente des cartes RFID... 📡")
client.loop_forever()