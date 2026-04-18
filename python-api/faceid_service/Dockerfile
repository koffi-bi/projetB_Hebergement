FROM python:3.11-slim

# 1. Installer les dépendances système, dont CMAKE, AVANT d'installer les paquets Python
RUN apt-get update && apt-get install -y \
    cmake \
    build-essential \
    libopencv-dev \
    python3-opencv \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .

# 2. Maintenant que CMake est disponible, on installe les paquets Python
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

CMD uvicorn app:app --host 0.0.0.0 --port $PORT
