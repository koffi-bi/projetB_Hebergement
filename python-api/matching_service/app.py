# ================================================================
#  SmartRecruit — Matching IA (Groq) v6
#  Lancer : uvicorn matching_service:app --host 0.0.0.0 --port 5001 --reload
#
#  .env requis :
#      GROQ_API_KEY=gsk_xxxxxxxxxxxxxxxxxxxx
#      RACINE_PROJET=C:\xampp\htdocs\ProjetB
#
#  pip install fastapi uvicorn groq mysql-connector-python
#              python-dotenv pdfplumber python-docx
# ================================================================

import os, re, json, time, logging
from datetime import datetime
from pathlib import Path
from typing import Optional

import mysql.connector
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from groq import Groq, RateLimitError
from dotenv import load_dotenv

load_dotenv()

# ────────────────────────────────────────────────────────────────
#  CONFIG
# ────────────────────────────────────────────────────────────────

DB = {
    "host":     os.getenv("DB_HOST",     "localhost"),
    "database": os.getenv("DB_NAME",     "projetb"),
    "user":     os.getenv("DB_USER",     "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "charset":  "utf8mb4",
}

GROQ_API_KEY  = os.getenv("GROQ_API_KEY", "")
RACINE_PROJET = os.getenv("RACINE_PROJET", r"C:\xampp\htdocs\ProjetB")
MODELE        = "llama-3.3-70b-versatile"
SCORE_MIN     = 10   # score minimum pour afficher un candidat

# ────────────────────────────────────────────────────────────────
#  INITIALISATION
# ────────────────────────────────────────────────────────────────

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("matching")

try:
    _test = mysql.connector.connect(**DB)
    _test.close()
    log.info(f"MySQL OK — base : {DB['database']}")
except Exception as e:
    log.error(f"MySQL ERREUR : {e}")

groq_client = Groq(api_key=GROQ_API_KEY)

app = FastAPI(title="SmartRecruit Matching IA", version="6.0", docs_url="/docs")
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"], allow_methods=["*"],
    allow_headers=["*"], allow_credentials=True,
)

# ────────────────────────────────────────────────────────────────
#  MODELES
# ────────────────────────────────────────────────────────────────

class RequeteChat(BaseModel):
    question:        str  = Field(..., description="Message du recruteur")
    recruteur_id:    Optional[int] = None
    historique_chat: list = Field(default=[])

class RequeteExtractionCV(BaseModel):
    texte_cv:    str
    candidat_id: int

class RequeteAnalyserFichier(BaseModel):
    candidat_id: int
    chemin_cv:   str

# ────────────────────────────────────────────────────────────────
#  APPEL GROQ — avec retry sur rate limit 429
#
#  Toute la communication avec Groq passe par cette fonction.
#  Si la limite de tokens est atteinte, on attend et on reessaie
#  au lieu de planter avec une erreur 500.
# ────────────────────────────────────────────────────────────────

def appeler_groq(messages: list, max_tokens: int = 300) -> str:
    """
    Envoie les messages a Groq et retourne le texte de la reponse.
    Attend automatiquement si la limite de tokens est atteinte (429).
    Leve HTTPException si impossible apres 3 tentatives.
    """
    for tentative in range(1, 4):
        try:
            resp = groq_client.chat.completions.create(
                model       = MODELE,
                messages    = messages,
                max_tokens  = max_tokens,
                temperature = 0.1,
            )
            return resp.choices[0].message.content.strip()

        except RateLimitError as e:
            # Extraire le temps d'attente du message Groq
            match = re.search(r"try again in (\d+)m([\d.]+)s", str(e))
            attente = int(match.group(1)) * 60 + float(match.group(2)) if match else 30
            attente = min(attente, 60)  # max 60s d'attente

            log.warning(f"Rate limit Groq — tentative {tentative}/3, attente {attente:.0f}s")

            if tentative == 3:
                raise HTTPException(429,
                    "Limite de tokens Groq atteinte. "
                    "Reessayez dans quelques minutes ou upgradez sur console.groq.com")
            time.sleep(attente)

        except Exception as e:
            raise HTTPException(500, f"Erreur Groq : {e}")

    return ""


