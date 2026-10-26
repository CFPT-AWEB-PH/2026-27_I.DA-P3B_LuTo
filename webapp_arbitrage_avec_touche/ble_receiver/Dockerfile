FROM python:3.11-slim

# BlueZ + D-Bus nécessaires pour Bleak sur Linux
RUN apt-get update && apt-get install -y --no-install-recommends \
        bluez \
        libglib2.0-0 \
        dbus \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY receiver.py .

CMD ["python", "-u", "receiver.py"]
