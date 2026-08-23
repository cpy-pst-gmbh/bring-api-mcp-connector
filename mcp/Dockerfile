FROM python:3.14-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    HTTP_HOST=0.0.0.0 \
    HTTP_PORT=8080

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY server.py .

RUN useradd --create-home --uid 10001 app
USER app

EXPOSE 8080

CMD ["python", "server.py"]
