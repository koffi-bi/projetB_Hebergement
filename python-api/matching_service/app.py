# ================================================================
#  SmartRecruit — Microservice Matching IA (Groq)
#  Fichier : python/matching_service.py
#
#  Lancer : uvicorn matching_service:app --host 0.0.0.0 --port 5001 --reload
#
#  .env requis :
#      GROQ_API_KEY=gsk_xxxxxxxxxxxxxxxxxxxx
# ================================================================

import mysql.connector
import json
import re
import logging
from datetime import datetime
from typing import Optional
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from groq import Groq
from dotenv import load_dotenv
import os

load_dotenv()

# ================================================================
#  CONFIGURATION (modifiez uniquement cette section)
# ================================================================

DB = {
    "host": os.getenv("DB_HOST", "localhost"),
    "port": int(os.getenv("DB_PORT", 3306)),  
    "database": os.getenv("DB_NAME", "projetb"),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "charset": "utf8mb4"
}

GROQ_API_KEY = os.getenv("GROQ_API_KEY")
MODELE       = "llama-3.3-70b-versatile"   # modèle texte gratuit Groq
SCORE_MIN    = 10                           # score min pour apparaître dans les résultats

# ================================================================
#  INITIALISATION
# ================================================================

# Test de connexion au démarrage
try:
    _c = mysql.connector.connect(**DB)
    print(f"✅ MySQL connecté — base : {DB['database']}")
    _c.close()
except Exception as e:
    print(f"❌ MySQL échec : {e}")

groq_client = Groq(api_key=GROQ_API_KEY)

app = FastAPI(
    title    = "SmartRecruit — Matching IA (Groq)",
    version  = "3.0",
    docs_url = "/docs",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins     = ["*"],
    allow_credentials = True,
    allow_methods     = ["*"],
    allow_headers     = ["*"],
)

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("matching")

# ================================================================
#  MODÈLES PYDANTIC
# ================================================================

class RequeteChat(BaseModel):
    """
    Corps unique pour /ask et /agent.
    La même route gère conversation ET recherche — Groq décide.
    """
    question:         str  = Field(..., description="Message de l'admin en langage naturel")
    recruteur_id:     Optional[int] = None
    historique_chat:  list = Field(default=[], description="Échanges précédents pour le contexte")

class RequeteExtractionCV(BaseModel):
    texte_cv:    str
    candidat_id: int

# ================================================================
#  UTILITAIRES BASE DE DONNÉES
# ================================================================

def get_db():
    return mysql.connector.connect(**DB)

def propre(row: dict) -> dict:
    """Sérialise les types MySQL non-JSON (datetime, Decimal)."""
    out = {}
    for k, v in row.items():
        if isinstance(v, datetime):
            out[k] = v.isoformat()
        elif hasattr(v, "__float__"):
            out[k] = float(v)
        else:
            out[k] = v
    return out

def nettoyer_json(texte: str) -> str:
    """Retire les balises ```json``` que le modèle ajoute parfois."""
    texte = re.sub(r"^```(?:json)?\s*", "", texte, flags=re.MULTILINE)
    texte = re.sub(r"\s*```$",          "", texte, flags=re.MULTILINE)
    return texte.strip()

def schema_base() -> str:
    """
    Décrit le schéma de la base de données au modèle Groq.
    Ajoute ou modifie les colonnes ici si votre schéma change.
    """
    return """
TABLE candidats :
  id, prenom, nom, email, telephone, ville,
  genre ('homme'|'femme'|'autre'),
  poste_actuel, experience_ans (entier),
  competences (texte libre, ex: "PHP, MySQL, React"),
  disponibilite ('immediat'|'1_mois'|'3_mois'|'a_discuter'),
  niveau_etude, salaire_souhaite, bio,
  date_naissance (DATE), linkedin, statut ('actif'|'inactif')

TABLE cv :
  id, candidat_id (FK → candidats.id),
  nom_fichier, chemin (chemin relatif du fichier),
  type_fichier ('pdf'|'docx'|'jpg'|'png'),
  taille_ko, statut_analyse ('en_attente'|'analyse'|'erreur'),
  resume_ia (résumé généré par l'IA),
  date_upload

TABLE administrateurs :
  id, email, prenom, nom, role
"""

# ================================================================
#  CŒUR IA — GROQ DÉCIDE DE L'INTENTION
# ================================================================

