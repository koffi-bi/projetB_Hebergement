# Dockerfile pour SmartRecruit Matching Service
# Emplacement: python-api/matching_service/Dockerfile

FROM python:3.10-slim

# Métadonnées
LABEL service="smartrecruit-matching-service"
LABEL version="7.2"

# Éviter les prompts interactifs
ENV DEBIAN_FRONTEND=noninteractive
ENV PYTHONUNBUFFERED=1
ENV PYTHONDONTWRITEBYTECODE=1

# Installer les dépendances système
RUN apt-get update && apt-get install -y --no-install-recommends \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-fra \
    tesseract-ocr-eng \
    curl \
    wget \
    ca-certificates \
    libjpeg-dev \
    zlib1g-dev \
    libpng-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Définir le répertoire de travail
WORKDIR /app

# Copier requirements.txt (local à ce dossier)
COPY requirements.txt .

# Installer les dépendances Python
RUN pip install --no-cache-dir --upgrade pip && \
    pip install --no-cache-dir -r requirements.txt

# Copier tout le code source (app.py et autres fichiers)
COPY . .

# Créer un répertoire pour les fichiers temporaires
RUN mkdir -p /tmp/cv_cache && chmod 777 /tmp/cv_cache

# Variables d'environnement
ENV PORT=5001

# Exposer le port
EXPOSE 5001

# Healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
  CMD curl -f http://localhost:5001/sante || exit 1

# Démarrer le service (si app.py est le fichier principal)
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "5001", "--workers", "2"]