def nettoyer_json(texte: str) -> str:
    """Supprime les balises markdown que Groq ajoute parfois autour du JSON."""
    texte = re.sub(r"^```(?:json)?\s*", "", texte, flags=re.MULTILINE)
    texte = re.sub(r"\s*```$",          "", texte, flags=re.MULTILINE)
    return texte.strip()


# ────────────────────────────────────────────────────────────────
#  BASE DE DONNEES
# ────────────────────────────────────────────────────────────────

def get_db():
    return mysql.connector.connect(**DB)


def serialiser(row: dict) -> dict:
    """Convertit les types MySQL (datetime, Decimal) en types JSON."""
    out = {}
    for k, v in row.items():
        if isinstance(v, datetime):
            out[k] = v.isoformat()
        elif hasattr(v, "__float__"):
            out[k] = float(v)
        else:
            out[k] = v
    return out


# ────────────────────────────────────────────────────────────────
#  EXTRACTION DU TEXTE DES FICHIERS CV
# ────────────────────────────────────────────────────────────────

def lire_pdf(chemin: str) -> str:
    texte = ""
    try:
        import pdfplumber
        with pdfplumber.open(chemin) as pdf:
            for page in pdf.pages:
                t = page.extract_text()
                if t:
                    texte += t + "\n"
    except Exception as e:
        log.warning(f"PDF lecture : {e}")
    return texte.strip()


def lire_docx(chemin: str) -> str:
    texte = ""
    try:
        from docx import Document
        doc = Document(chemin)
        for para in doc.paragraphs:
            texte += para.text + "\n"
        for table in doc.tables:
            for row in table.rows:
                texte += " | ".join(c.text for c in row.cells) + "\n"
    except Exception as e:
        log.warning(f"DOCX lecture : {e}")
    return texte.strip()


def lire_fichier_cv(chemin_relatif: str) -> str:
    """
    Lit le fichier CV et retourne son texte brut.
    Reconstruit le chemin absolu a partir de RACINE_PROJET.
    """
    chemin = os.path.join(RACINE_PROJET, chemin_relatif.lstrip("/\\"))
    if not os.path.exists(chemin):
        log.error(f"Fichier introuvable : {chemin}")
        return ""

    ext = Path(chemin).suffix.lower()
    if ext == ".pdf":
        return lire_pdf(chemin)
    if ext in (".docx", ".doc"):
        return lire_docx(chemin)
    return ""


# ────────────────────────────────────────────────────────────────
#  ETAPE 1 — Groq comprend la question du recruteur
#
#  On envoie UN SEUL prompt court avec tout ce qu'il faut.
#  Groq repond en JSON avec le type (recherche/conversation/stat)
#  et les criteres de recherche normalises.
#
#  ECONOMIE DE TOKENS : prompt compact, historique limite a 2 echanges.
# ────────────────────────────────────────────────────────────────

PROMPT_INTENTION = """Tu es l'assistant RH d'un dashboard de recrutement en Cote d'Ivoire.

TABLES DISPONIBLES :
- candidats : id, prenom, nom, ville, genre, poste_actuel, experience_ans, competences, disponibilite, niveau_etude, bio, statut
- cv : candidat_id, resume_ia (analyse complete du CV : competences, villes, secteurs, formations)

MESSAGE RECRUTEUR : "{question}"

REGLES :
1. Corriger les fautes ("chaiche"=cherche, "comaercial"=commercial, "yamossoukro"=Yamoussoukro)
2. Si c'est une recherche de profil -> type=recherche
3. Si c'est une salutation ou question generale -> type=conversation
4. Si c'est une demande de stats/chiffres -> type=statistique

Reponds UNIQUEMENT avec ce JSON valide :
{{
  "type": "recherche" | "conversation" | "statistique",
  "reponse_directe": "texte si conversation, sinon null",
  "requete_normalisee": "la requete en francais correct",
  "criteres": {{
    "poste": "intitule du poste ou null",
    "ville": "ville en minuscules ou null",
    "genre": "homme" | "femme" | null,
    "exp_min": nombre ou null,
    "competences": ["comp1", "comp2"],
    "mots_cles": ["tous les mots importants de la requete"]
  }}
}}"""


