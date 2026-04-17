import mysql.connector
from datetime import date, datetime

# Connexion à la base
db = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",
    database="gestion_utilisateurs"
)

cursor = db.cursor()

def tester_rfid(rfid_code):
    print("\nRFID simulé :", rfid_code)

    # 🔍 Recherche dans registre
    query = "SELECT id, nom, prenom, statut FROM registre WHERE rfid_code = %s"
    cursor.execute(query, (rfid_code,))
    user = cursor.fetchone()

    today = date.today()
    now_time = datetime.now().strftime("%H:%M:%S")

    if user:
        user_id, nom, prenom, statut = user

        print(f"Utilisateur : {nom} {prenom}")
        print(f"Statut : {statut}")

        # ✅ Si actif → Présent
        if statut == "actif":

            # Vérifier si déjà présent aujourd'hui
            check = """
                SELECT * FROM presence 
                WHERE employe_id = %s AND date = %s
            """
            cursor.execute(check, (user_id, today))
            presence = cursor.fetchone()

            if not presence:
                # ➕ Ajouter présence
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

                print("Présent aujourd'hui ✅")

            else:
                print("Déjà présent aujourd'hui ✅")

        else:
            # ❌ Non actif → absent
            print("Accès refusé ❌")
            print("Absent aujourd'hui ❌")

            # ➕ On peut enregistrer comme absent
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

    else:
        # ❌ RFID inconnu
        print("Carte inconnue ❌")
        print("Aucune insertion dans presence 🚫")


# 🔥 TEST
tester_rfid("57A911AF")