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
    print("\nRFID simulé :", rfid_code)

    # 🔍 البحث في registre
    query = "SELECT id, nom, prenom, statut FROM registre WHERE rfid_code = %s"
    cursor.execute(query, (rfid_code,))
    user = cursor.fetchone()

    today = date.today()
    now_time = datetime.now().strftime("%H:%M:%S")

    if user:
        user_id, nom, prenom, statut = user

        print(f"Utilisateur : {nom} {prenom}")
        print(f"Statut : {statut}")

        # 🔍 هل موجود في presence اليوم؟
        check = """
            SELECT id, heure_arrivee, heure_depart 
            FROM presence 
            WHERE employe_id = %s AND date = %s
        """
        cursor.execute(check, (user_id, today))
        presence = cursor.fetchone()

        # ❌ غير actif → absent
        if statut != "actif":
            print("Accès refusé ❌")
            print("Absent ❌")
            return

        # ✅ actif → traitement pointage
        if presence:
            presence_id, heure_arrivee, heure_depart = presence

            # 🔁 إذا لم يتم تسجيل heure_depart → نسجلها
            if heure_depart is None:
                update = """
                    UPDATE presence
                    SET heure_depart = %s
                    WHERE id = %s
                """
                cursor.execute(update, (now_time, presence_id))
                db.commit()

                print("Heure de départ enregistrée 🟢")
            else:
                print("Déjà pointé (entrée et sortie) ✅")

        else:
            # ➕ أول دخول
            insert = """
                INSERT INTO presence 
                (employe, employe_id, date, heure_arrivee, statut)
                VALUES (%s, %s, %s, %s, %s)
            """
            cursor.execute(insert, (
                nom,
                user_id,
                today,
                now_time,
                "présent"
            ))
            db.commit()

            print("Heure d'arrivée enregistrée 🟢")

    else:
        print("Carte inconnue ❌")
        print("Aucune action 🚫")


# 🔥 TEST
tester_rfid("57A911AF")