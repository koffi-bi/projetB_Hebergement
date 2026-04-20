# ================================================================
#  SmartRecruit — Face ID Service
#  Fichier : python/face_id_service.py
#
#  Lancer (dev)  : uvicorn face_id_service:app --port 5000 --reload
#  Lancer (prod) : uvicorn face_id_service:app --host 0.0.0.0 --port 5000 --workers 2
#
#  IA utilisée : Groq (GRATUIT) — https://console.groq.com
#    - Texte  : llama-3.3-70b-versatile   (~500 000 tokens/jour gratuits)
#    - Vision : meta-llama/llama-4-scout-17b-16e-instruct (multimodal)
#    - URL    : https://api.groq.com/openai/v1
#
#  .env requis (même dossier) :
#      GROQ_API_KEY=gsk_xxxxxxxxxxxxxxxxxxxx    ← obtenir sur console.groq.com
#      DB_HOST=localhost
#      DB_NAME=smartrecruit
#      DB_USER=root
#      DB_PASSWORD=
# ================================================================

import cv2
import face_recognition
import numpy as np
import mysql.connector
import json, secrets, string, base64, logging
from datetime import datetime, timedelta
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from google.cloud import vision
from dotenv import load_dotenv
import os

load_dotenv()

app = FastAPI(title="SmartRecruit — Face ID", version="2.0", docs_url="/docs")
app.add_middleware(CORSMiddleware, allow_origins=["http://localhost","http://127.0.0.1"],
                   allow_credentials=True, allow_methods=["*"], allow_headers=["*"])

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("face_id")

# ── MODÈLES PYDANTIC ─────────────────────────────────────────────
class RequeteCapturer(BaseModel):
    action: str = "capturer"

class RequeteVerifier(BaseModel):
    action: str = "identifier"

# ── UTILITAIRES ──────────────────────────────────────────────────

def get_db():
    """Connexion MySQL."""
    return mysql.connector.connect(**DB)

def token_securise(n=64):
    """Token URL-safe aléatoire."""
    return "".join(secrets.choice(string.ascii_letters + string.digits) for _ in range(n))

def frame_b64(frame):
    """Frame OpenCV → JPEG base64."""
    _, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
    return base64.b64encode(buf).decode()

def capturer_visage():
    """
    Ouvre la webcam et attend qu'un visage soit détecté (~7 sec max).
    Retourne (frame, encoding_128_floats).
    Lève HTTPException 400 si rien n'est détecté.
    """
    cap = cv2.VideoCapture(0)
    cap.set(cv2.CAP_PROP_FRAME_WIDTH, 640)
    cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 480)

    if not cap.isOpened():
        raise HTTPException(400, "Webcam inaccessible. Vérifiez qu'elle est branchée.")

    frame_ok = encoding = None

    for _ in range(90):   # ~7 secondes à ~12 fps
        ok, frame = cap.read()
        if not ok:
            continue
        rgb  = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        locs = face_recognition.face_locations(rgb, model="hog")
        encs = face_recognition.face_encodings(rgb, locs)
        if encs:
            encoding = encs[0]
            frame_ok = frame.copy()
            log.info("Visage détecté.")
            break

    cap.release()
    # destroyAllWindows() indisponible sur opencv-python headless (Windows sans GUI).
    # Le try/except garantit que le service tourne dans tous les environnements.
    try:
        cv2.destroyAllWindows()
    except Exception:
        pass

    if encoding is None:
        raise HTTPException(400, "Aucun visage détecté après 7 secondes. "
                                 "Placez-vous face à la caméra dans un endroit bien éclairé.")
    return frame_ok, encoding

# ── GROQ IA ──────────────────────────────────────────────────────

# ── ENDPOINTS ────────────────────────────────────────────────────

@app.post("/enregistrer-visage",
          summary="Capturer et encoder le visage d'un nouvel admin",
          tags=["Face ID"])
async def enregistrer_visage(body: RequeteCapturer):
    """
    Appelé par admin/register.php lors de l'inscription.
    Capture la webcam → génère l'encoding 128 floats.
    PHP récupère cet encoding et le stocke dans MySQL (colonne face_encoding).

    Retourne :
    - encoding  : liste de 128 floats à stocker en base
    - avis_groq : évaluation qualité par Groq Vision
    """
    log.info("POST /enregistrer-visage")
    frame, encoding = capturer_visage()
    avis = groq_qualite_capture(frame_b64(frame), "enregistrement Face ID admin")
    return {"success": True, "encoding": encoding.tolist(), "avis_groq": avis,
            "message": "Visage enregistré avec succès."}


