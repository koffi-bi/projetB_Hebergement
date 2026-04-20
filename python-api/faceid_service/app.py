# ================================================================
#  cvmatchIA — Face ID Service (Google Cloud Vision)
#  Fichier : python/face_id_service.py
# ================================================================

import os
import json
import numpy as np
import mysql.connector
import secrets
import string
import logging
from datetime import datetime, timedelta
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from dotenv import load_dotenv
from google.cloud import vision
import base64

load_dotenv()

# ── CONFIGURATION ────────────────────────────────────────────────
SEUIL_DISTANCE = 0.45      # Seuil de similarité (plus petit = plus strict)
DUREE_TOKEN_MINUTES = 5

DB_CONFIG = {
    "host": os.getenv("DB_HOST"),
    "port": int(os.getenv("DB_PORT")),
    "database": os.getenv("DB_NAME"),
    "user": os.getenv("DB_USER"),
    "password": os.getenv("DB_PASSWORD"),
}

# Initialisation du client Google Cloud Vision
# Il lira la clé depuis GOOGLE_APPLICATION_CREDENTIALS (fichier JSON téléchargé)
client = vision.ImageAnnotatorClient()

app = FastAPI(title="SmartRecruit — Face ID (Google Vision)", version="3.0")
app.add_middleware(
    CORSMiddleware,
    allow_origins=["https://projetb-php-app-cvmatch.onrender.com"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("face_id")

# ── MODÈLES PYDANTIC ─────────────────────────────────────────────
class RequeteEnregistrement(BaseModel):
    photo_base64: str   # Image encodée en base64 (data:image/jpeg;base64,...)
    admin_id: int

class RequeteVerification(BaseModel):
    photo_base64: str
    admin_id: int

# ── UTILITAIRES ──────────────────────────────────────────────────
def get_db():
    return mysql.connector.connect(**DB_CONFIG)

def token_securise(n=64):
    return "".join(secrets.choice(string.ascii_letters + string.digits) for _ in range(n))

def extraire_landmarks(photo_base64: str):
    """
    Envoie l'image à Google Cloud Vision, récupère les landmarks du premier visage.
    Retourne un dictionnaire avec les coordonnées normalisées (x, y) pour chaque point.
    Lève une exception si aucun visage n'est détecté.
    """
    # Nettoyer le base64 (enlever le préfixe "data:image/jpeg;base64,")
    if ',' in photo_base64:
        photo_base64 = photo_base64.split(',')[1]
    image_bytes = base64.b64decode(photo_base64)

    image = vision.Image(content=image_bytes)
    response = client.face_detection(image=image)
    faces = response.face_annotations

    if not faces:
        raise ValueError("Aucun visage détecté sur l'image.")

    # Prendre le premier visage
    face = faces[0]
    landmarks = {}
    # Les landmarks disponibles sont : LEFT_EYE, RIGHT_EYE, LEFT_OF_LEFT_EYEBROW, etc.
    # Voir https://cloud.google.com/vision/docs/reference/rest/v1/Position
    for landmark in face.landmarks:
        # type : enum (ex: 'LEFT_EYE')
        landmarks[landmark.type_.name] = {
            "x": landmark.position.x,
            "y": landmark.position.y,
            "z": landmark.position.z
        }
    return landmarks

def comparer_landmarks(landmarks1, landmarks2):
    """
    Compare deux ensembles de landmarks (dictionnaires) en calculant la distance
    euclidienne moyenne entre les points correspondants.
    Retourne la distance moyenne.
    """
    points_communs = set(landmarks1.keys()) & set(landmarks2.keys())
    if not points_communs:
        return float('inf')
    distances = []
    for point in points_communs:
        p1 = np.array([landmarks1[point]['x'], landmarks1[point]['y'], landmarks1[point]['z']])
        p2 = np.array([landmarks2[point]['x'], landmarks2[point]['y'], landmarks2[point]['z']])
        distances.append(np.linalg.norm(p1 - p2))
    return np.mean(distances)

# ── ENDPOINTS ────────────────────────────────────────────────────

@app.post("/enregistrer-visage")
async def enregistrer_visage(req: RequeteEnregistrement):
    """
    Reçoit la photo de l'admin, extrait les landmarks via Google Vision,
    et les stocke dans la base (colonne face_landmarks).
    """
    log.info(f"Enregistrement Face ID pour admin #{req.admin_id}")
    try:
        landmarks = extraire_landmarks(req.photo_base64)
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))

    db = get_db()
    cursor = db.cursor()
    cursor.execute("""
        UPDATE administrateurs
        SET face_landmarks = %s, face_id_actif = 1
        WHERE id = %s
    """, (json.dumps(landmarks), req.admin_id))
    db.commit()
    cursor.close()
    db.close()

    return {"success": True, "message": "Visage enregistré avec succès."}


@app.post("/verifier-visage")
async def verifier_visage(req: RequeteVerification):
    """
    Reçoit la photo de l'admin, extrait les landmarks, compare avec ceux stockés
    en base, génère un token si la distance est inférieure au seuil.
    """
    log.info(f"Vérification Face ID pour admin #{req.admin_id}")

    # Récupérer les landmarks stockés
    db = get_db()
    cursor = db.cursor(dictionary=True)
    cursor.execute("""
        SELECT id, prenom, nom, face_landmarks
        FROM administrateurs
        WHERE id = %s AND face_id_actif = 1
    """, (req.admin_id,))
    admin = cursor.fetchone()
    cursor.close()
    db.close()

    if not admin or not admin.get('face_landmarks'):
        raise HTTPException(status_code=404, detail="Aucun visage enregistré pour cet administrateur.")

    stored_landmarks = json.loads(admin['face_landmarks'])

    try:
        new_landmarks = extraire_landmarks(req.photo_base64)
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))

    # Comparaison
    distance = comparer_landmarks(stored_landmarks, new_landmarks)
    log.info(f"Distance entre les visages : {distance:.4f} (seuil : {SEUIL_DISTANCE})")

    if distance > SEUIL_DISTANCE:
        return {"success": False, "message": f"Visage non reconnu (distance {distance:.3f})"}

    # Génération du token de session (pour être utilisé par PHP)
    token = token_securise()
    expire = datetime.now() + timedelta(minutes=DUREE_TOKEN_MINUTES)

    db = get_db()
    cursor = db.cursor()
    cursor.execute("""
        INSERT INTO sessions_face_id (admin_id, token, expire_a)
        VALUES (%s, %s, %s)
    """, (admin['id'], token, expire))
    cursor.execute("UPDATE administrateurs SET derniere_connexion = NOW() WHERE id = %s", (admin['id'],))
    db.commit()
    cursor.close()
    db.close()

    nom_complet = f"{admin['prenom']} {admin['nom']}"
    log.info(f"✅ Connexion Face ID réussie pour {nom_complet} (distance {distance:.4f})")

    return {
        "success": True,
        "token": token,
        "admin_nom": nom_complet,
        "message": f"Bienvenue {admin['prenom']} !"
    }


@app.get("/sante")
async def sante():
    """Healthcheck."""
    try:
        db = get_db()
        db.close()
        bd_ok = True
    except Exception:
        bd_ok = False
    return {
        "status": "ok" if bd_ok else "bd_error",
        "service": "Face ID — Google Cloud Vision",
        "vision_api": bool(client is not None)
    }

if __name__ == "__main__":
    import uvicorn
    print("🚀 Face ID Service (Google Vision) - Port 5000")
    uvicorn.run(app, host="0.0.0.0", port=5000)