def groq_detecter_intention(question: str, historique: list) -> dict:
    """
    Étape 1 : Groq analyse le message et décide s'il s'agit :
      - d'une RECHERCHE (chercher des candidats selon des critères)
      - d'une CONVERSATION (salutation, question générale, aide)
      - d'une STATISTIQUE (compter, résumer, lister)

    Retourne un dict :
    {
      "type": "recherche" | "conversation" | "statistique",
      "reponse_directe": "..." ou null,   // pour conversation simple
      "criteres": { ... }                 // pour recherche
    }
    """
    historique_str = ""
    for msg in historique[-4:]:
        role  = "Admin" if msg.get("role") == "user" else "IA"
        historique_str += f"{role}: {msg.get('content','')}\n"

    prompt = f"""Tu es l'assistant IA d'un dashboard de recrutement.
Tu reçois un message de l'administrateur RH et tu dois décider quoi faire.

SCHÉMA DE LA BASE DE DONNÉES :
{schema_base()}

HISTORIQUE RÉCENT :
{historique_str or "(aucun)"}

MESSAGE ACTUEL : "{question}"

RÈGLES DE CLASSIFICATION :
- Si le message contient des critères de recherche de candidats (genre, ville, compétences, expérience, disponibilité, poste, etc.) → type = "recherche"
- Si le message est une salutation, question générale sur le système, demande d'aide → type = "conversation"
- Si le message demande des statistiques, comptages, résumés globaux → type = "statistique"

Retourne UNIQUEMENT ce JSON valide :
{{
  "type": "recherche" | "conversation" | "statistique",
  "reponse_directe": "réponse en français si type=conversation, sinon null",
  "criteres": {{
    "genre": "femme" | "homme" | null,
    "ville": "ville en minuscules" | null,
    "exp_min": nombre | null,
    "exp_max": nombre | null,
    "competences": ["liste", "de", "compétences"] | [],
    "disponibilite": "immediat" | "1_mois" | "3_mois" | null,
    "niveau": "Master (Bac+5)" | "Licence (Bac+3)" | "Ingénieur" | null,
    "poste": "mots-clés du poste" | null,
    "age_max": nombre | null,
    "avec_cv": true | false
  }}
}}"""

    resp = groq_client.chat.completions.create(
        model    = MODELE,
        messages = [{"role": "user", "content": prompt}],
        max_tokens  = 400,
        temperature = 0.1,
    )

    try:
        return json.loads(nettoyer_json(resp.choices[0].message.content))
    except json.JSONDecodeError:
        log.error("Groq intention JSON invalide — fallback conversation.")
        return {"type": "conversation", "reponse_directe": "Je n'ai pas compris votre demande. Pouvez-vous reformuler ?", "criteres": {}}


def groq_construire_sql(criteres: dict) -> str:
    """
    Étape 2 (si recherche) : Groq construit la requête SQL
    en joignant toutes les tables concernées selon les critères.
    """
    prompt = f"""Tu es un expert SQL MySQL. Construis une requête SQL SELECT pour chercher des candidats.

SCHÉMA :
{schema_base()}

CRITÈRES FOURNIS :
{json.dumps(criteres, ensure_ascii=False, indent=2)}

RÈGLES IMPORTANTES :
1. Toujours faire JOIN avec la table cv pour récupérer le chemin du CV (LEFT JOIN)
2. Sélectionner au minimum : c.id, c.prenom, c.nom, c.email, c.telephone, c.ville,
   c.genre, c.poste_actuel, c.experience_ans, c.competences, c.disponibilite,
   c.niveau_etude, c.photo, c.date_naissance,
   cv.chemin AS cv_chemin, cv.nom_fichier AS cv_nom, cv.type_fichier,
   cv.resume_ia, cv.statut_analyse
3. Utiliser LIKE '%valeur%' pour competences et poste_actuel (recherche partielle)
4. Pour le genre : utiliser LOWER(c.genre) = 'femme' (ou 'homme')
5. WHERE c.statut = 'actif' TOUJOURS
6. ORDER BY c.experience_ans DESC
7. LIMIT 50
8. Retourner UNIQUEMENT la requête SQL, sans texte autour, sans ```

Exemples de syntaxe :
- genre : LOWER(c.genre) = 'femme'
- compétences : c.competences LIKE '%PHP%' AND c.competences LIKE '%MySQL%'
- expérience : c.experience_ans >= 2 AND c.experience_ans <= 5
- ville : LOWER(c.ville) LIKE '%abidjan%'"""

    resp = groq_client.chat.completions.create(
        model    = MODELE,
        messages = [{"role": "user", "content": prompt}],
        max_tokens  = 300,
        temperature = 0.0,   # déterministe pour le SQL
    )

    sql = resp.choices[0].message.content.strip()
    # Nettoyer les backticks résiduels
    sql = re.sub(r"```sql\s*", "", sql)
    sql = re.sub(r"```\s*",    "", sql)
    return sql.strip()


