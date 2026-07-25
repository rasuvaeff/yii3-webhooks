# rasuvaeff/yii3-webhooks

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![Build](https://github.com/rasuvaeff/yii3-webhooks/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-webhooks/php)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[English version](README.md)

Инфраструктура webhook-ов с HMAC-подписанием для Yii3: исходящая подпись,
входящая верификация, защита от replay и политика retry доставки. Подписывает
ровно те байты payload-а, которые вы отправляете или получаете; жёсткой
зависимости от HTTP-клиента нет — подключайте собственный dispatcher.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) дополнительно получают agent-скилл этого пакета в `.agents/skills/` автоматически при установке.

## Требования

- PHP 8.3+
- `psr/clock` ^1.0

## Установка

```bash
composer require rasuvaeff/yii3-webhooks
```

## Использование

### Подписание исходящего webhook-а

```php
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

$signer = new HmacSha256Signer();
$endpoint = new WebhookEndpoint(
    url: 'https://partner.example.com/webhook',
    secret: 'whsec_...',
);

$event = WebhookEvent::create(
    type: 'order.created',
    payload: json_encode(['orderId' => 42]),
);

$timestamp = $clock->now()->getTimestamp();
$signature = $signer->sign(
    payload: $event->getPayload(),
    secret: $endpoint->getSecret(),
    timestamp: $timestamp,
    eventId: $event->getId(),
);

// Add to outgoing request:
// X-Webhook-Id: <event_id>
// X-Webhook-Signature: t=1717228800,v1=<hmac_hex>
$header = $signature->toHeaderValue();
```

### Идентификаторы событий

Id события уезжает получателю в `X-Webhook-Id` — именно по нему он
дедуплицирует. Если webhook отражает доменное событие, у которого
идентификатор уже есть, передайте его: иначе повторная публикация того же
доменного события приедет под новым id, и обработка ретраев на стороне
получателя перестанет работать:

```php
$event = WebhookEvent::create(
    type: 'order.created',
    payload: $json,
    id: $domainEvent->getId(),
);
```

Без `id` он остаётся 32 случайными hex-символами, как и в прошлых версиях.

