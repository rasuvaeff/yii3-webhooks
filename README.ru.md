# rasuvaeff/yii3-webhooks
[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![Build](https://github.com/rasuvaeff/yii3-webhooks/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-webhooks/php)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
Инфраструктура веб-перехватчиков, подписанная HMAC, для Yii3: исходящая подпись, входящая проверка
, защита от повторного воспроизведения и политика повторной доставки. Он подписывает точные байты полезной нагрузки
, которые вы отправляете или получаете; нет жесткой зависимости от HTTP-клиента — используйте собственный диспетчер
.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете использовать. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `psr/lock` ^1.0

## Установка
```bash
composer require rasuvaeff/yii3-webhooks
```
## Использование
### Подписание исходящего вебхука
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
### Проверка входящего вебхука
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
### Защита от повтора
Используйте идентификатор события (из заголовка `X-Webhook-Id`) в качестве nonce — он уникально
 идентифицирует доставку и позволяет обнаруживать повторы независимо от проверки подписи
. @@ЛИНИЯ@@
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
### Отслеживание поставок
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
## Справочник по API
### ВебхукСобытие
| Метод | Описание |
 |---|---|
 | `create (тип, полезная нагрузка, произошло?)` | Фабрика с автоматически сгенерированным идентификатором |
 | `getId()` | 32-значный шестнадцатеричный идентификатор |
 | `getType()` | Строка типа события |
 | `getPayload()` | Необработанные байты полезной нагрузки для подписи и доставки |
 | `getOccurredAt()` | `DateTimeImmutable` | @@ЛИНИЯ@@
### ВебхукКонечная точка
| Метод | Описание |
 |---|---|
 | `__construct(url, secret, headers?)` | URL-адрес должен использовать http/https; секретный непустой |
 | `getUrl()` | URL-адрес конечной точки |
 | `getSecret()` | Общий секрет (не хранится при доставке) |
 | `getHeaders()` | Дополнительные заголовки запросов | @@ЛИНИЯ@@
### ВебхукПодпись
| Метод | Описание |
 |---|---|
 | `__construct(метка времени, значение)` | Положительная временная метка, непустое значение |
 | `fromHeaderValue(заголовок)` | Разобрать формат `t=...,v1=...` |
 | `toHeaderValue()` | Сериализовать в формат `t=...,v1=...` |
 | `getTimestamp()` | Временная метка Unix |
 | `getValue()` | Шестнадцатеричная строка HMAC | @@ЛИНИЯ@@
### Вебхукподписчик
Интерфейс для реализации исходящей подписи. Пользовательские подписывающие лица должны подписать точные байты полезной нагрузки и вернуть WebhookSignature.

 | Метод | Описание |
 |---|---|
 | `sign(payload, secret, timestamp, eventId)` | Возвращает `WebhookSignature` | @@ЛИНИЯ@@
### HmacSha256подписавшийся
Подписывает `"{eventId}.{timestamp}.{payload}"` с секретом, используя HMAC-SHA256. `payload` — это точная строка тела HTTP, а не перекодированное значение JSON.

 | Метод | Описание |
 |---|---|
 | `sign(payload, secret, timestamp, eventId)` | Возвращает `WebhookSignature` | @@ЛИНИЯ@@
### ВебхукVerifier
| Метод | Описание |
 |---|---|
 | `__construct(подписавший, часы, толерантные секунды?)` | Допуск по умолчанию: 300 с |
 | `verify(полезная нагрузка, секрет, подпись, eventId)` | Возвращает `bool`; использует `hash_equals` | @@ЛИНИЯ@@
### ВебхукRetryPolicy
| Метод | Описание |
 |---|---|
 | `fixed(maxAttempts?, DelaySeconds?)` | Постоянная задержка; по умолчанию: 3 попытки, 60 с |
 | `экспоненциальный(maxAttempts?, baseSeconds?, cap?, множитель?)` | Двойная задержка; по умолчанию: 5 попыток, база 10 с, ограничение 3600 с |
 | `getMaxAttempts()` | Максимальное количество повторных попыток |
 | `nextDelaySeconds(попытки)` | Задержка перед следующей попыткой; `attempts` = текущее количество попыток |
 | `следуетПовторить(доставка)` | Возвращает true, если статус «Ожидание» и количество попыток < maxAttempts |
 | `isReadyForRetry(доставка, сейчас)` | Возвращает true, когда задержка истекла | @@ЛИНИЯ@@
### ВебхукДоставка
| Метод | Описание |
 |---|---|
 | `create(событие, конечная точка, создано?)` | Фабрика; хранит только URL (без секрета) |
 | `getId()` | 32-значный шестнадцатеричный идентификатор |
 | `getEventId()` | Идентификатор исходного события |
 | `getEventType()` | Тип исходного события |
 | `getEndpointUrl()` | URL-адрес конечной точки |
 | `getStatus()` | Перечисление `WebhookDeliveryStatus` |
 | `getCreatedAt()` | Время создания DateTimeImmutable |
 | `getAttempts()` | Количество попыток |
 | `getLastAttemptAt()` | `?DateTimeImmutable` |
 | `getLastError()` | `?строка` |
 | `withAttempt(at, error?)` | Возвращает новый экземпляр с увеличенными попытками |
 | `withStatus(статус)` | Возвращает новый экземпляр с обновленным статусом | @@ЛИНИЯ@@
### ВебхукДоставкаХранилище
Интерфейс для бэкэндов персистентности. Core поставляет InMemoryDeliveryStorage для тестов; используйте постоянный бэкэнд в производстве.

 | Метод | Описание |
 |---|---|
 | `сохранить(доставка)` | Хранит запись попытки доставки |
 | `findPending(лимит)` | Возврат в ожидании поставки |
 | `markDelivered(доставка)` | Отмечает доставку как доставленную |
 | `markFailed(доставка)` | Помечает доставку как неудавшуюся |
 | `getById(id)` | Загружает доставку по ID | @@ЛИНИЯ@@
### ReplayGuard
| Метод | Описание |
 |---|---|
 | `__construct(NonceStorage)` | Хранилище должно атомарно отклонять повторяющиеся одноразовые номера |
 | `isReplayed(nonce)` | Возвращает `bool` |
 | `принять (одноразовый номер)` | Отмечает как просмотренное; выдает `RuntimeException`, если дублируется | @@ЛИНИЯ@@
### Статус вебхукаДоставка
Поддерживаемое перечисление строк с тремя регистрами:

 | Дело | Значение |
 |---|---|
 | `В ожидании` | `'ожидает'` |
 | `Доставлено` | `'доставлено'` |
 | `Не удалось` | `'не удалось'` | @@ЛИНИЯ@@
### ВебхукДиспетчер
Интерфейс для реализаций транспорта HTTP. В комплект поставки не входит конкретный диспетчер — приносите свой (Жур, ПСР-18 и т.п.).

 | Метод | Описание |
 |---|---|
 | `отправка(событие, конечная точка)` | Отправляет подписанный вебхук; возвращает `WebhookDelivery` | @@ЛИНИЯ@@
### NonceStorage
Интерфейс для серверов хранения данных с защитой от повторного воспроизведения. Реализации должны атомарно отклонять повторяющиеся одноразовые номера.

 | Метод | Описание |
 |---|---|
 | `имеет(одноразовый номер)` | Возвращает true, если одноразовый номер уже был замечен |
 | `добавить(одноразовый номер)` | Хранит одноразовый номер; возвращает false, если дублируется | @@ЛИНИЯ@@
### InMemoryNonceStorage
Реализация NonceStorage только для тестирования. Небезопасен для производственного использования.

 | Метод | Описание |
 |---|---|
 | `имеет(одноразовый номер)` | Возвращает `bool` |
 | `добавить(одноразовый номер)` | Возвращает false для дубликатов |
 | `очистить()` | Удаляет все сохраненные одноразовые номера | @@ЛИНИЯ@@
### InMemoryDeliveryStorage
Реализация WebhookDeliveryStorage только для тестирования. Реализует IteratorAggregate и Countable для упрощения проверки.

 | Метод | Описание |
 |---|---|
 | `сохранить(доставка)` | Магазины запись о доставке |
 | `findPending(лимит)` | Возврат в ожидании поставки |
 | `markDelivered(доставка)` | Устанавливает статус «Доставлено» |
 | `markFailed(доставка)` | Устанавливает статус «Не удалось» |
 | `getById(id)` | Загружает доставку по ID |
 | `очистить()` | Удаляет все записи | @@ЛИНИЯ@@
## Безопасность
— Для сравнения сигнатур используется hash_equals() — защита от атак по времени.
 — `WebhookDelivery` сохраняет только URL-адрес конечной точки, но не секрет.
 — Все секретные параметры отмечены `#[\SensitiveParameter]` — они не отображаются в трассировках стека.
 — Всегда проверяйте временные метки (допуск), чтобы предотвратить повторное использование старых подписей.
 — используйте ReplayGuard с постоянным хранилищем NonceStorage в рабочей среде; Реализации хранилища
 должны отклонять дубликаты атомарно. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для полных примеров использования. @@ЛИНИЯ@@
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
`make test-coverage` и `makemutation` загружают `pcov` внутри контейнера
 `composer:2`, поскольку базовый образ не имеет драйвера покрытия. @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