def groq_scorer_et_resumer(candidat: dict, criteres: dict, question_orig: str) -> tuple[int, str]:
    """
    Étape 3 : Groq attribue un score 0-100 et rédige un résumé
    personnalisé expliquant pourquoi ce profil correspond.
    """
    profil = {
        "nom":         f"{candidat.get('prenom','')} {candidat.get('nom','')}",
        "poste":       candidat.get("poste_actuel", "—"),
        "experience":  f"{candidat.get('experience_ans', 0)} ans",
        "ville":       candidat.get("ville", "—"),
        "competences": candidat.get("competences", "—"),
        "niveau":      candidat.get("niveau_etude", "—"),
        "disponible":  candidat.get("disponibilite", "—"),
        "bio":         (candidat.get("bio") or "")[:150],
        "cv_present":  bool(candidat.get("cv_chemin")),
    }

    resp = groq_client.chat.completions.create(
        model    = MODELE,
        messages = [{"role": "user", "content": f"""Note ce candidat de 0 à 100 par rapport à la demande.

DEMANDE : "{question_orig}"
CRITÈRES : {json.dumps(criteres, ensure_ascii=False)}
CANDIDAT : {json.dumps(profil, ensure_ascii=False)}

Retourne UNIQUEMENT ce JSON :
{{"score": <0-100>, "resume": "<2 phrases expliquant l'adéquation>"}}

Barème : 90-100 parfait | 70-89 bon | 50-69 partiel | <50 faible"""}],
        max_tokens  = 120,
        temperature = 0.2,
    )

    try:
        r = json.loads(nettoyer_json(resp.choices[0].message.content))
        return max(0, min(100, int(r.get("score", 0)))), r.get("resume", "")
    except Exception:
        return 50, "Profil potentiellement pertinent."


def groq_statistiques(question: str) -> str:
    """
    Répond aux questions statistiques en interrogeant la BD
    et en demandant à Groq de formuler la réponse.
    """
    db  = get_db()
    cur = db.cursor(dictionary=True)

    stats = {}
    try:
        cur.execute("SELECT COUNT(*) AS n FROM candidats WHERE statut='actif'")
        stats["total_candidats"] = cur.fetchone()["n"]

        cur.execute("SELECT COUNT(*) AS n FROM cv")
        stats["total_cv"] = cur.fetchone()["n"]

        cur.execute("SELECT COUNT(*) AS n FROM cv WHERE statut_analyse='analyse'")
        stats["cv_analyses"] = cur.fetchone()["n"]

        cur.execute("SELECT COUNT(*) AS n FROM candidats WHERE LOWER(genre)='femme' AND statut='actif'")
        stats["femmes"] = cur.fetchone()["n"]

        cur.execute("SELECT COUNT(*) AS n FROM candidats WHERE LOWER(genre)='homme' AND statut='actif'")
        stats["hommes"] = cur.fetchone()["n"]

        cur.execute("SELECT AVG(experience_ans) AS moy FROM candidats WHERE statut='actif'")
        moy = cur.fetchone()["moy"]
        stats["experience_moyenne"] = round(float(moy), 1) if moy else 0

        cur.execute("SELECT ville, COUNT(*) AS n FROM candidats WHERE statut='actif' GROUP BY ville ORDER BY n DESC LIMIT 5")
        stats["top_villes"] = [r for r in cur.fetchall()]

    finally:
        cur.close(); db.close()

    resp = groq_client.chat.completions.create(
        model    = MODELE,
        messages = [{"role": "user", "content": (
            f"Tu es un assistant RH. Voici les statistiques de la base de recrutement :\n"
            f"{json.dumps(stats, ensure_ascii=False, indent=2)}\n\n"
            f"Question : \"{question}\"\n\n"
            "Réponds en français de façon claire et concise (3-5 phrases max)."
        )}],
        max_tokens  = 200,
        temperature = 0.3,
    )
    return resp.choices[0].message.content.strip()


# ================================================================
#  ENDPOINT PRINCIPAL — /ask  (et alias /agent)
# ================================================================

