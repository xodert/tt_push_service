# Notification Service

Массовая рассылка SMS/Email. Laravel 13, Kafka, Redis, PostgreSQL.

## Запуск

```bash
docker-compose up --build
```

После старта:
- `migrate` прогонит миграции и сидер
- токены Alice/Bob — в логах контейнера `migrate`
- health: `curl http://localhost:8080/up`
- Swagger: http://localhost:8080/api-docs/index.html
- Postman: импорт `public/api-docs/postman_collection.json` (токен через запрос Login)

Тестовые пользователи: `alice@example.com` / `bob@example.com`, пароль `password`.

## API

Базовый URL: `http://localhost:8080/api`  
Авторизация: `Authorization: Bearer <token>`

Массовая рассылка:

```bash
curl -X POST http://localhost:8080/api/notifications/bulk \
  -H "Authorization: Bearer <token>" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "transactional",
    "channel": "sms",
    "message": "Your OTP is 4821",
    "recipient_ids": ["+79001234567"]
  }'
```

`type`: `transactional` (только у пользователей с правом) или `marketing`.

Статус батча:

```bash
curl -H "Authorization: Bearer <token>" \
  http://localhost:8080/api/notifications/batch/<batch_id>
```

История получателя:

```bash
curl -H "Authorization: Bearer <token>" \
  "http://localhost:8080/api/notifications/subscriber/%2B79001234567"
```

Документация: [openapi.yaml](public/api-docs/openapi.yaml) · [postman_collection.json](public/api-docs/postman_collection.json)

## Тесты

```bash
./vendor/bin/pest
```

Локально — SQLite in-memory, Kafka через `Kafka::fake()`.

## Заметки

- Kafka кладёт задачи в Laravel queue (`high` / `low`) — ретраи и `failed()` через `queue:work`
- `KAFKA_AUTO_COMMIT=false`, оффсет коммитится вручную после `dispatch`
- `notifications:reclaim-stale` раз в минуту подбирает зависшие `sent` / `queued`

## Ограничения

- Нет transactional outbox — если упали между записью в БД и publish, спасает reclaim
- `sent` ставится при claim, до фактического вызова шлюза
- В Docker HTTP — nginx + php-fpm, не production-grade