def comprendre_question(question: str, historique: list) -> dict:
    """
    Etape 1 : Groq analyse la question et retourne les criteres.
    Prompt tres court = peu de tokens consommes.
    """
    # Historique limite aux 2 derniers echanges pour economiser les tokens
    ctx = ""
    for msg in historique[-2:]:
        role = "Recruteur" if msg.get("role") == "user" else "IA"
        ctx += f"{role}: {msg.get('content','')}\n"

    prompt = PROMPT_INTENTION.format(question=question)
    if ctx:
        prompt = f"CONTEXTE RECENT:\n{ctx}\n\n" + prompt

    reponse = appeler_groq(
        messages   = [{"role": "user", "content": prompt}],
        max_tokens = 350,
    )

    try:
        return json.loads(nettoyer_json(reponse))
    except json.JSONDecodeError:
        # Fallback : traiter comme recherche avec les mots de la question
        log.warning("JSON intention invalide — fallback recherche brute")
        return {
            "type": "recherche",
            "reponse_directe": None,
            "requete_normalisee": question,
            "criteres": {
                "poste": None,
                "ville": None,
                "genre": None,
                "exp_min": None,
                "competences": [],
                "mots_cles": [m for m in question.split() if len(m) > 2],
            }
        }


# ────────────────────────────────────────────────────────────────
#  ETAPE 2 — Construction du SQL en Python pur
#
#  On ne laisse PAS Groq ecrire le SQL — ca causait des erreurs 1064.
#  Le SQL est construit directement en Python a partir des criteres
#  retournes par Groq. Zero risque d'erreur de syntaxe.
#
#  Chaque critere cherche dans le formulaire ET dans cv.resume_ia
#  (le texte complet du CV analyse).
# ────────────────────────────────────────────────────────────────

def construire_sql(criteres: dict) -> str:
    """
    Construit le SQL de recherche en Python pur.
    Cherche dans les champs du formulaire ET dans cv.resume_ia (contenu du CV).
    """
    def esc(s: str) -> str:
        """Echappe les apostrophes pour eviter les injections SQL."""
        return s.replace("'", "\\'")

    conditions = ["c.statut = 'actif'"]

    # Genre
    if criteres.get("genre"):
        conditions.append(f"LOWER(c.genre) = '{esc(criteres['genre'].lower())}'")

    # Experience minimale
    if criteres.get("exp_min") is not None:
        conditions.append(f"c.experience_ans >= {int(criteres['exp_min'])}")

    # Ville — cherche dans la fiche ET dans le CV analyse
    if criteres.get("ville"):
        v = esc(criteres["ville"].lower())
        conditions.append(
            f"(LOWER(c.ville) LIKE '%{v}%' OR LOWER(cv.resume_ia) LIKE '%{v}%')"
        )

    # Poste — cherche dans la fiche ET dans le CV analyse
    if criteres.get("poste"):
        p = esc(criteres["poste"].lower())
        conditions.append(
            f"(LOWER(c.poste_actuel) LIKE '%{p}%' "
            f"OR LOWER(c.competences) LIKE '%{p}%' "
            f"OR LOWER(c.bio) LIKE '%{p}%' "
            f"OR LOWER(cv.resume_ia) LIKE '%{p}%')"
        )

    # Competences — chacune cherchee dans la fiche ET le CV
    for comp in criteres.get("competences", []):
        if comp.strip():
            c = esc(comp.strip().lower())
            conditions.append(
                f"(LOWER(c.competences) LIKE '%{c}%' "
                f"OR LOWER(c.bio) LIKE '%{c}%' "
                f"OR LOWER(cv.resume_ia) LIKE '%{c}%')"
            )

    # Mots-cles libres — cherche dans TOUS les champs texte
    for mot in criteres.get("mots_cles", []):
        if mot.strip() and len(mot.strip()) > 2:
            m = esc(mot.strip().lower())
            conditions.append(
                f"(LOWER(c.poste_actuel) LIKE '%{m}%' "
                f"OR LOWER(c.competences) LIKE '%{m}%' "
                f"OR LOWER(c.bio) LIKE '%{m}%' "
                f"OR LOWER(c.ville) LIKE '%{m}%' "
                f"OR LOWER(cv.resume_ia) LIKE '%{m}%')"
            )

    where = " AND ".join(conditions)

    return (
        "SELECT c.id, c.prenom, c.nom, c.email, c.telephone, c.ville, "
        "c.genre, c.poste_actuel, c.experience_ans, c.competences, "
        "c.disponibilite, c.niveau_etude, c.photo, c.date_naissance, c.bio, "
        "cv.chemin AS cv_chemin, cv.nom_fichier AS cv_nom, "
        "cv.type_fichier, cv.resume_ia, cv.statut_analyse "
        "FROM candidats c "
        "LEFT JOIN cv ON cv.id = ("
        "  SELECT id FROM cv WHERE candidat_id = c.id ORDER BY date_upload DESC LIMIT 1"
        ") "
        f"WHERE {where} "
        "ORDER BY c.experience_ans DESC "
        "LIMIT 50"
    )