В отличие от [rasuvaeff/yii3-outbox](https://github.com/rasuvaeff/yii3-outbox),
этот пакет не биндит генератор id: `WebhookDispatcher` — интерфейс, и объект,
который держал бы генератор, принадлежит приложению. Если нужна единая схема
id по всему проекту, оберните фабрику:

```php
final readonly class EventFactory
{
    public function __construct(private ClockInterface $clock) {}

    public function create(string $type, string $payload): WebhookEvent
    {
        return WebhookEvent::create(
            type: $type,
            payload: $payload,
            occurredAt: $this->clock->now(),
            id: Uuid::v7()->toRfc4122(), // symfony/uid или ramsey/uuid — на ваш выбор
        );
    }
}
```

`WebhookDelivery::create()` тоже принимает `id` — для приложений, которые сами
генерируют идентификаторы записей о доставке. Держите их в пределах 32
символов: такова ширина колонки в `rasuvaeff/yii3-webhooks-db`.

### Верификация входящего webhook-а

```php
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Rasuvaeff\Yii3Webhooks\WebhookVerifier;

$verifier = new WebhookVerifier(
    signer: new HmacSha256Signer(),
    clock: $clock,
    toleranceSeconds: 300,
);

$signature = WebhookSignature::fromHeaderValue(
    $request->getHeaderLine('X-Webhook-Signature'),
);

$eventId = $request->getHeaderLine('X-Webhook-Id');

$valid = $verifier->verify(
    payload: (string) $request->getBody(),
    secret: 'whsec_...',
    signature: $signature,
    eventId: $eventId,
);
```

### Защита от replay

Используйте id события (из заголовка `X-Webhook-Id`) как nonce — он однозначно
идентифицирует доставку и позволяет обнаруживать replay независимо от
верификации подписи.

```php
use Rasuvaeff\Yii3Webhooks\InMemoryNonceStorage;
use Rasuvaeff\Yii3Webhooks\ReplayGuard;

$guard = new ReplayGuard(new InMemoryNonceStorage());

// $eventId = $request->getHeaderLine('X-Webhook-Id');
if ($valid) {
    $guard->accept($eventId); // throws RuntimeException if already seen
    // process the webhook...
}
```

### Отслеживание доставок

```php
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;

$storage = new InMemoryDeliveryStorage();
$policy = WebhookRetryPolicy::fixed(maxAttempts: 3, delaySeconds: 60);
// or: WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600)

$delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint);
$storage->save($delivery);

// After attempt:
$delivery = $delivery->withAttempt($clock->now(), error: 'Connection refused');
$storage->save($delivery);

if ($policy->isReadyForRetry($delivery, $clock->now())) {
    // retry...
}
```

## API-справочник

### WebhookEvent

| Метод | Описание |
|---|---|
| `create(type, payload, occurredAt?, id?)` | Фабрика; `id` — идентификатор доменного события, без него → 32-символьный hex |
| `getId()` | 32-символьный hex ID |
| `getType()` | Строковый тип события |
| `getPayload()` | Сырые байты payload-а для подписания и доставки |
| `getOccurredAt()` | `DateTimeImmutable` |

### WebhookEndpoint

| Метод | Описание |
|---|---|
| `__construct(url, secret, headers?)` | URL обязан использовать http/https; secret — непустой |
| `getUrl()` | URL эндпоинта |
| `getSecret()` | Shared secret (не сохраняется в delivery) |
| `getHeaders()` | Дополнительные заголовки запроса |

### WebhookSignature

| Метод | Описание |
|---|---|
| `__construct(timestamp, value)` | Положительный timestamp, непустое value |
| `fromHeaderValue(header)` | Разбирает формат `t=...,v1=...` |
| `toHeaderValue()` | Сериализует в формат `t=...,v1=...` |
| `getTimestamp()` | Unix timestamp |
| `getValue()` | Hex-строка HMAC |

### WebhookSigner

Интерфейс реализаций исходящей подписи. Кастомные signer-ы обязаны подписывать
ровно байты payload-а и возвращать `WebhookSignature`.

| Метод | Описание |
|---|---|
| `sign(payload, secret, timestamp, eventId)` | Возвращает `WebhookSignature` |

### HmacSha256Signer

Подписывает `"{eventId}.{timestamp}.{payload}"` секретом через HMAC-SHA256.
`payload` — это исходная строка HTTP-тела, а не пересериализованное JSON-значение.

| Метод | Описание |
|---|---|
| `sign(payload, secret, timestamp, eventId)` | Возвращает `WebhookSignature` |

### WebhookVerifier

| Метод | Описание |
|---|---|
| `__construct(signer, clock, toleranceSeconds?)` | Допуск по умолчанию: 300 с |
| `verify(payload, secret, signature, eventId)` | Возвращает `bool`; использует `hash_equals` |

### WebhookRetryPolicy

| Метод | Описание |
|---|---|
| `fixed(maxAttempts?, delaySeconds?)` | Постоянная задержка; по умолчанию: 3 попытки, 60 с |
| `exponential(maxAttempts?, baseSeconds?, cap?, multiplier?)` | Удваивающаяся задержка; по умолчанию: 5 попыток, base 10 с, cap 3600 с |
| `getMaxAttempts()` | Максимум попыток retry |
| `nextDelaySeconds(attempts)` | Задержка перед следующей попыткой; `attempts` = текущее количество попыток |
| `shouldRetry(delivery)` | Возвращает `true`, если статус `Pending` и `attempts < maxAttempts` |
| `isReadyForRetry(delivery, now)` | Возвращает `true`, когда задержка истекла |

### WebhookDelivery

| Метод | Описание |
|---|---|
| `create(event, endpoint, createdAt?, id?)` | Фабрика; хранит только URL (без секрета). `id` — не длиннее 32 символов (ширина колонки в БД) |
| `getId()` | 32-символьный hex ID |
| `getEventId()` | ID исходного события |
| `getEventType()` | Тип исходного события |
| `getEndpointUrl()` | URL эндпоинта |
| `getStatus()` | enum `WebhookDeliveryStatus` |
| `getCreatedAt()` | `DateTimeImmutable` времени создания |
| `getAttempts()` | Количество попыток |
| `getLastAttemptAt()` | `?DateTimeImmutable` |
| `getLastError()` | `?string` |
| `withAttempt(at, error?)` | Возвращает новый экземпляр с инкрементированным счётчиком попыток |
| `withStatus(status)` | Возвращает новый экземпляр с обновлённым статусом |

### WebhookDeliveryStorage

Интерфейс backend-ов персистентности. В ядре поставляется
`InMemoryDeliveryStorage` для тестов; в проде используйте персистентный backend.

| Метод | Описание |
|---|---|
| `save(delivery)` | Сохраняет запись о попытке доставки |
| `findPending(limit)` | Возвращает доставки в статусе `Pending` |
| `markDelivered(delivery)` | Помечает доставку как `Delivered` |
| `markFailed(delivery)` | Помечает доставку как `Failed` |
| `getById(id)` | Загружает доставку по ID |

### ReplayGuard

| Метод | Описание |
|---|---|
| `__construct(NonceStorage)` | Хранилище обязано атомарно отклонять дубликаты nonce |
| `isReplayed(nonce)` | Возвращает `bool` |
| `accept(nonce)` | Помечает nonce как замеченный; бросает `RuntimeException` при дубликате |

### WebhookDeliveryStatus

Backed string enum с тремя случаями:

| Case | Value |
|---|---|
| `Pending` | `'pending'` |
| `Delivered` | `'delivered'` |
| `Failed` | `'failed'` |

### WebhookDispatcher

Интерфейс реализаций HTTP-транспорта. В пакете нет конкретного dispatcher-а —
подключайте собственный (Guzzle, PSR-18 и т.п.).

| Метод | Описание |
|---|---|
| `dispatch(event, endpoint)` | Отправляет подписанный webhook; возвращает `WebhookDelivery` |

### NonceStorage

Интерфейс backend-ов хранения nonce для защиты от replay. Реализации обязаны
атомарно отклонять дубликаты nonce.

| Метод | Описание |
|---|---|
| `has(nonce)` | Возвращает `true`, если nonce уже встречался |
| `add(nonce)` | Сохраняет nonce; возвращает `false` при дубликате |

### InMemoryNonceStorage

Реализация `NonceStorage` только для тестов. Не подходит для production.

| Метод | Описание |
|---|---|
| `has(nonce)` | Возвращает `bool` |
| `add(nonce)` | Возвращает `false` при дубликате |
| `clear()` | Удаляет все сохранённые nonce |

### InMemoryDeliveryStorage

Реализация `WebhookDeliveryStorage` только для тестов. Реализует
`IteratorAggregate` и `Countable` для удобной инспекции.

| Метод | Описание |
|---|---|
| `save(delivery)` | Сохраняет запись о доставке |
| `findPending(limit)` | Возвращает доставки в статусе `Pending` |
| `markDelivered(delivery)` | Устанавливает статус `Delivered` |
| `markFailed(delivery)` | Устанавливает статус `Failed` |
| `getById(id)` | Загружает доставку по ID |
| `clear()` | Удаляет все записи |

## Безопасность

- Сравнение подписей использует `hash_equals()` — защита от timing-атак.
- `WebhookDelivery` хранит только URL эндпоинта, но не секрет.
- Все параметры-секреты помечены `#[\SensitiveParameter]` — они не появятся в stack trace.
- Всегда валидируйте timestamp (допуск), чтобы предотвратить replay старых подписей.
- Используйте `ReplayGuard` с персистентным `NonceStorage` в production; реализации
  хранилища обязаны атомарно отклонять дубликаты.

## Примеры

Полные примеры использования — см. [examples/](examples/).

## Разработка

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` и `make mutation` поднимают `pcov` внутри контейнера
`composer:2`, потому что в базовом образе нет драйвера покрытия.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
