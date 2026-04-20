# Dans face_id_service.py, remplacez la classe RequeteVerification et l'endpoint

class RequeteVerification(BaseModel):
    photo_base64: str
    # plus besoin de admin_id

@app.post("/verifier-visage")
async def verifier_visage(req: RequeteVerification):
    """
    Reçoit la photo, compare avec tous les admins ayant un visage enregistré,
    retourne le token si la distance est inférieure au seuil.
    """
    log.info("Vérification Face ID - recherche parmi tous les admins")

    # Récupérer TOUS les admins avec face_landmarks
    db = get_db()
    cursor = db.cursor(dictionary=True)
    cursor.execute("""
        SELECT id, prenom, nom, face_landmarks
        FROM administrateurs
        WHERE face_id_actif = 1 AND face_landmarks IS NOT NULL
    """)
    admins = cursor.fetchall()
    cursor.close()
    db.close()

    if not admins:
        raise HTTPException(status_code=404, detail="Aucun administrateur n'a activé Face ID.")

    try:
        new_landmarks = extraire_landmarks(req.photo_base64)
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))

    meilleur_admin = None
    meilleure_distance = float('inf')

    for admin in admins:
        stored_landmarks = json.loads(admin['face_landmarks'])
        distance = comparer_landmarks(stored_landmarks, new_landmarks)
        log.info(f"Admin {admin['prenom']} {admin['nom']} : distance {distance:.4f}")
        if distance < meilleure_distance:
            meilleure_distance = distance
            meilleur_admin = admin

    if meilleure_distance > SEUIL_DISTANCE:
        return {"success": False, "message": f"Visage non reconnu (distance {meilleure_distance:.3f})"}

    # Génération du token
    token = token_securise()
    expire = datetime.now() + timedelta(minutes=DUREE_TOKEN_MINUTES)

    db = get_db()
    cursor = db.cursor()
    cursor.execute("""
        INSERT INTO sessions_face_id (admin_id, token, expire_a)
        VALUES (%s, %s, %s)
    """, (meilleur_admin['id'], token, expire))
    cursor.execute("UPDATE administrateurs SET derniere_connexion = NOW() WHERE id = %s", (meilleur_admin['id'],))
    db.commit()
    cursor.close()
    db.close()

    nom_complet = f"{meilleur_admin['prenom']} {meilleur_admin['nom']}"
    log.info(f"✅ Connexion Face ID réussie pour {nom_complet} (distance {meilleure_distance:.4f})")

    return {
        "success": True,
        "token": token,
        "admin_nom": nom_complet,
        "message": f"Bienvenue {meilleur_admin['prenom']} !"
    }