# ────────────────────────────────────────────────────────────────
#  ETAPE 3 — Groq evalue chaque candidat
#
#  On envoie un profil TRES COMPACT a Groq (pas tout le CV).
#  Groq repond avec un score et une courte justification.
#
#  ECONOMIE DE TOKENS : profil limite a l'essentiel, reponse courte.
# ────────────────────────────────────────────────────────────────

PROMPT_SCORE = """Evalue ce candidat pour la recherche suivante. Reponds UNIQUEMENT en JSON.

RECHERCHE : "{question}"
CANDIDAT :
- Poste : {poste}
- Experience : {experience} ans
- Ville : {ville}
- Competences : {competences}
- Extrait CV : {extrait_cv}

JSON attendu :
{{"score": <0-100>, "resume": "<1 phrase expliquant le score>"}}

Bareme : 90+=parfait | 70-89=bon | 50-69=correct | <50=faible"""


def scorer_candidat(candidat: dict, question: str, ville_cible: str) -> tuple[int, str]:
    """
    Etape 3 : Groq attribue un score au candidat.
    Prompt tres court pour economiser les tokens.
    Retourne (score, resume).
    """
    # Extrait du CV analyse — limite a 300 caracteres pour economiser les tokens
    extrait_cv = (candidat.get("resume_ia") or "")[:300]
    if not extrait_cv:
        extrait_cv = "CV non encore analyse"

    prompt = PROMPT_SCORE.format(
        question    = question,
        poste       = candidat.get("poste_actuel") or "non renseigne",
        experience  = candidat.get("experience_ans") or 0,
        ville       = candidat.get("ville") or "non renseignee",
        competences = (candidat.get("competences") or "non renseignees")[:150],
        extrait_cv  = extrait_cv,
    )

    reponse = appeler_groq(
        messages   = [{"role": "user", "content": prompt}],
        max_tokens = 80,  # reponse tres courte : score + 1 phrase
    )

    try:
        r = json.loads(nettoyer_json(reponse))
        score = max(0, min(100, int(r.get("score", 0))))
        return score, r.get("resume", "")
    except Exception:
        return 50, "Profil potentiellement pertinent."


# ────────────────────────────────────────────────────────────────
#  ANALYSE PROFONDE D'UN CV
#
#  Appele une seule fois par CV (au moment de l'upload).
#  Le resultat est stocke dans cv.resume_ia et reutilise ensuite
#  pour tous les matchings — zero token gaspille.
# ────────────────────────────────────────────────────────────────

PROMPT_ANALYSE_CV = """Analyse ce CV et extrais les informations cles. Reponds UNIQUEMENT en JSON.

CV :
---
{texte}
---

JSON attendu :
{{
  "poste": "intitule du poste principal",
  "experience_ans": nombre_entier,
  "competences": ["comp1", "comp2", "comp3"],
  "villes": ["ville1", "ville2"],
  "secteurs": ["secteur1"],
  "formations": ["diplome - etablissement"],
  "langues": ["Francais"],
  "resume": "3-4 phrases sur le profil, ses competences, son parcours et sa localisation"
}}"""


