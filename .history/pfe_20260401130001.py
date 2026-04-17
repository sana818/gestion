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
            SELECT id FROM presence 
            WHERE employe_id = %s AND date = %s
        """
        cursor.execute(check, (user_id, today))
        presence = cursor.fetchone()

        # ✅ إذا actif → présent
        if statut == "actif":

            if presence:
                # 🔄 تحديث إلى présent
                update = """
                    UPDATE presence
                    SET statut = %s, heure_arrivee = %s
                    WHERE employe_id = %s AND date = %s
                """
                cursor.execute(update, ("présent", now_time, user_id, today))
            else:
                # ➕ إدخال جديد
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
            print("Présent ✅")

        else:
            # ❌ غير actif → absent

            if presence:
                update = """
                    UPDATE presence
                    SET statut = %s
                    WHERE employe_id = %s AND date = %s
                """
                cursor.execute(update, ("absent", user_id, today))
            else:
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
                    "absent"
                ))

            db.commit()
            print("Absent ❌")

    else:
        print("Carte inconnue ❌")
        print("Aucune modification dans presence 🚫")


# 🔥 TEST
tester_rfid("57A911AF")