@app.post("/ask", summary="Recherche ou conversation IA", tags=["IA"])
@app.post("/agent", summary="Alias /ask pour le dashboard", tags=["IA"])
async def ask(body: RequeteChat):
    """
    Point d'entrée unique.
    Groq détecte automatiquement l'intention :

    - CONVERSATION  → réponse directe en texte
    - STATISTIQUE   → interroge la BD + réponse formulée par Groq
    - RECHERCHE     → SQL généré par Groq + scoring des résultats

    Retourne toujours :
    {
      "success": true,
      "type":    "conversation" | "statistique" | "recherche",
      "reponse": "texte",
      "results": [...],       // vide si conversation/statistique
      "sql_generated": "..."  // debug, vide si non-recherche
    }
    """
    question  = body.question.strip()
    historique = body.historique_chat

    if not question:
        raise HTTPException(400, "La question est vide.")

    log.info(f"POST /ask — '{question[:70]}'")

    # ── Étape 1 : Groq détecte l'intention ──────────────────
    intention = groq_detecter_intention(question, historique)
    type_msg  = intention.get("type", "conversation")

    log.info(f"Intention détectée : {type_msg}")

    # ── CAS 1 : Conversation simple ──────────────────────────
    if type_msg == "conversation":
        reponse = intention.get("reponse_directe") or (
            "Bonjour ! Je suis votre assistant RH propulsé par Groq. "
            "Décrivez-moi le profil que vous recherchez et je trouverai les candidats correspondants. "
            "Exemple : « Je cherche un développeur PHP avec 2 ans d'expérience à Abidjan »."
        )
        return {
            "success": True,
            "type":    "conversation",
            "reponse": reponse,
            "results": [],
            "sql_generated": "",
        }

    # ── CAS 2 : Statistiques ─────────────────────────────────
    if type_msg == "statistique":
        reponse = groq_statistiques(question)
        return {
            "success": True,
            "type":    "statistique",
            "reponse": reponse,
            "results": [],
            "sql_generated": "",
        }

    # ── CAS 3 : Recherche de candidats ───────────────────────
    criteres = intention.get("criteres", {})

    # Étape 2 : Groq construit le SQL
    sql = groq_construire_sql(criteres)
    log.info(f"SQL généré : {sql}")

    # Vérification de sécurité : uniquement SELECT
    if not re.match(r"^\s*SELECT\s", sql, re.IGNORECASE):
        log.error(f"SQL refusé (non-SELECT) : {sql}")
        raise HTTPException(400, "Le SQL généré n'est pas un SELECT. Reformulez votre demande.")

    # Étape 3 : Exécuter le SQL
    try:
        db  = get_db()
        cur = db.cursor(dictionary=True)
        cur.execute(sql)
        candidats = cur.fetchall()
        cur.close(); db.close()
    except mysql.connector.Error as e:
        log.error(f"SQL erreur : {e}\nSQL : {sql}")
        # Groq reformule le SQL en cas d'erreur
        sql = groq_construire_sql({**criteres, "_erreur": str(e)})
        try:
            db  = get_db()
            cur = db.cursor(dictionary=True)
            cur.execute(sql)
            candidats = cur.fetchall()
            cur.close(); db.close()
        except Exception as e2:
            raise HTTPException(500, f"Erreur SQL persistante : {e2}")

    log.info(f"{len(candidats)} candidats trouvés.")

    # Étape 4 : Groq score chaque candidat
    resultats = []
    for cand in candidats:
        cand_propre = propre(dict(cand))
        score, resume = groq_scorer_et_resumer(cand_propre, criteres, question)
        if score >= SCORE_MIN:
            resultats.append({
                **cand_propre,
                "score":     score,
                "resume_ia": resume,
                # Champs normalisés pour le dashboard PHP
                "nom":        f"{cand_propre.get('prenom','')} {cand_propre.get('nom','')}".strip(),
                "profession": cand_propre.get("poste_actuel", ""),
                "experience": f"{cand_propre.get('experience_ans', 0)} ans",
            })

    # Tri par score décroissant
    resultats.sort(key=lambda x: x["score"], reverse=True)

    # Message de synthèse de Groq
    nb = len(resultats)
    reponse_synthese = (
        f"J'ai trouvé {nb} candidat{'s' if nb > 1 else ''} correspondant à votre recherche."
        if nb > 0
        else "Aucun candidat ne correspond exactement à vos critères. Essayez des critères plus larges."
    )

    return {
        "success":       True,
        "type":          "recherche",
        "reponse":       reponse_synthese,
        "results":       resultats,
        "count":         nb,
        "sql_generated": sql,
    }


# ================================================================
#  ENDPOINT : /cvs — Tous les candidats
# ================================================================