def analyser_cv_avec_groq(texte_cv: str, candidat: dict) -> dict:
    """
    Groq lit le CV et extrait toutes les informations importantes.
    Le champ 'resume' sera stocke dans cv.resume_ia pour les recherches futures.
    """
    texte = texte_cv[:5000]  # limite raisonnable

    reponse = appeler_groq(
        messages   = [{"role": "user", "content": PROMPT_ANALYSE_CV.format(texte=texte)}],
        max_tokens = 600,
    )

    try:
        return json.loads(nettoyer_json(reponse))
    except json.JSONDecodeError:
        log.error("Analyse CV : JSON invalide retourne par Groq")
        return {}


def fusionner_competences(existantes: str, nouvelles_liste: list) -> str:
    """Fusionne sans doublons (insensible a la casse)."""
    connus = {c.strip().lower() for c in existantes.split(",") if c.strip()}
    result = [c for c in existantes.split(",") if c.strip()]
    for c in nouvelles_liste:
        if c.strip() and c.strip().lower() not in connus:
            result.append(c.strip())
            connus.add(c.strip().lower())
    return ", ".join(result)


def analyser_cv_en_attente() -> int:
    """
    Analyse tous les CV avec statut 'en_attente'.
    Appele au demarrage et via POST /analyser-cv-en-attente.
    """
    db  = get_db()
    cur = db.cursor(dictionary=True)
    cur.execute("""
        SELECT cv.id AS cv_id, cv.chemin, cv.candidat_id,
               c.prenom, c.nom, c.poste_actuel, c.ville, c.competences, c.bio
        FROM cv
        JOIN candidats c ON c.id = cv.candidat_id
        WHERE cv.statut_analyse = 'en_attente' AND cv.chemin IS NOT NULL
        LIMIT 10
    """)
    cvs = cur.fetchall()
    nb  = 0

    for cv in cvs:
        texte = lire_fichier_cv(cv["chemin"])
        if not texte:
            cur.execute("UPDATE cv SET statut_analyse='erreur' WHERE id=%s", (cv["cv_id"],))
            db.commit()
            continue

        try:
            donnees = analyser_cv_avec_groq(texte, cv)
        except HTTPException as e:
            log.error(f"Rate limit pendant analyse batch : {e.detail}")
            break  # Arreter proprement, ne pas planter

        if not donnees:
            continue

        # Construire le resume_ia : texte riche et indexable pour les recherches
        resume_ia = (
            f"POSTE: {donnees.get('poste', '')}\n"
            f"SECTEURS: {', '.join(donnees.get('secteurs', []))}\n"
            f"VILLES: {', '.join(donnees.get('villes', []))}\n"
            f"FORMATIONS: {', '.join(donnees.get('formations', []))}\n"
            f"LANGUES: {', '.join(donnees.get('langues', []))}\n"
            f"RESUME: {donnees.get('resume', '')}"
        )

        competences = fusionner_competences(
            cv.get("competences") or "",
            donnees.get("competences", [])
        )

        cur.execute(
            "UPDATE cv SET statut_analyse='analyse', resume_ia=%s WHERE id=%s",
            (resume_ia, cv["cv_id"])
        )
        cur.execute("""
            UPDATE candidats
            SET competences    = %s,
                bio            = IF(bio IS NULL OR bio='', %s, bio),
                poste_actuel   = IF(poste_actuel IS NULL OR poste_actuel='', %s, poste_actuel),
                experience_ans = IF(experience_ans=0 OR experience_ans IS NULL, %s, experience_ans)
            WHERE id = %s
        """, (
            competences,
            donnees.get("resume", ""),
            donnees.get("poste", ""),
            int(donnees.get("experience_ans", 0)),
            cv["candidat_id"],
        ))
        db.commit()
        nb += 1
        log.info(f"CV #{cv['candidat_id']} analyse : {donnees.get('poste','?')}")

    cur.close()
    db.close()
    return nb


