import mysql.connector

# Connexion à MySQL
db = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",
    database="gestion_utilisateurs"
)

cursor = db.cursor()

def tester_rfid(rfid_code):
    print("\nRFID simulé :", rfid_code)

    query = "SELECT nom, prenom, statut FROM registre WHERE rfid_code = %s"
    cursor.execute(query, (rfid_code,))
    user = cursor.fetchone()

    if user:
        nom, prenom, statut = user

        print("Utilisateur trouvé :", nom, prenom)
        print("Statut :", statut)

        # ✅ Vérification correcte (ENUM)
        if statut == "actif":
            print("Accès autorisé ✅")
        else:
            print("Accès refusé ❌ (statut:", statut, ")")

    else:
        print("Carte inconnue ❌")


# 🔥 TEST
tester_rfid("57A911AF")