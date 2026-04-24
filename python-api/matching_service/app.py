
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

# ================================================================
#  CONFIG
# ================================================================

DB = {
    "host":     os.getenv("DB_HOST",     "localhost"),
    "database": os.getenv("DB_NAME",     "projetb"),
    "user":     os.getenv("DB_USER",     "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "charset":  "utf8mb4",
}

GROQ_API_KEY  = os.getenv("GROQ_API_KEY", "")
RACINE_PROJET = os.getenv("RACINE_PROJET", r"C:\xampp\htdocs\ProjetB")
TESSERACT_CMD = os.getenv("TESSERACT_CMD", "")
MODELE        = "llama-3.3-70b-versatile"
SCORE_MIN     = 10

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("matching")

try:
    _t = mysql.connector.connect(**DB); _t.close()
    log.info(f"MySQL OK — base : {DB['database']}")
except Exception as e:
    log.error(f"MySQL ERREUR : {e}")

groq_client = Groq(api_key=GROQ_API_KEY)

app = FastAPI(title="SmartRecruit Matching IA", version="6.0", docs_url="/docs")
app.add_middleware(CORSMiddleware, allow_origins=["*"],
                   allow_methods=["*"], allow_headers=["*"], allow_credentials=True)


# ================================================================
#  MODÈLES
# ================================================================

class RequeteChat(BaseModel):
    question:        str  = Field(...)
    recruteur_id:    Optional[int] = None
    historique_chat: list = Field(default=[])

class RequeteExtractionCV(BaseModel):
    texte_cv:    str
    candidat_id: int

class RequeteAnalyserFichier(BaseModel):
    candidat_id: int
    chemin_cv:   str

class RequeteUploadCV(BaseModel):
    candidat_id: int
    chemin_cv:   str


# ================================================================
#  GROQ — APPEL CENTRALISÉ AVEC RETRY 429
# ================================================================

def groq_appel(messages: list, max_tokens: int = 300,
               temperature: float = 0.2, json_mode: bool = False) -> str:
    """Appel Groq avec retry automatique sur rate limit."""
    kwargs = dict(model=MODELE, messages=messages,
                  max_tokens=max_tokens, temperature=temperature)
    if json_mode:
        kwargs["response_format"] = {"type": "json_object"}

    for tentative in range(1, 4):
        try:
            resp = groq_client.chat.completions.create(**kwargs)
            return resp.choices[0].message.content or ""
        except RateLimitError as e:
            m = re.search(r"try again in (\d+)m([\d.]+)s", str(e))
            attente = min((int(m.group(1))*60 + float(m.group(2))) if m else 30, 60)
            log.warning(f"Rate limit — tentative {tentative}/3, attente {attente:.0f}s")
            if tentative == 3:
                raise HTTPException(429, "Limite Groq atteinte. Réessayez dans quelques minutes.")
            time.sleep(attente)
        except Exception as e:
            raise HTTPException(500, f"Erreur Groq : {e}")
    return ""


def nettoyer_json(texte: str) -> str:
    """Extrait le JSON même si Groq ajoute du texte parasite."""
    texte = re.sub(r"^```(?:json)?\s*", "", texte, flags=re.MULTILINE)
    texte = re.sub(r"\s*```$",          "", texte, flags=re.MULTILINE)
    texte = texte.strip()
    debut = texte.find("{"); fin = texte.rfind("}")
    if debut != -1 and fin > debut:
        return texte[debut:fin+1]
    return texte


# ================================================================
#  CLASSIFICATION PYTHON PUR — ZÉRO GROQ

# Corrections orthographiques ivoiriennes les plus fréquentes
CORRECTIONS = {
    r"\bchaiche\b":      "cherche",
    r"\bchèrche\b":      "cherche",
    r"\bchairche\b":     "cherche",
    r"\bcomaercial\b":   "commercial",
    r"\bcommercail\b":   "commercial",
    r"\binformaticein\b":"informaticien",
    r"\bingenieure?\b":  "ingénieur",
    r"\byamossoukro\b":  "yamoussoukro",
    r"\byamoussokro\b":  "yamoussoukro",
    r"\babidgane?\b":    "abidjan",
    r"\bdeveloppeure?\b":"développeur",
    r"\bcomercial\b":    "commercial",
    r"\bsecretaire\b":   "secrétaire",
    r"\bcomptabilite\b": "comptabilité",
    r"\bmarketing\b":    "marketing",
    r"\bmanageure?\b":   "manager",
    r"\bplomberie\b":    "plombier",
    r"\belectriciene?\b":"électricien",
}

# Mots qui signalent une conversation (pas une recherche)
MOTS_CONVERSATION = {
    "bonjour", "bonsoir", "salut", "hello", "hi", "allo",
    "aide", "help", "comment", "fonctionner", "fonctionne",
    "qu'est-ce", "qu'est", "merci", "svp", "stp",
    "comment ça marche", "comment utiliser", "à quoi sert",
}

# Mots qui signalent une statistique
MOTS_STATISTIQUE = {
    "combien", "nombre", "total", "count", "statistique",
    "stat", "résumé", "liste complète", "tous les candidats",
    "tous les cv", "répartition", "moyenne",
}

# Mots à ignorer pour l'extraction de critères (stop words FR)
STOP_WORDS = {
    "je", "un", "une", "des", "les", "le", "la", "de", "du",
    "et", "en", "que", "qui", "avec", "pour", "dans", "sur",
    "cherche", "recherche", "voudrais", "veux", "besoin",
    "trouver", "profil", "candidat", "cv", "voir", "avoir",
    "plus", "moins", "ans", "année", "années", "mois",
    "the", "of", "a", "an", "is", "are", "was",
}

# Villes ivoiriennes et africaines connues
VILLES_CONNUES = {
    "abidjan", "yamoussoukro", "bouaké", "bouake", "daloa",
    "san pedro", "korhogo", "man", "divo", "gagnoa",
    "abengourou", "bondoukou", "odienné", "odiénné",
    "dakar", "accra", "lomé", "cotonou", "bamako",
    "paris", "lyon", "marseille", "bordeaux",
}

# Synonymes de genre
MOTS_FEMME = {"femme", "féminin", "féminine", "dame", "madame", "mlle", "mademoiselle"}
MOTS_HOMME = {"homme", "masculin", "monsieur", "m.", "mr"}


def normaliser_question(question: str) -> str:
    """Corrige les fautes d'orthographe courantes."""
    q = question.lower().strip()
    for pattern, remplacement in CORRECTIONS.items():
        q = re.sub(pattern, remplacement, q, flags=re.IGNORECASE)
    return q


def classifier_intention(question_norm: str) -> str:
    """
    Classifie la question en : recherche / conversation / statistique.
    Basé sur des mots-clés Python — déterministe, zéro Groq.
    """
    mots = set(re.findall(r'\b\w+\b', question_norm.lower()))

    # Statistique
    if mots & MOTS_STATISTIQUE:
        return "statistique"

    # Conversation pure (salutation sans critères métier)
    if mots & MOTS_CONVERSATION:
        # Si la question contient aussi un métier, c'est une recherche quand même
        has_metier = bool(re.search(
            r'\b(développeur|ingénieur|comptable|commercial|médecin|infirmier|'
            r'mécanicien|plombier|électricien|secrétaire|manager|directeur|'
            r'technicien|informaticien|marketing|communication|finance|'
            r'juriste|avocat|architecte|designer|graphiste|data|analyst)\b',
            question_norm, re.IGNORECASE
        ))
        if not has_metier:
            return "conversation"

    return "recherche"


def extraire_criteres(question_norm: str, question_orig: str) -> dict:
    """
    Extrait TOUS les critères de recherche en Python pur.
    Déterministe : même question → mêmes critères → même SQL → mêmes résultats.

    Stratégie :
    1. Genre : mots-clés explicites
    2. Expérience : regex "X ans"
    3. Ville : liste de villes connues + pattern géographique
    4. Poste : pattern métier après "cherche", "profil", etc.
    5. Compétences : liste de technologies/métiers connus
    6. Prénom + Nom : deux mots capitalisés consécutifs
    7. Mots-clés libres : tous les mots significatifs restants
    """
    criteres = {
        "genre": None,
        "ville": None,
        "exp_min": None,
        "exp_max": None,
        "poste": None,
        "competences": [],
        "mots_cles_libres": [],
        "disponibilite": None,
        "niveau": None,
        "prenom_nom": None,   # nouveau : prénom + nom recherché
    }

    q = question_norm.lower()

    # ── 1. GENRE 
    mots_q = set(re.findall(r'\b\w+\b', q))
    if mots_q & MOTS_FEMME:
        criteres["genre"] = "femme"
    elif mots_q & MOTS_HOMME:
        criteres["genre"] = "homme"

    # ── 2. EXPÉRIENCE ──
    # "3 ans", "plus de 2 ans", "minimum 5 ans", "2 à 5 ans"
    m = re.search(r'(\d+)\s*(?:à|-)\s*(\d+)\s*ans', q)
    if m:
        criteres["exp_min"] = int(m.group(1))
        criteres["exp_max"] = int(m.group(2))
    else:
        m = re.search(r'(?:plus\s*de|minimum|min\.?|au\s*moins|>\s*)?\s*(\d+)\s*ans', q)
        if m:
            val = int(m.group(1))
            # "plus de 2 ans" → exp_min=2, sinon c'est une valeur exacte
            if re.search(r'plus\s*de|minimum|min\.?|au\s*moins', q):
                criteres["exp_min"] = val
            else:
                criteres["exp_min"] = max(0, val - 1)  # tolérance ±1

    # ── 3. DISPONIBILITÉ ──
    if re.search(r'imm[eé]diat|disponible\s*maintenant|tout\s*de\s*suite', q):
        criteres["disponibilite"] = "immediat"
    elif re.search(r'1\s*mois|dans\s*un\s*mois', q):
        criteres["disponibilite"] = "1_mois"
    elif re.search(r'3\s*mois|dans\s*trois\s*mois', q):
        criteres["disponibilite"] = "3_mois"

    # ── 4. NIVEAU D'ÉTUDES ───
    if re.search(r'master|bac\+5|bac\s*\+\s*5|m2\b', q):
        criteres["niveau"] = "Master (Bac+5)"
    elif re.search(r'licence|bac\+3|bac\s*\+\s*3|l3\b', q):
        criteres["niveau"] = "Licence (Bac+3)"
    elif re.search(r'ing[eé]nieur|bac\+[45]|école', q):
        criteres["niveau"] = "Ingénieur"
    elif re.search(r'bts|bac\+2|bac\s*\+\s*2', q):
        criteres["niveau"] = "BTS"

    # ── 5. VILLE ─────
    for ville in VILLES_CONNUES:
        if re.search(r'\b' + re.escape(ville) + r'\b', q):
            criteres["ville"] = ville
            break
    # Fallback : "à <Mot>" ou "de <Mot>"
    if not criteres["ville"]:
        m = re.search(r'(?:à|a|de|depuis|en)\s+([A-ZÀ-Ÿa-zà-ÿ]{3,})', question_norm)
        if m:
            ville_candidate = m.group(1).lower().strip()
            if ville_candidate not in STOP_WORDS and len(ville_candidate) > 3:
                criteres["ville"] = ville_candidate

    # ── 6. POSTE / MÉTIER ───
    # D'abord après les mots déclencheurs
    m = re.search(
        r'(?:cherche|recherche|profil\s+de|poste\s+de|cv\s+de|candidat\s+(?:pour|de)?)\s+'
        r'(?:un|une|un\s|une\s)?\s*([a-zà-ÿ\s\-]+?)(?:\s+(?:avec|qui|de|à|pour|ayant|et|,|$))',
        q
    )
    if m:
        poste_brut = m.group(1).strip().rstrip()
        if len(poste_brut) > 2 and poste_brut not in STOP_WORDS:
            criteres["poste"] = poste_brut

    # Si pas trouvé, chercher les métiers connus directement
    METIERS = [
        "développeur", "developer", "dev", "ingénieur", "engineer",
        "comptable", "commercial", "médecin", "infirmier", "infirmière",
        "mécanicien", "mécanicienne", "plombier", "électricien", "électricienne",
        "secrétaire", "manager", "directeur", "directrice",
        "technicien", "technicienne", "informaticien", "informaticienne",
        "data scientist", "data analyst", "analyste", "consultant",
        "graphiste", "designer", "architecte", "juriste", "avocat",
        "chargé de communication", "chargé de marketing",
        "responsable", "chef de projet", "project manager",
        "web developer", "full stack", "fullstack", "frontend", "backend",
        "devops", "sysadmin", "administrateur système",
        "pharmacien", "chirurgien", "laborantin",
        "enseignant", "professeur", "formateur",
        "logisticien", "supply chain", "acheteur",
        "rh", "ressources humaines", "recruteur",
        "community manager", "digital", "e-commerce",
    ]
    if not criteres["poste"]:
        for metier in METIERS:
            if re.search(r'\b' + re.escape(metier) + r'\b', q, re.IGNORECASE):
                criteres["poste"] = metier
                break

    # ── 7. COMPÉTENCES TECHNIQUES ──
    TECHS = [
        "php", "mysql", "javascript", "python", "java", "react", "vue",
        "angular", "nodejs", "laravel", "symfony", "django", "flask",
        "html", "css", "sql", "postgresql", "mongodb", "redis",
        "docker", "kubernetes", "aws", "azure", "git", "linux",
        "word", "excel", "powerpoint", "powerbi", "tableau",
        "photoshop", "illustrator", "figma",
        "sap", "sage", "salesforce", "odoo",
        "c++", "c#", ".net", "kotlin", "swift", "flutter",
        "machine learning", "deep learning", "tensorflow", "pytorch",
    ]
    comps = []
    for tech in TECHS:
        if re.search(r'\b' + re.escape(tech) + r'\b', q, re.IGNORECASE):
            comps.append(tech)
    criteres["competences"] = comps

    # ── 8. PRÉNOM + NOM (recherche nominative) ───────────────────
    # Chercher deux mots capitalisés consécutifs dans la question originale
    # qui ne sont pas des mots de la question
    mots_orig = re.findall(r'\b[A-ZÀ-Ÿ][a-zà-ÿ]{1,}\b', question_orig)
    mots_non_stop_orig = [
        m for m in mots_orig
        if m.lower() not in {"Cherche", "Recherche", "Bonjour", "Salut",
                              "CV", "Profil", "Candidat", "Master", "BTS",
                              "Abidjan", "Bouaké", "Yamoussoukro"}
        and m.lower() not in STOP_WORDS
    ]
    # Si on a deux mots capitalisés, c'est probablement un prénom + nom
    if len(mots_non_stop_orig) >= 2:
        criteres["prenom_nom"] = (mots_non_stop_orig[0], mots_non_stop_orig[1])
        log.info(f"Prénom/Nom détecté : {criteres['prenom_nom']}")

    # ── 9. MOTS-CLÉS LIBRES ───
    # Tous les mots significatifs de la question normalisée
    # (pour la recherche dans cv.resume_ia et les autres champs)
    mots_libres = []
    for mot in re.findall(r'\b[a-zà-ÿ]{3,}\b', q):
        if (mot not in STOP_WORDS
                and mot not in {"cherche", "recherche", "profil", "candidat", "cv"}
                and len(mot) > 2):
            mots_libres.append(mot)
    # Dédupliquer en gardant l'ordre
    vu = set()
    criteres["mots_cles_libres"] = [
        m for m in mots_libres if not (m in vu or vu.add(m))
    ]

    return criteres


# ================================================================
#  CONSTRUCTION SQL — PYTHON PUR, DÉTERMINISTE
#
#  Règle clé : chaque critère cherche dans le formulaire ET
#  dans cv.resume_ia pour trouver les candidats dont le formulaire
#  est incomplet mais le CV bien analysé.
# ================================================================

def construire_sql(criteres: dict) -> str:
    """
    SQL 100% Python. Même critères → même SQL → mêmes résultats.
    Toujours. Sans surprise.
    """
    def s(v: str) -> str:
        """Échappe les apostrophes pour éviter les injections SQL."""
        return v.replace("'", "\\'") if v else ""

    conds = ["c.statut = 'actif'"]

    # Genre
    if criteres.get("genre"):
        g = s(criteres["genre"].lower())
        conds.append(f"LOWER(c.genre) = '{g}'")

    # Expérience
    if criteres.get("exp_min") is not None:
        conds.append(f"c.experience_ans >= {int(criteres['exp_min'])}")
    if criteres.get("exp_max") is not None:
        conds.append(f"c.experience_ans <= {int(criteres['exp_max'])}")

    # Disponibilité
    if criteres.get("disponibilite"):
        conds.append(f"c.disponibilite = '{s(criteres['disponibilite'])}'")

    # Niveau
    if criteres.get("niveau"):
        n = s(criteres["niveau"])
        conds.append(f"LOWER(c.niveau_etude) LIKE LOWER('%{n}%')")

    # Ville — formulaire ET cv.resume_ia
    if criteres.get("ville"):
        v = s(criteres["ville"].lower())
        conds.append(
            f"(LOWER(c.ville) LIKE '%{v}%' "
            f"OR LOWER(cv.resume_ia) LIKE '%{v}%')"
        )

    # Poste — toutes les colonnes texte + CV
    if criteres.get("poste"):
        p = s(criteres["poste"].lower())
        conds.append(
            f"(LOWER(c.poste_actuel) LIKE '%{p}%' "
            f"OR LOWER(c.competences) LIKE '%{p}%' "
            f"OR LOWER(c.bio) LIKE '%{p}%' "
            f"OR LOWER(cv.resume_ia) LIKE '%{p}%')"
        )

    # Compétences techniques
    for comp in (criteres.get("competences") or []):
        if comp and comp.strip():
            c = s(comp.strip().lower())
            conds.append(
                f"(LOWER(c.competences) LIKE '%{c}%' "
                f"OR LOWER(c.bio) LIKE '%{c}%' "
                f"OR LOWER(cv.resume_ia) LIKE '%{c}%')"
            )

    # Prénom + Nom (recherche nominative — la plus prioritaire)
    if criteres.get("prenom_nom"):
        prenom, nom = criteres["prenom_nom"]
        p_safe = s(prenom.lower())
        n_safe = s(nom.lower())
        # Condition OR : soit les deux mots dans prenom+nom, soit dans resume_ia
        conds.append(
            f"(("
            f"LOWER(c.prenom) LIKE '%{p_safe}%' "
            f"AND LOWER(c.nom) LIKE '%{n_safe}%'"
            f") OR ("
            f"LOWER(c.prenom) LIKE '%{n_safe}%' "
            f"AND LOWER(c.nom) LIKE '%{p_safe}%'"  # ordre inversé au cas où
            f") OR LOWER(cv.resume_ia) LIKE '%{p_safe}%' "
            f"OR LOWER(cv.resume_ia) LIKE '%{n_safe}%')"
        )

    # Mots-clés libres — dernier filet, cherche partout
    for mot in (criteres.get("mots_cles_libres") or []):
        if mot and len(mot.strip()) > 2:
            m = s(mot.strip().lower())
            # Ne pas ajouter si déjà couvert par poste ou compétences
            if not any(m in str(c) for c in conds):
                conds.append(
                    f"(LOWER(c.poste_actuel) LIKE '%{m}%' "
                    f"OR LOWER(c.competences) LIKE '%{m}%' "
                    f"OR LOWER(c.bio) LIKE '%{m}%' "
                    f"OR LOWER(c.ville) LIKE '%{m}%' "
                    f"OR LOWER(cv.resume_ia) LIKE '%{m}%')"
                )

    where = " AND ".join(conds)

    return (
        "SELECT c.id, c.prenom, c.nom, c.email, c.telephone, c.ville, "
        "c.genre, c.poste_actuel, c.experience_ans, c.competences, "
        "c.disponibilite, c.niveau_etude, c.photo, c.date_naissance, c.bio, "
        "cv.chemin AS cv_chemin, cv.nom_fichier AS cv_nom, "
        "cv.type_fichier, cv.resume_ia, cv.statut_analyse "
        "FROM candidats c "
        "LEFT JOIN cv ON cv.id = ("
        "SELECT id FROM cv WHERE candidat_id = c.id "
        "ORDER BY date_upload DESC LIMIT 1) "
        f"WHERE {where} "
        "ORDER BY c.experience_ans DESC "
        "LIMIT 50"
    )


# ================================================================
#  SQL ÉLARGI — si la recherche stricte ne trouve rien
#
#  Au lieu de dire "0 résultat", on tente une recherche plus large
#  avec seulement les mots-clés les plus importants.
# ================================================================

def construire_sql_elargi(criteres: dict) -> str:
    """
    SQL de fallback : uniquement poste + mots-clés libres, sans les
    filtres stricts (genre, exp, dispo). Retourne plus de candidats.
    """
    def s(v: str) -> str:
        return v.replace("'", "\\'") if v else ""

    conds = ["c.statut = 'actif'"]

    # Poste seulement
    if criteres.get("poste"):
        p = s(criteres["poste"].lower())
        conds.append(
            f"(LOWER(c.poste_actuel) LIKE '%{p}%' "
            f"OR LOWER(c.competences) LIKE '%{p}%' "
            f"OR LOWER(c.bio) LIKE '%{p}%' "
            f"OR LOWER(cv.resume_ia) LIKE '%{p}%')"
        )

    # Prénom + Nom
    if criteres.get("prenom_nom"):
        prenom, nom = criteres["prenom_nom"]
        p_safe = s(prenom.lower())
        n_safe = s(nom.lower())
        conds.append(
            f"(LOWER(c.prenom) LIKE '%{p_safe}%' "
            f"OR LOWER(c.nom) LIKE '%{n_safe}%' "
            f"OR LOWER(c.prenom) LIKE '%{n_safe}%' "
            f"OR LOWER(c.nom) LIKE '%{p_safe}%')"
        )

    # Mots-clés libres les plus longs (les plus spécifiques)
    mots = sorted(
        [m for m in (criteres.get("mots_cles_libres") or []) if len(m) > 3],
        key=len, reverse=True
    )[:3]  # max 3 mots pour ne pas trop filtrer
    for mot in mots:
        m = s(mot.lower())
        conds.append(
            f"(LOWER(c.poste_actuel) LIKE '%{m}%' "
            f"OR LOWER(c.competences) LIKE '%{m}%' "
            f"OR LOWER(c.bio) LIKE '%{m}%' "
            f"OR LOWER(cv.resume_ia) LIKE '%{m}%')"
        )

    where = " AND ".join(conds) if len(conds) > 1 else "c.statut = 'actif'"

    return (
        "SELECT c.id, c.prenom, c.nom, c.email, c.telephone, c.ville, "
        "c.genre, c.poste_actuel, c.experience_ans, c.competences, "
        "c.disponibilite, c.niveau_etude, c.photo, c.date_naissance, c.bio, "
        "cv.chemin AS cv_chemin, cv.nom_fichier AS cv_nom, "
        "cv.type_fichier, cv.resume_ia, cv.statut_analyse "
        "FROM candidats c "
        "LEFT JOIN cv ON cv.id = ("
        "SELECT id FROM cv WHERE candidat_id = c.id "
        "ORDER BY date_upload DESC LIMIT 1) "
        f"WHERE {where} "
        "ORDER BY c.experience_ans DESC "
        "LIMIT 20"
    )


# ================================================================
#  BASE DE DONNÉES
# ================================================================

def get_db():
    return mysql.connector.connect(**DB)

def propre(row: dict) -> dict:
    out = {}
    for k, v in row.items():
        if isinstance(v, datetime): out[k] = v.isoformat()
        elif hasattr(v, "__float__"): out[k] = float(v)
        else: out[k] = v
    return out


# ================================================================
#  GROQ — SCORING (seul appel Groq lors d'une recherche)
#
#  Prompt DeepSeek intégré : score + justification + points forts/faibles
# ================================================================

SYSTEM_SCORING = """Tu es expert RH. Évalue la pertinence d'un profil pour une recherche.
Retourne UNIQUEMENT ce JSON :
{
  "score": <0-100>,
  "justification": "<1 phrase>",
  "points_forts": "<points forts>",
  "points_faibles": "<points faibles>",
  "competences_detectees": "<compétences clés>"
}
Barème : 90+=parfait | 70-89=bon | 50-69=correct | 30-49=faible | <30=hors sujet
- Si cv_analyse rempli : s'y fier en priorité sur le formulaire
- Synonymes acceptés : JS=JavaScript, dev=développeur, comm=communication
- Profil vide → score 0-15""".strip()


def groq_scorer(candidat: dict, question: str, criteres: dict) -> tuple[int, str]:
    """Groq score un candidat. 1 appel, réponse courte = peu de tokens."""
    cv_info = (candidat.get("resume_ia") or "")[:400]
    profil = {
        "poste":       candidat.get("poste_actuel", "—"),
        "exp":         f"{candidat.get('experience_ans', 0)} ans",
        "ville":       candidat.get("ville", "—"),
        "competences": (candidat.get("competences") or "")[:150],
        "cv":          cv_info or "non analysé",
    }
    filtre = criteres.get("ville") or ""
    msg = (
        f'RECHERCHE: "{question}"\n'
        f"FILTRE VILLE: {filtre or 'aucun'}\n"
        f"PROFIL: {json.dumps(profil, ensure_ascii=False)}"
    )
    try:
        contenu = groq_appel(
            messages=[
                {"role": "system", "content": SYSTEM_SCORING},
                {"role": "user",   "content": msg},
            ],
            max_tokens=180,
            temperature=0.1,
            json_mode=True,
        )
        r     = json.loads(nettoyer_json(contenu))
        score = max(0, min(100, int(r.get("score", 0))))
        res   = r.get("justification", "")
        if r.get("points_forts"):
            res += f" | ✅ {r['points_forts']}"
        if r.get("competences_detectees"):
            res += f" | 🔧 {r['competences_detectees']}"
        return score, res[:350]
    except Exception as e:
        log.warning(f"Scorer : {e}")
        return 50, "Profil potentiellement pertinent."


# ================================================================
#  GROQ — RÉPONSE NATURELLE (1 appel, compact)
# ================================================================

def groq_reponse_naturelle(question: str, nb: int,
                            criteres: dict, elargi: bool = False) -> str:
    """
    Groq formule la réponse finale en langage naturel.
    Beaucoup moins de tokens que de lui faire tout analyser.
    """
    if nb > 0:
        extra = " (recherche élargie — certains critères assouplis)" if elargi else ""
        return (
            f"J'ai trouvé {nb} candidat{'s' if nb>1 else ''} "
            f"correspondant à votre recherche{extra}."
        )
    # 0 résultat : Groq propose des alternatives
    try:
        db  = get_db()
        cur = db.cursor(dictionary=True)
        cur.execute(
            "SELECT c.prenom, c.nom, c.poste_actuel, c.ville, c.experience_ans, "
            "LEFT(cv.resume_ia, 150) AS cv_resume "
            "FROM candidats c "
            "LEFT JOIN cv ON cv.id=(SELECT id FROM cv WHERE candidat_id=c.id "
            "ORDER BY date_upload DESC LIMIT 1) "
            "WHERE c.statut='actif' ORDER BY c.date_inscription DESC LIMIT 5"
        )
        profils = cur.fetchall()
        cur.close(); db.close()

        if not profils:
            return "Aucun candidat dans la base. Demandez aux candidats de s'inscrire."

        liste = "\n".join(
            f"- {p['prenom']} {p['nom']} | {p['poste_actuel'] or '?'} | "
            f"{p['ville'] or '?'} | {p['experience_ans'] or 0}ans"
            for p in profils
        )
        contenu = groq_appel(
            messages=[{"role": "user", "content": (
                f'Recherche: "{question}"\nAucun résultat exact.\n\n'
                f"Profils disponibles:\n{liste}\n\n"
                "En 3 phrases: explique l'absence, propose 1-2 profils proches, "
                "suggère de reformuler. Sois direct."
            )}],
            max_tokens=200,
            temperature=0.3,
        )
        return contenu.strip()
    except Exception:
        return (
            f"Aucun candidat ne correspond exactement à « {question} ». "
            "Essayez des critères plus larges ou vérifiez que les CV ont été analysés."
        )


# ================================================================
#  GROQ — STATISTIQUES
# ================================================================

def repondre_statistiques(question: str) -> str:
    """Lit les stats en base (SQL Python) et demande à Groq de les formuler."""
    db  = get_db()
    cur = db.cursor(dictionary=True)
    stats = {}
    try:
        cur.execute("SELECT COUNT(*) AS n FROM candidats WHERE statut='actif'")
        stats["candidats"] = cur.fetchone()["n"]
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
        stats["exp_moyenne"] = round(float(moy), 1) if moy else 0
        cur.execute("SELECT ville, COUNT(*) AS n FROM candidats WHERE statut='actif' "
                    "GROUP BY ville ORDER BY n DESC LIMIT 3")
        stats["top_villes"] = [f"{r['ville']}({r['n']})" for r in cur.fetchall() if r['ville']]
    finally:
        cur.close(); db.close()

    return groq_appel(
        messages=[{"role": "user", "content": (
            f"Stats RH: {json.dumps(stats, ensure_ascii=False)}\n"
            f"Question: {question}\nRéponds en français, 2-3 phrases max."
        )}],
        max_tokens=150,
        temperature=0.2,
    ).strip()


# ================================================================
#  EXTRACTION TEXTE CV
# ================================================================

def extraire_texte_pdf(chemin: str) -> str:
    texte = ""
    try:
        import pdfplumber
        with pdfplumber.open(chemin) as pdf:
            for page in pdf.pages:
                t = page.extract_text()
                if t: texte += t + "\n"
    except Exception as e:
        log.warning(f"pdfplumber: {e}")
    if len(texte.strip()) < 50:
        try:
            from pdf2image import convert_from_path
            import pytesseract
            if TESSERACT_CMD: pytesseract.pytesseract.tesseract_cmd = TESSERACT_CMD
            for img in convert_from_path(chemin, dpi=200):
                texte += pytesseract.image_to_string(img, lang="fra+eng") + "\n"
        except Exception as e:
            log.warning(f"OCR PDF: {e}")
    return texte.strip()

def extraire_texte_docx(chemin: str) -> str:
    texte = ""
    try:
        from docx import Document
        doc = Document(chemin)
        for p in doc.paragraphs: texte += p.text + "\n"
        for t in doc.tables:
            for r in t.rows: texte += " ".join(c.text for c in r.cells) + "\n"
    except Exception as e:
        log.warning(f"docx: {e}")
    return texte.strip()

def extraire_texte_fichier(chemin_rel: str) -> str:
    chemin = os.path.join(RACINE_PROJET, chemin_rel.lstrip("/\\"))
    if not os.path.exists(chemin):
        log.error(f"Fichier introuvable: {chemin}")
        return ""
    ext = Path(chemin).suffix.lower()
    if ext == ".pdf":                  return extraire_texte_pdf(chemin)
    if ext in (".docx", ".doc"):       return extraire_texte_docx(chemin)
    if ext in (".jpg", ".jpeg", ".png"):
        try:
            import pytesseract; from PIL import Image
            if TESSERACT_CMD: pytesseract.pytesseract.tesseract_cmd = TESSERACT_CMD
            return pytesseract.image_to_string(Image.open(chemin), lang="fra+eng").strip()
        except Exception as e:
            log.warning(f"OCR image: {e}"); return ""
    return ""


# ================================================================
#  GROQ — ANALYSE CV (utilisé uniquement à l'upload, pas à chaque recherche)
# ================================================================

PROMPT_ANALYSE_CV = """Analyse ce CV. Retourne UNIQUEMENT ce JSON :
{
  "poste_principal": "intitulé",
  "experience_ans": 0,
  "competences": ["c1","c2"],
  "villes_mentions": ["ville1"],
  "formations": ["diplome - ecole"],
  "langues": ["Français"],
  "secteurs": ["secteur"],
  "resume_enrichi": "3-5 phrases sur compétences, expériences, villes, formations"
}"""

def groq_analyser_cv(texte: str, info: dict) -> dict:
    prompt = (
        f"{PROMPT_ANALYSE_CV}\n\n"
        f"Candidat: {info.get('prenom','')} {info.get('nom','')}\n"
        f"CV:\n---\n{texte[:5000]}\n---"
    )
    contenu = groq_appel(
        messages=[{"role": "user", "content": prompt}],
        max_tokens=700,
        temperature=0.1,
        json_mode=True,
    )
    try:
        return json.loads(nettoyer_json(contenu))
    except Exception as e:
        log.error(f"Analyse CV JSON: {e}")
        return {}

def fusionner_competences(ex: str, nv: str) -> str:
    connus = {c.strip().lower() for c in ex.split(",") if c.strip()}
    res    = [c for c in ex.split(",") if c.strip()]
    for c in nv.split(","):
        if c.strip() and c.strip().lower() not in connus:
            res.append(c.strip()); connus.add(c.strip().lower())
    return ", ".join(res)

def sauvegarder_analyse(cur, cv_id, candidat_id, donnees, comp_ex, bio_ex):
    resume = donnees.get("resume_enrichi", "")
    comp   = fusionner_competences(comp_ex or "", ", ".join(donnees.get("competences", [])))
    resume_ia = (
        f"POSTE: {donnees.get('poste_principal','')}\n"
        f"SECTEURS: {', '.join(donnees.get('secteurs',[]))}\n"
        f"VILLES: {', '.join(donnees.get('villes_mentions',[]))}\n"
        f"FORMATIONS: {', '.join(donnees.get('formations',[]))}\n"
        f"LANGUES: {', '.join(donnees.get('langues',[]))}\n"
        f"RESUME: {resume}"
    )
    cur.execute("UPDATE cv SET statut_analyse='analyse', resume_ia=%s WHERE id=%s",
                (resume_ia, cv_id))
    cur.execute("""
        UPDATE candidats SET competences=%s,
            bio=COALESCE(NULLIF(bio,''),%s),
            poste_actuel=COALESCE(NULLIF(poste_actuel,''),%s),
            experience_ans=CASE WHEN experience_ans IS NULL OR experience_ans=0
                           THEN %s ELSE experience_ans END
        WHERE id=%s
    """, (comp, resume, donnees.get("poste_principal",""),
          int(donnees.get("experience_ans",0)), candidat_id))

def analyser_cv_batch() -> int:
    db  = get_db(); cur = db.cursor(dictionary=True)
    cur.execute("""
        SELECT cv.id AS cv_id, cv.chemin, cv.candidat_id,
               c.prenom, c.nom, c.poste_actuel, c.ville, c.competences, c.bio
        FROM cv JOIN candidats c ON c.id=cv.candidat_id
        WHERE cv.statut_analyse='en_attente' AND cv.chemin IS NOT NULL LIMIT 10
    """)
    cvs = cur.fetchall(); nb = 0
    for cv in cvs:
        texte = extraire_texte_fichier(cv["chemin"])
        if not texte:
            cur.execute("UPDATE cv SET statut_analyse='erreur' WHERE id=%s", (cv["cv_id"],))
            db.commit(); continue
        try:
            donnees = groq_analyser_cv(texte, cv)
        except HTTPException:
            break
        if donnees:
            sauvegarder_analyse(cur, cv["cv_id"], cv["candidat_id"], donnees,
                                cv.get("competences") or "", cv.get("bio") or "")
            db.commit(); nb += 1
    cur.close(); db.close()
    return nb


# ================================================================
#  ENDPOINT PRINCIPAL — /ask
#
#  FLUX :
#  1. Python classifie la question (0 token Groq)
#  2. Python extrait les critères (0 token Groq)
#  3. Python construit le SQL (0 token Groq)
#  4. Python exécute le SQL sur MySQL
#  5. Groq score chaque candidat trouvé (N tokens)
#  6. Groq formule la réponse (1 appel)
#
#  Si 0 résultat : Python élargit le SQL et réessaie automatiquement.
# ================================================================

@app.post("/ask", tags=["IA"])
@app.post("/agent", tags=["IA"])
async def ask(body: RequeteChat):
    question = body.question.strip()
    if not question:
        raise HTTPException(400, "Question vide.")

    log.info(f"/ask — '{question[:70]}'")

    # ── Étape 1 : Classification Python ──────────────────────────
    question_norm = normaliser_question(question)
    intention     = classifier_intention(question_norm)
    log.info(f"Intention Python: {intention} | Normalisée: {question_norm[:60]}")

    if intention == "conversation":
        return {"success": True, "type": "conversation",
                "reponse": (
                    "Bonjour ! Décrivez le profil recherché. "
                    "Exemple : développeur PHP 3 ans Abidjan, "
                    "ou : commercial marketing disponible immédiatement."
                ),
                "results": [], "sql_generated": ""}

    if intention == "statistique":
        return {"success": True, "type": "statistique",
                "reponse": repondre_statistiques(question),
                "results": [], "sql_generated": ""}

    # ── Étape 2 : Extraction critères Python ─────────────────────
    criteres = extraire_criteres(question_norm, question)
    log.info(f"Critères: poste={criteres.get('poste')} | ville={criteres.get('ville')} | "
             f"genre={criteres.get('genre')} | exp_min={criteres.get('exp_min')} | "
             f"prenom_nom={criteres.get('prenom_nom')} | "
             f"mots_cles={criteres.get('mots_cles_libres')}")

    # ── Étape 3 : SQL Python ──────────────────────────────────────
    sql    = construire_sql(criteres)
    elargi = False
    log.info(f"SQL strict prêt")

    # ── Étape 4 : Exécution SQL ───────────────────────────────────
    try:
        db  = get_db()
        cur = db.cursor(dictionary=True)
        cur.execute(sql)
        candidats = cur.fetchall()
        cur.close(); db.close()
    except mysql.connector.Error as e:
        log.error(f"SQL erreur: {e}\nSQL: {sql}")
        raise HTTPException(500, f"Erreur base de données: {e}")

    log.info(f"{len(candidats)} candidat(s) — SQL strict")

    # ── Fallback : SQL élargi si 0 résultat ──────────────────────
    if not candidats and (criteres.get("poste") or criteres.get("prenom_nom")
                           or criteres.get("mots_cles_libres")):
        sql_elargi = construire_sql_elargi(criteres)
        try:
            db  = get_db()
            cur = db.cursor(dictionary=True)
            cur.execute(sql_elargi)
            candidats = cur.fetchall()
            cur.close(); db.close()
            if candidats:
                elargi = True
                log.info(f"{len(candidats)} candidat(s) — SQL élargi")
                sql = sql_elargi
        except mysql.connector.Error as e:
            log.warning(f"SQL élargi erreur: {e}")

    # ── Étape 5 : Scoring Groq ────────────────────────────────────
    resultats = []
    for cand in candidats:
        c     = propre(dict(cand))
        score, resume = groq_scorer(c, question_norm, criteres)
        if score >= SCORE_MIN:
            resultats.append({
                **c,
                "score":      score,
                "resume_ia":  resume,
                "nom":        f"{c.get('prenom','')} {c.get('nom','')}".strip(),
                "profession": c.get("poste_actuel", ""),
                "experience": f"{c.get('experience_ans',0)} ans",
            })

    resultats.sort(key=lambda x: x["score"], reverse=True)
    nb = len(resultats)

    # ── Étape 6 : Réponse Groq ────────────────────────────────────
    reponse = groq_reponse_naturelle(question_norm, nb, criteres, elargi)

    return {
        "success":       True,
        "type":          "recherche",
        "reponse":       reponse,
        "results":       resultats,
        "count":         nb,
        "sql_generated": sql,
    }


# ================================================================
#  ENDPOINTS CV
# ================================================================

@app.post("/cv-uploade", tags=["CV"])
async def cv_uploade(body: RequeteUploadCV):
    """Analyse automatique après upload PHP."""
    db  = get_db(); cur = db.cursor(dictionary=True)
    cur.execute("SELECT * FROM candidats WHERE id=%s", (body.candidat_id,))
    candidat = cur.fetchone()
    if not candidat:
        cur.close(); db.close()
        raise HTTPException(404, f"Candidat #{body.candidat_id} introuvable.")
    cur.execute("SELECT id FROM cv WHERE candidat_id=%s ORDER BY date_upload DESC LIMIT 1",
                (body.candidat_id,))
    cv_row = cur.fetchone()
    if not cv_row:
        cur.close(); db.close()
        raise HTTPException(404, "Aucun CV trouvé.")
    texte = extraire_texte_fichier(body.chemin_cv)
    if not texte:
        cur.execute("UPDATE cv SET statut_analyse='erreur' WHERE id=%s", (cv_row["id"],))
        db.commit(); cur.close(); db.close()
        return {"success": False, "message": f"Fichier illisible: {body.chemin_cv}"}
    donnees = groq_analyser_cv(texte, dict(candidat))
    if not donnees:
        cur.execute("UPDATE cv SET statut_analyse='erreur' WHERE id=%s", (cv_row["id"],))
        db.commit(); cur.close(); db.close()
        return {"success": False, "message": "Analyse Groq échouée."}
    sauvegarder_analyse(cur, cv_row["id"], body.candidat_id, donnees,
                        candidat.get("competences") or "", candidat.get("bio") or "")
    db.commit(); cur.close(); db.close()
    log.info(f"✅ CV analysé — candidat #{body.candidat_id}: {donnees.get('poste_principal','?')}")
    return {"success": True,
            "message": f"CV analysé: {donnees.get('poste_principal','?')}",
            "competences": ", ".join(donnees.get("competences", [])),
            "resume": donnees.get("resume_enrichi", "")}


@app.post("/analyser-cv-en-attente", tags=["CV"])
async def analyser_cv_en_attente():
    nb = analyser_cv_batch()
    return {"success": True, "traites": nb,
            "message": f"{nb} CV analysé(s)." if nb else "Aucun CV en attente."}


@app.post("/analyser-cv-fichier", tags=["CV"])
async def analyser_cv_fichier(body: RequeteAnalyserFichier):
    db  = get_db(); cur = db.cursor(dictionary=True)
    cur.execute("SELECT * FROM candidats WHERE id=%s", (body.candidat_id,))
    candidat = cur.fetchone()
    if not candidat:
        cur.close(); db.close()
        raise HTTPException(404, f"Candidat #{body.candidat_id} introuvable.")
    cur.execute("SELECT id FROM cv WHERE candidat_id=%s ORDER BY date_upload DESC LIMIT 1",
                (body.candidat_id,))
    cv_row = cur.fetchone()
    if not cv_row:
        cur.close(); db.close()
        raise HTTPException(404, "Aucun CV trouvé.")
    texte = extraire_texte_fichier(body.chemin_cv)
    if not texte:
        cur.close(); db.close()
        return {"success": False, "message": f"Illisible: {body.chemin_cv}"}
    donnees = groq_analyser_cv(texte, dict(candidat))
    if not donnees:
        cur.close(); db.close()
        return {"success": False, "message": "Analyse échouée."}
    sauvegarder_analyse(cur, cv_row["id"], body.candidat_id, donnees,
                        candidat.get("competences") or "", candidat.get("bio") or "")
    db.commit(); cur.close(); db.close()
    return {"success": True,
            "message": f"CV analysé: {donnees.get('poste_principal','?')}",
            "competences": ", ".join(donnees.get("competences", [])),
            "resume": donnees.get("resume_enrichi", "")}


# ================================================================
#  ENDPOINTS UTILITAIRES
# ================================================================

@app.get("/cvs", tags=["Données"])
async def get_all_cvs(limit: int = 100):
    try:
        db  = get_db(); cur = db.cursor(dictionary=True)
        cur.execute("""
            SELECT c.id, c.prenom, c.nom, c.email, c.ville, c.poste_actuel,
                   c.experience_ans, c.competences, c.photo,
                   cv.chemin AS cv_chemin, cv.nom_fichier AS cv_nom, cv.resume_ia
            FROM candidats c
            LEFT JOIN cv ON cv.id=(SELECT id FROM cv WHERE candidat_id=c.id
                                   ORDER BY date_upload DESC LIMIT 1)
            WHERE c.statut='actif' ORDER BY c.date_inscription DESC LIMIT %s
        """, (limit,))
        rows = [propre(r) for r in cur.fetchall()]
        cur.close(); db.close()
        return {"success": True, "count": len(rows), "cvs": rows}
    except Exception as e:
        return {"success": False, "error": str(e)}


@app.get("/sante", tags=["Système"])
async def sante():
    bd_ok = False; cv_attente = 0
    try:
        db  = get_db(); cur = db.cursor()
        cur.execute("SELECT COUNT(*) FROM cv WHERE statut_analyse='en_attente'")
        cv_attente = cur.fetchone()[0]
        cur.close(); db.close(); bd_ok = True
    except Exception:
        pass
    return {"status": "ok" if bd_ok else "erreur_bd", "version": "6.0",
            "bd": bd_ok, "groq": bool(GROQ_API_KEY),
            "cv_en_attente": cv_attente, "racine_projet": RACINE_PROJET}


@app.get("/", tags=["Système"])
async def root():
    return {
        "service": "SmartRecruit Matching IA v6.0",
        "architecture": {
            "classification": "Python pur (regex + mots-clés) — 0 token Groq",
            "sql":            "Python pur (déterministe) — 0 token Groq",
            "scoring":        "Groq (1 appel par candidat trouvé)",
            "reponse":        "Groq (1 appel synthèse)",
        },
        "endpoints": ["/ask", "/cv-uploade", "/analyser-cv-en-attente",
                      "/analyser-cv-fichier", "/cvs", "/sante", "/docs"],
    }


# ================================================================
#  DÉMARRAGE
# ================================================================

if __name__ == "__main__":
    import uvicorn
    print("\n" + "="*58)
    print("  SmartRecruit Matching IA v6.0")
    print(f"  Modèle  : {MODELE}")
    print(f"  Base    : {DB['database']}")
    print(f"  Projet  : {RACINE_PROJET}")
    print(f"  Groq    : {'✅' if GROQ_API_KEY else '❌ GROQ_API_KEY manquante'}")
    print("  Docs    : http://localhost:5001/docs")
    print("="*58)
    print("\n  Analyse des CV en attente...")
    nb = analyser_cv_batch()
    print(f"  {'✅ '+str(nb)+' CV analysé(s)' if nb else 'ℹ️  Aucun CV en attente'}\n")
    uvicorn.run("matching_service:app", host="0.0.0.0", port=5001, reload=True)