# ────────────────────────────────────────────────────────────────
#  STATISTIQUES
# ────────────────────────────────────────────────────────────────

def repondre_statistiques(question: str) -> str:
    """Recupère les stats en base et demande à Groq de les formuler."""
    db  = get_db()
    cur = db.cursor(dictionary=True)
    stats = {}
    try:
        cur.execute("SELECT COUNT(*) AS n FROM candidats WHERE statut='actif'")
        stats["candidats_actifs"] = cur.fetchone()["n"]

        cur.execute("SELECT COUNT(*) AS n FROM cv WHERE statut_analyse='analyse'")
        stats["cv_analyses"] = cur.fetchone()["n"]

        cur.execute("SELECT COUNT(*) AS n FROM cv WHERE statut_analyse='en_attente'")
        stats["cv_en_attente"] = cur.fetchone()["n"]

        cur.execute("SELECT COUNT(*) AS n FROM candidats WHERE LOWER(genre)='femme' AND statut='actif'")
        stats["femmes"] = cur.fetchone()["n"]

        cur.execute("SELECT COUNT(*) AS n FROM candidats WHERE LOWER(genre)='homme' AND statut='actif'")
        stats["hommes"] = cur.fetchone()["n"]

        cur.execute("SELECT AVG(experience_ans) AS m FROM candidats WHERE statut='actif'")
        moy = cur.fetchone()["m"]
        stats["experience_moyenne"] = round(float(moy), 1) if moy else 0

        cur.execute("""
            SELECT ville, COUNT(*) AS n FROM candidats
            WHERE statut='actif' GROUP BY ville ORDER BY n DESC LIMIT 3
        """)
        stats["top_villes"] = [f"{r['ville']} ({r['n']})" for r in cur.fetchall()]
    finally:
        cur.close()
        db.close()

    reponse = appeler_groq(
        messages = [{"role": "user", "content": (
            f"Stats recrutement : {json.dumps(stats, ensure_ascii=False)}\n"
            f"Question : {question}\n"
            "Reponds en francais en 3 phrases maximum."
        )}],
        max_tokens = 150,
    )
    return reponse


# ────────────────────────────────────────────────────────────────
#  ENDPOINTS
# ────────────────────────────────────────────────────────────────

@app.post("/ask", tags=["IA"])
@app.post("/agent", tags=["IA"])
async def ask(body: RequeteChat):
    """
    Point d'entree principal.
    3 etapes : comprendre -> chercher en base -> scorer avec Groq.
    """
    question = body.question.strip()
    if not question:
        raise HTTPException(400, "Question vide.")

    log.info(f"/ask : '{question[:60]}'")

    # Etape 1 : Comprendre la question
    intention = comprendre_question(question, body.historique_chat)
    type_msg  = intention.get("type", "conversation")
    log.info(f"Type detecte : {type_msg}")

    # Cas conversation
    if type_msg == "conversation":
        reponse = intention.get("reponse_directe") or (
            "Bonjour ! Decrivez le profil que vous recherchez. "
            "Exemple : developpeur Python 3 ans experience Abidjan."
        )
        return {"success": True, "type": "conversation",
                "reponse": reponse, "results": [], "sql_generated": ""}

    # Cas statistiques
    if type_msg == "statistique":
        return {"success": True, "type": "statistique",
                "reponse": repondre_statistiques(question),
                "results": [], "sql_generated": ""}

    # Cas recherche
    criteres            = intention.get("criteres", {})
    question_propre     = intention.get("requete_normalisee", question)
    ville_cible         = criteres.get("ville", "")

    # Etape 2 : SQL en Python pur (zero erreur MariaDB)
    sql = construire_sql(criteres)
    log.info(f"SQL construit, execution...")

    try:
        db  = get_db()
        cur = db.cursor(dictionary=True)
        cur.execute(sql)
        candidats = cur.fetchall()
        cur.close()
        db.close()
    except mysql.connector.Error as e:
        log.error(f"SQL erreur : {e}")
        raise HTTPException(500, f"Erreur base de donnees : {e}")

    log.info(f"{len(candidats)} candidat(s) trouve(s)")

    # Etape 3 : Scorer chaque candidat
    resultats = []
    for cand in candidats:
        c = serialiser(dict(cand))
        score, resume = scorer_candidat(c, question_propre, ville_cible)
        if score >= SCORE_MIN:
            resultats.append({
                **c,
                "score":      score,
                "resume_ia":  resume,
                "nom":        f"{c.get('prenom','')} {c.get('nom','')}".strip(),
                "profession": c.get("poste_actuel", ""),
                "experience": f"{c.get('experience_ans', 0)} ans",
            })

    resultats.sort(key=lambda x: x["score"], reverse=True)
    nb = len(resultats)

    if nb > 0:
        reponse = f"J'ai trouve {nb} candidat{'s' if nb > 1 else ''} pour : {question_propre}."
    else:
        reponse = (
            "Aucun candidat trouve. "
            "Verifiez que les CV ont ete analyses (POST /analyser-cv-en-attente) "
            "ou elargissez vos criteres."
        )

    return {
        "success":       True,
        "type":          "recherche",
        "reponse":       reponse,
        "results":       resultats,
        "count":         nb,
        "sql_generated": sql,
    }