@app.get("/cvs", summary="Liste tous les candidats", tags=["Données"])
async def get_all_cvs(limit: int = 100):
    try:
        db  = get_db()
        cur = db.cursor(dictionary=True)
        cur.execute("""
            SELECT c.id, c.prenom, c.nom, c.email, c.ville, c.poste_actuel,
                   c.experience_ans, c.competences, c.photo,
                   cv.chemin AS cv_chemin, cv.nom_fichier AS cv_nom
            FROM candidats c
            LEFT JOIN cv ON cv.id = (
                SELECT id FROM cv WHERE candidat_id = c.id
                ORDER BY date_upload DESC LIMIT 1
            )
            WHERE c.statut = 'actif'
            ORDER BY c.date_inscription DESC
            LIMIT %s
        """, (limit,))
        rows = [propre(r) for r in cur.fetchall()]
        cur.close(); db.close()
        return {"success": True, "count": len(rows), "cvs": rows}
    except Exception as e:
        return {"success": False, "error": str(e)}


# ================================================================
#  ENDPOINT : /extraire-cv — Analyse CV par Groq
# ================================================================

@app.post("/extraire-cv", summary="Extraire données d'un CV", tags=["CV"])
async def extraire_cv(body: RequeteExtractionCV):
    if not body.texte_cv.strip():
        raise HTTPException(400, "Texte CV vide.")

    resp = groq_client.chat.completions.create(
        model    = MODELE,
        messages = [{"role": "user", "content": f"""Analyse ce CV et retourne UNIQUEMENT un JSON valide.

CV :
---
{body.texte_cv[:3000]}
---

JSON attendu :
{{
  "poste":        "intitulé du poste principal",
  "experience":   nombre_années_entier,
  "competences":  ["comp1", "comp2"],
  "niveau":       "Licence (Bac+3) | Master (Bac+5) | Ingénieur | ...",
  "resume":       "résumé 2-3 phrases"
}}"""}],
        max_tokens  = 400,
        temperature = 0.1,
    )

    try:
        donnees = json.loads(nettoyer_json(resp.choices[0].message.content))
    except json.JSONDecodeError:
        raise HTTPException(500, "JSON invalide retourné par Groq.")

    db  = get_db()
    cur = db.cursor()
    cur.execute("""
        UPDATE candidats
        SET poste_actuel=?, experience_ans=?, competences=?, niveau_etude=?, bio=?
        WHERE id=?
    """, (
        donnees.get("poste",""),
        int(donnees.get("experience", 0)),
        ", ".join(donnees.get("competences", [])),
        donnees.get("niveau",""),
        donnees.get("resume",""),
        body.candidat_id,
    ))
    cur.execute(
        "UPDATE cv SET statut_analyse='analyse', resume_ia=? WHERE candidat_id=? ORDER BY date_upload DESC LIMIT 1",
        (donnees.get("resume",""), body.candidat_id)
    )
    db.commit(); cur.close(); db.close()

    return {"success": True, "donnees": donnees, "message": "CV analysé et profil mis à jour."}


# ================================================================
#  ENDPOINT : /sante — Healthcheck
# ================================================================

@app.get("/sante", summary="Healthcheck", tags=["Système"])
async def sante():
    try:
        db = get_db(); db.close(); bd_ok = True
    except Exception:
        bd_ok = False
    return {
        "status":  "ok" if bd_ok else "erreur_bd",
        "service": "SmartRecruit Matching IA — FastAPI + Groq",
        "modele":  MODELE,
        "bd":      bd_ok,
        "groq":    bool(GROQ_API_KEY),
    }


@app.get("/", tags=["Système"])
async def root():
    return {
        "service":   "SmartRecruit Matching IA",
        "version":   "3.0",
        "engine":    "Groq — llama-3.3-70b-versatile",
        "endpoints": ["POST /ask", "POST /agent", "GET /cvs", "GET /sante", "GET /docs"],
    }


# ================================================================
#  DÉMARRAGE
# ================================================================

if __name__ == "__main__":
    import uvicorn
    print("\n" + "="*55)
    print("  SmartRecruit — Matching IA (Groq)")
    print(f"  Modèle  : {MODELE}")
    print(f"  Base    : {DB['database']}")
    print(f"  Groq    : {'✅ Configuré' if GROQ_API_KEY else '❌ GROQ_API_KEY manquante'}")
    print("  Swagger : http://localhost:5001/docs")
    print("="*55 + "\n")
    uvicorn.run("matching_service:app", host="0.0.0.0", port=5001, reload=True)
