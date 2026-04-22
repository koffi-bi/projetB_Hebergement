# batch_analyse_cv.py
import os
import mysql.connector
import requests
from pypdf import PdfReader
from dotenv import load_dotenv

load_dotenv()

# ============================================================
# Configuration
# ============================================================
DB = {
    "host":     os.getenv("DB_HOST", "localhost"),
    "database": os.getenv("DB_NAME", "projetb"),
    "user":     os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASSWORD", ""),
}

# URL de votre service matching (ajustez le port si nécessaire)
API_URL = "http://localhost:5001/extraire-cv"

# Chemin absolu vers le dossier des CVs
# On part du dossier où se trouve ce script, on remonte deux niveaux,
# puis on va dans php-app/uploads/cv
BASE_DIR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
UPLOAD_DIR = os.path.join(BASE_DIR, "php-app", "uploads", "cv")

print(f"📁 Dossier des CVs : {UPLOAD_DIR}")

# ============================================================
# Fonctions
# ===========================================================
def extraire_texte_pdf(chemin_fichier):
    try:
        reader = PdfReader(chemin_fichier)
        texte = ""
        for page in reader.pages:
            texte += page.extract_text()
        return texte.strip()
    except Exception as e:
        print(f"❌ Erreur lecture PDF {chemin_fichier}: {e}")
        return ""

def analyser_cv(candidat_id, texte_cv):
    payload = {
        "texte_cv": texte_cv[:4000],  # limite pour éviter timeout
        "candidat_id": candidat_id
    }
    try:
        resp = requests.post(API_URL, json=payload, timeout=60)
        if resp.status_code == 200:
            print(f"✅ Candidat {candidat_id} analysé")
            return True
        else:
            print(f"❌ Erreur HTTP {resp.status_code} pour candidat {candidat_id}")
            return False
    except Exception as e:
        print(f"❌ Exception pour candidat {candidat_id}: {e}")
        return False

def main():
    # Connexion à la base
    conn = mysql.connector.connect(**DB)
    cursor = conn.cursor(dictionary=True)

    # Récupérer les CVs non analysés ou sans resume_ia
    cursor.execute("""
        SELECT cv.id, cv.candidat_id, cv.chemin, cv.nom_fichier
        FROM cv
        WHERE cv.statut_analyse != 'analyse' OR cv.resume_ia IS NULL
    """)
    cvs = cursor.fetchall()
    print(f"🔍 {len(cvs)} CVs à analyser")

    for cv in cvs:
        # Le chemin stocké en base est du type "uploads/cv/cv_4_1775401489.pdf"
        # On extrait le nom du fichier
        nom_fichier = os.path.basename(cv['chemin'])
        chemin_complet = os.path.join(UPLOAD_DIR, nom_fichier)

        if not os.path.exists(chemin_complet):
            print(f"⚠️ Fichier introuvable : {chemin_complet}")
            # Essayer avec le chemin original (au cas où)
            if os.path.exists(cv['chemin']):
                chemin_complet = cv['chemin']
            else:
                continue

        print(f"📄 Analyse de : {nom_fichier}")
        texte = extraire_texte_pdf(chemin_complet)
        if not texte:
            print(f"⚠️ Aucun texte extrait pour {nom_fichier}, mise d'un placeholder")
            texte = "CV non lisible (image ou PDF sans texte)."

        analyser_cv(cv['candidat_id'], texte)

    cursor.close()
    conn.close()
    print("✅ Analyse terminée.")

if __name__ == "__main__":
    main()