@app.post("/analyser-cv-en-attente", tags=["CV"])
async def endpoint_analyser_cv_en_attente():
    """Analyse les CV en attente (statut='en_attente'). A appeler apres chaque upload."""
    nb = analyser_cv_en_attente()
    return {
        "success": True,
        "traites": nb,
        "message": f"{nb} CV analyse(s)." if nb > 0 else "Aucun CV en attente.",
    }


@app.post("/analyser-cv-fichier", tags=["CV"])
async def endpoint_analyser_cv_fichier(body: RequeteAnalyserFichier):
    """Analyse immediatement un CV specifique. A appeler depuis PHP apres upload."""
    db  = get_db()
    cur = db.cursor(dictionary=True)
    cur.execute("SELECT * FROM candidats WHERE id = %s", (body.candidat_id,))
    candidat = cur.fetchone()
    if not candidat:
        cur.close(); db.close()
        raise HTTPException(404, f"Candidat #{body.candidat_id} introuvable.")

    texte = lire_fichier_cv(body.chemin_cv)
    if not texte:
        cur.close(); db.close()
        return {"success": False, "message": "Impossible de lire le fichier CV."}

    donnees = analyser_cv_avec_groq(texte, dict(candidat))
    if not donnees:
        cur.close(); db.close()
        return {"success": False, "message": "Analyse Groq echouee."}

    resume_ia = (
        f"POSTE: {donnees.get('poste', '')}\n"
        f"SECTEURS: {', '.join(donnees.get('secteurs', []))}\n"
        f"VILLES: {', '.join(donnees.get('villes', []))}\n"
        f"FORMATIONS: {', '.join(donnees.get('formations', []))}\n"
        f"LANGUES: {', '.join(donnees.get('langues', []))}\n"
        f"RESUME: {donnees.get('resume', '')}"
    )

    competences = fusionner_competences(
        candidat.get("competences") or "",
        donnees.get("competences", [])
    )

    cur.execute(
        "UPDATE cv SET statut_analyse='analyse', resume_ia=%s "
        "WHERE candidat_id=%s ORDER BY date_upload DESC LIMIT 1",
        (resume_ia, body.candidat_id)
    )
    cur.execute("""
        UPDATE candidats
        SET competences    = %s,
            bio            = IF(bio IS NULL OR bio='', %s, bio),
            poste_actuel   = IF(poste_actuel IS NULL OR poste_actuel='', %s, poste_actuel),
            experience_ans = IF(experience_ans=0 OR experience_ans IS NULL, %s, experience_ans)
        WHERE id = %s
    """, (
        competences,
        donnees.get("resume", ""),
        donnees.get("poste", ""),
        int(donnees.get("experience_ans", 0)),
        body.candidat_id,
    ))
    db.commit(); cur.close(); db.close()

    return {
        "success":     True,
        "message":     f"CV analyse : {donnees.get('poste','?')} — {len(donnees.get('competences',[]))} competences",
        "competences": competences,
        "resume":      donnees.get("resume", ""),
    }


