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
RUN python -m spacy download fr_core_news_md

# Copier le code source
COPY . .

# Commande de démarrage
CMD uvicorn app:app --host 0.0.0.0 --port $PORT
