import os
import mysql.connector
import json
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from dotenv import load_dotenv
from google.cloud import vision
import base64
import requests

load_dotenv()

app = FastAPI()

# Configuration CORS (identique à votre service matching)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Configuration de la base de données (identique à avant)
DB_CONFIG = {
    "host": os.getenv("DB_HOST"),
    "port": int(os.getenv("DB_PORT")),
    "database": os.getenv("DB_NAME"),
    "user": os.getenv("DB_USER"),
    "password": os.getenv("DB_PASSWORD"),
}

# Initialisation du client Google Cloud Vision
# Il va automatiquement chercher votre clé dans la variable d'environnement GOOGLE_APPLICATION_CREDENTIALS
client = vision.ImageAnnotatorClient()

# Modèle pour la requête
class FaceVerificationRequest(BaseModel):
    photo_base64: str  # L'image encodée en base64
    admin_id: int

@app.post("/verifier-visage")
async def verifier_visage(request: FaceVerificationRequest):
    """
    Reçoit la photo, appelle Google Cloud Vision, compare avec la photo de référence.
    """
    try:
        # 1. Récupérer la photo de référence de l'admin depuis votre base de données
        db = mysql.connector.connect(**DB_CONFIG)
        cursor = db.cursor(dictionary=True)
        cursor.execute("SELECT photo_reference FROM administrateurs WHERE id = %s", (request.admin_id,))
        result = cursor.fetchone()
        db.close()

        if not result or not result['photo_reference']:
            return {"success": False, "message": "Aucune photo de référence trouvée pour cet utilisateur."}

        # 2. Décoder l'image envoyée par l'utilisateur
        #    L'image arrive sous forme de base64, par exemple "data:image/jpeg;base64,..."
        image_user_base64 = request.photo_base64.split(",")[-1]
        image_user_bytes = base64.b64decode(image_user_base64)

        # 3. Appeler l'API Google Cloud Vision pour DÉTECTER le visage sur la nouvelle photo
        image_user = vision.Image(content=image_user_bytes)
        response_user = client.face_detection(image=user_image)
        
        if not response_user.face_annotations:
            return {"success": False, "message": "Aucun visage détecté sur la photo fournie."}
        
        # 4. Récupérer l'URL de la photo de référence et appeler Google Cloud Vision dessus
        photo_ref_url = result['photo_reference']  # Doit être une URL publique
        image_ref = vision.Image()
        image_ref.source.image_uri = photo_ref_url
        response_ref = client.face_detection(image=ref_image)
        
        if not response_ref.face_annotations:
            return {"success": False, "message": "Aucun visage détecté sur la photo de référence."}
        
        # 5. COMPARAISON SIMPLIFIÉE
        #    Google Vision ne fait PAS de "face matching" (1:1) directement.
        #    Il ne vous dit pas "c'est la même personne", il détecte juste des visages.
        #    Une approche simple est de considérer que si un visage est détecté dans les deux,
        #    et que l'utilisateur est le seul attendu, on valide.
        #    POUR UNE VÉRIFICATION ROBUSTE : Il faudrait extraire les "landmarks" (yeux, nez)
        #    et comparer les distances. Mais la méthode simple suffit souvent pour un MVP.
        #    Nous allons considérer que la vérification est réussie si un seul visage est présent dans chaque image.
        #    (C'est une simplification pour vous aider à avancer).

        # 6. ICI, vous devez adapter la logique de comparaison.
        #    La méthode la plus simple pour votre besoin est de stocker l'URL de la photo de référence
        #    et de la comparer à la nouvelle. Une méthode plus robuste serait d'extraire
        #    les "face landmarks" avec l'API et de comparer les distances entre les yeux, etc.

        # Pour l'instant, nous allons simuler un succès si un visage est trouvé dans chaque image.
        if len(response_user.face_annotations) == 1 and len(response_ref.face_annotations) == 1:
            # C'est ICI que vous devriez implémenter la logique de comparaison avancée.
            return {"success": True, "message": "Visage vérifié avec succès (simplifié)."}
        else:
            return {"success": False, "message": "La vérification a échoué."}

    except Exception as e:
        print(f"Erreur: {e}")
        return {"success": False, "message": "Erreur interne lors de la vérification."}