@app.post("/verifier-visage",
          summary="Identifier un admin par Face ID et générer un token",
          tags=["Face ID"])
async def verifier_visage(body: RequeteVerifier):
    """
    Appelé par admin/login.php lors de la connexion.

    Flux :
    1. Webcam capture le visage en direct
    2. Comparaison avec les encodings MySQL (admins avec face_id_actif=1)
    3. Si reconnu → token inséré dans sessions_face_id (expire dans 5 min)
    4. PHP valide ce token côté serveur pour ouvrir la session PHP
    5. Groq commente la connexion pour les logs

    Retourne :
    - token      : token que PHP doit valider en base
    - admin_nom  : nom de l'admin reconnu
    - commentaire: note de sécurité Groq
    """
    log.info("POST /verifier-visage")

    # Capturer
    frame, encoding_inconnu = capturer_visage()

    # Charger les admins depuis MySQL
    db     = get_db()
    cur    = db.cursor(dictionary=True)
    cur.execute("""
        SELECT id, prenom, nom, face_encoding
        FROM administrateurs
        WHERE face_id_actif=1 AND face_encoding IS NOT NULL AND statut='actif'
    """)
    admins = cur.fetchall()

    if not admins:
        cur.close(); db.close()
        raise HTTPException(404, "Aucun admin avec Face ID configuré.")

    # Trouver le meilleur match
    meilleur = None
    dist_min = float("inf")

    for admin in admins:
        enc_ref  = np.array(json.loads(admin["face_encoding"]))
        distance = face_recognition.face_distance([enc_ref], encoding_inconnu)[0]
        log.info(f"  {admin['prenom']} {admin['nom']} → {distance:.4f}")
        if distance < SEUIL_TOLERANCE and distance < dist_min:
            dist_min = distance
            meilleur = admin

    # Aucun match
    if meilleur is None:
        cur.close(); db.close()
        return {"success": False, "token": None,
                "message": f"Visage non reconnu (distance min : {dist_min:.3f}). "
                           "Utilisez votre mot de passe si le problème persiste."}

    # Générer et stocker le token
    token = token_securise()
    expir = datetime.now() + timedelta(minutes=DUREE_TOKEN_MINUTES)
    cur.execute("INSERT INTO sessions_face_id (admin_id, token, expire_a) VALUES (%s,%s,%s)",
                (meilleur["id"], token, expir))
    cur.execute("UPDATE administrateurs SET derniere_connexion=NOW() WHERE id=%s", (meilleur["id"],))
    db.commit(); cur.close(); db.close()

    nom        = f"{meilleur['prenom']} {meilleur['nom']}"
    commentaire = groq_log_connexion(nom, dist_min)
    log.info(f"Face ID OK — {nom} — distance {dist_min:.4f}")

    return {"success": True, "token": token, "admin_nom": nom,
            "commentaire": commentaire, "message": f"Bienvenue {meilleur['prenom']} !"}


@app.get("/sante", summary="Healthcheck", tags=["Système"])
async def sante():
    """Vérifie que le service, MySQL et Groq sont opérationnels."""
    try:
        db = get_db(); db.close(); bd_ok = True
    except Exception:
        bd_ok = False
    return {"status": "ok" if bd_ok else "erreur_bd",
            "service": "Face ID — FastAPI + Groq (gratuit)", "version": "2.0",
            "modele_texte": MODELE_TEXTE, "modele_vision": MODELE_VISION,
            "bd": bd_ok, "groq": bool(GROQ_API_KEY)}


# ── DÉMARRAGE ────────────────────────────────────────────────────
if __name__ == "__main__":
    import uvicorn
    print(f"\n SmartRecruit — Face ID | Groq gratuit : {'✅' if GROQ_API_KEY else '❌ GROQ_API_KEY manquante dans .env'}")
    print(f" Modèle texte  : {MODELE_TEXTE}")
    print(f" Modèle vision : {MODELE_VISION}")
    print(" Swagger : http://localhost:5000/docs\n")
    uvicorn.run("face_id_service:app", host="0.0.0.0", port=5000, reload=True)
