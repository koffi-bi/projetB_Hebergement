# Dockerfile pour SmartRecruit Matching Service - Render.com
# Version: 7.2 - Cloudinary + Groq + OCR

FROM python:3.10-slim

# Métadonnées
LABEL service="smartrecruit-matching-service"
LABEL version="7.2"
LABEL description="Matching service avec extraction Cloudinary et analyse Groq"

# Éviter les prompts interactifs
ENV DEBIAN_FRONTEND=noninteractive
ENV PYTHONUNBUFFERED=1
ENV PYTHONDONTWRITEBYTECODE=1
ENV TZ=UTC

# Mettre à jour et installer les dépendances système nécessaires
RUN apt-get update && apt-get install -y --no-install-recommends \
    # Pour l'extraction PDF
    poppler-utils \
    # Pour l'OCR
    tesseract-ocr \
    tesseract-ocr-fra \
    tesseract-ocr-eng \
    # Utilitaires système
    curl \
    wget \
    ca-certificates \
    # Pour les images (Pillow)
    libjpeg-dev \
    zlib1g-dev \
    libpng-dev \
    # Nettoyer le cache pour réduire l'image
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && rm -rf /usr/share/doc/* \
    && rm -rf /usr/share/man/*

# Définir le répertoire de travail
WORKDIR /app

# Copier uniquement requirements.txt d'abord (pour optimiser le cache Docker)
COPY matching_service/requirements.txt .

# Installer les dépendances Python
RUN pip install --no-cache-dir --upgrade pip && \
    pip install --no-cache-dir -r requirements.txt

# Copier tout le code source
COPY matching_service/ .

# Créer un répertoire pour les fichiers temporaires
RUN mkdir -p /tmp/cv_cache && chmod 777 /tmp/cv_cache

# Variables d'environnement (seront écrasées par Render)
ENV PORT=5001
ENV DB_HOST=localhost
ENV DB_NAME=projetb
ENV DB_USER=root
ENV DB_PASSWORD=""
ENV GROQ_API_KEY=""
ENV TESSERACT_CMD=/usr/bin/tesseract

# Exposer le port
EXPOSE 5001

# Healthcheck pour Render
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
  CMD curl -f http://localhost:5001/sante || exit 1

# Démarrer le service (sans reload pour la production)
CMD ["uvicorn", "matching_service:app", "--host", "0.0.0.0", "--port", "5001", "--workers", "2"]
