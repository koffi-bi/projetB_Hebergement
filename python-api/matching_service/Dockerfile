FROM python:3.11-slim

# Mise à jour et installation des outils système (pour pdf2image/poppler)
RUN apt-get update && apt-get install -y \
    gcc \
    g++ \
    make \
    poppler-utils \
    tesseract-ocr \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copier et installer les dépendances Python
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Télécharger le modèle français medium de spaCy
RUN pip install https://github.com/explosion/spacy-models/releases/download/fr_core_news_md-3.7.0/fr_core_news_md-3.7.0-py3-none-any.whl

# Copier le code source
COPY . .

# Commande de démarrage
CMD uvicorn app:app --host 0.0.0.0 --port $PORT
