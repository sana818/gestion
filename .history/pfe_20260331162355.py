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
    print("RFID simulé :", rfid_code)

    query = "SELECT * FROM registre WHERE rfid_code = %s"
    cursor.execute(query, (rfid_code,))
    user = cursor.fetchone()

    if user:
        print("Utilisateur trouvé :", user[1], user[2])  # nom, prénom

        statut = user[10]  # adapte si nécessaire

        if statut == "autorisé":
            print("Accès autorisé ✅")
        else:
            print("Accès refusé ❌ (statut:", statut, ")")
    else:
        print("Carte inconnue ❌")

# 🔥 TEST
tester_rfid("57A911AF")