# Dockerfile complet pour votre projet Python avec Groq et Cloudinary
FROM python:3.10-slim

# Installer les dépendances système
RUN apt-get update && apt-get install -y \
    tesseract-ocr \
    tesseract-ocr-fra \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# Définir le répertoire de travail
WORKDIR /app

# Copier les requirements d'abord (pour optimiser le cache Docker)
COPY requirements.txt .

# Installer les dépendances Python
RUN pip install --no-cache-dir -r requirements.txt

# Copier tout le code
COPY . .

# Exposer le port (ajustez selon votre app)
EXPOSE 5001

# Commande de démarrage
CMD ["python", "app.py"]