@app.post("/extraire-cv", tags=["CV"])
async def extraire_cv(body: RequeteExtractionCV):
    """Legacy : analyse depuis texte brut. Preferer /analyser-cv-fichier."""
    if not body.texte_cv.strip():
        raise HTTPException(400, "Texte CV vide.")
    donnees = analyser_cv_avec_groq(body.texte_cv, {})
    if not donnees:
        raise HTTPException(500, "Analyse echouee.")

    db  = get_db()
    cur = db.cursor()
    cur.execute(
        "UPDATE candidats SET poste_actuel=%s, experience_ans=%s, competences=%s, bio=%s WHERE id=%s",
        (donnees.get("poste",""), int(donnees.get("experience_ans",0)),
         ", ".join(donnees.get("competences",[])), donnees.get("resume",""), body.candidat_id)
    )
    cur.execute(
        "UPDATE cv SET statut_analyse='analyse', resume_ia=%s "
        "WHERE candidat_id=%s ORDER BY date_upload DESC LIMIT 1",
        (donnees.get("resume",""), body.candidat_id)
    )
    db.commit(); cur.close(); db.close()
    return {"success": True, "donnees": donnees}


@app.get("/cvs", tags=["Donnees"])
async def get_all_cvs(limit: int = 100):
    try:
        db  = get_db()
        cur = db.cursor(dictionary=True)
        cur.execute("""
            SELECT c.id, c.prenom, c.nom, c.email, c.ville, c.poste_actuel,
                   c.experience_ans, c.competences, c.photo,
                   cv.chemin AS cv_chemin, cv.nom_fichier AS cv_nom, cv.resume_ia
            FROM candidats c
            LEFT JOIN cv ON cv.id = (
                SELECT id FROM cv WHERE candidat_id = c.id ORDER BY date_upload DESC LIMIT 1
            )
            WHERE c.statut = 'actif'
            ORDER BY c.date_inscription DESC
            LIMIT %s
        """, (limit,))
        rows = [serialiser(r) for r in cur.fetchall()]
        cur.close(); db.close()
        return {"success": True, "count": len(rows), "cvs": rows}
    except Exception as e:
        return {"success": False, "error": str(e)}


@app.get("/sante", tags=["Systeme"])
async def sante():
    bd_ok, cv_attente = False, 0
    try:
        db  = get_db()
        cur = db.cursor()
        cur.execute("SELECT COUNT(*) FROM cv WHERE statut_analyse='en_attente'")
        cv_attente = cur.fetchone()[0]
        cur.close(); db.close()
        bd_ok = True
    except Exception:
        pass
    return {
        "status":        "ok" if bd_ok else "erreur_bd",
        "version":       "6.0",
        "bd":            bd_ok,
        "groq":          bool(GROQ_API_KEY),
        "cv_en_attente": cv_attente,
    }


@app.get("/", tags=["Systeme"])
async def root():
    return {
        "service":    "SmartRecruit Matching IA v6",
        "endpoints":  ["/ask", "/analyser-cv-en-attente", "/analyser-cv-fichier", "/cvs", "/sante", "/docs"],
    }


# ────────────────────────────────────────────────────────────────
#  DEMARRAGE
# ────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn
    print("\n" + "=" * 55)
    print("  SmartRecruit Matching IA v6")
    print(f"  Base    : {DB['database']}")
    print(f"  Groq    : {'OK' if GROQ_API_KEY else 'CLE MANQUANTE dans .env'}")
    print(f"  Projet  : {RACINE_PROJET}")
    print("  Docs    : http://localhost:5001/docs")
    print("=" * 55)
    print("\n  Analyse des CV en attente...")
    nb = analyser_cv_en_attente()
    print(f"  {nb} CV analyse(s)\n")
    uvicorn.run("matching_service:app", host="0.0.0.0", port=5001, reload=True)
