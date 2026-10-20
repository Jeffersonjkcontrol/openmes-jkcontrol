# JkControl module

Bridges machine devices connected to [jkcontrol.online](https://jkcontrol.online) into OpenMES.
The machine signals themselves (state, good/reject counts) do **not** go through this module:
the connector posts them to the core gateway endpoint
`POST /api/v1/machine-connections/{id}/signals`. This module adds what the core has no
endpoint for:

| Endpoint | Purpose |
|---|---|
| `POST /api/v1/jk/operator-events` | RFID card read at a machine → operator session → employee activity |
| `POST /api/v1/jk/workstations/{id}/downtime-reason` | Reason code keyed in at the machine → cause of the current stop |
| `POST /api/v1/jk/workstations/{id}/work-order` | Work-order card read at a machine → that order runs there and gets the machine's counts |

All require a Sanctum token of a user with the **Admin** or **Supervisor** role
(create a dedicated account for the connector).

This repository **is** the module folder. OpenMES keeps modules out of its own repository
(its pre-commit hook blocks module code), so the module lives here and is placed under
`backend/modules/JkControl` of an OpenMES checkout.

## Install

**Development** — clone straight into the OpenMES checkout; the dev overlay
(`docker-compose.dev.yml`) bind-mounts `backend/modules`, so edits are live:

```bash
git clone https://github.com/Jeffersonjkcontrol/openmes-jkcontrol.git openmes/backend/modules/JkControl
```

**Any OpenMES instance (including the official image)** — build the package and upload it in
**Admin → Modules**:

```bash
./scripts/build-zip.sh      # → dist/JkControl-<version>.zip (tests excluded)
```

## Enable

1. **Admin → Modules → JKControl Integration → Enable.** This runs the module migration
   (`jk_operator_sessions`) and creates the worker custom field **RFID card** (`rfid_card`).
2. Under Octane: `php artisan octane:reload`. If routes are cached: `php artisan route:cache`.
3. Type each operator's card UID into the worker form (**RFID card** field). Any format works —
   `04:A1:B2:C3`, `04 a1 b2 c3` and `04A1B2C3` are the same card.

## Operator events

```http
POST /api/v1/jk/operator-events
Authorization: Bearer <token>

{ "card_uid": "04A1B2C3", "action": "login", "workstation_id": 3, "occurred_at": "2026-10-19T08:00:00-03:00" }
```

| `status` | HTTP | Meaning |
|---|---|---|
| `logged_in` | 201 | Session opened |
| `already_logged_in` | 200 | Same worker, same machine, already open (safe retry) |
| `logged_out` | 200 | Session closed; an employee activity (`work`) was written |
| `no_open_session` | 200 | Logout with nothing open (safe retry) |

- A login at another machine closes the worker's previous session.
- With `JKCONTROL_EXCLUSIVE_WORKSTATION=true` (default) a login takes the machine over: the
  previous operator's session there is closed.
- Unknown or inactive card → 422 on `card_uid`.
- Sessions are kept in `jk_operator_sessions` because `employee_activities.ends_at` is
  required; the activity is written when the session closes.

## Stop causes

```http
POST /api/v1/jk/workstations/3/downtime-reason
Authorization: Bearer <token>

{ "reason_code": "12", "occurred_at": "2026-10-19T09:55:00-03:00" }
```

- Matches the stop running at `occurred_at`; if none, the last stop that ended within
  `JKCONTROL_REASON_GRACE_MINUTES` (default 30) and still needs a cause.
- Uses the core's `ProductionDowntime::classify()`, exactly like the Shift Monitor.
- `reason_code` is a **Downtime reason code** (Admin → downtime reasons). `AUTO-*`
  placeholders are rejected.
- A cause set by a person (Shift Monitor, or a manually reported stop) is never overwritten:
  409. The connector's own earlier cause can be corrected.

## Work-order cards

The operator reads the card of a work order at the machine: from then on, what the machine
makes belongs to that order.

```http
POST /api/v1/jk/workstations/3/work-order
Authorization: Bearer <token>

{ "order_no": "500101", "occurred_at": "2026-10-19T08:00:00-03:00" }
```

| `status` | HTTP | Meaning |
|---|---|---|
| `started` | 201 | A batch of the order now runs at the workstation |
| `resumed` | 200 | The order's parked batch at this workstation runs again |
| `already_running` | 200 | That order is already running there (safe retry) |

- The order must already exist (planning, **Admin → Import** of a spreadsheet, or the ERP API).
  A card never creates one. `order_no` is matched on letters and digits only, so a card
  written as `500101@` finds order `500101`.
- The core credits machine counts to the order whose batch is running at the workstation
  (`MachineProductionService::resolveActiveWorkOrder`). Starting an order therefore runs one
  batch of it there (created with `WorkOrderService::createBatch`, or the parked one resumed)
  and parks the batch of whatever ran before (back to `PENDING`), ready to resume when its
  card is read again.
- The order goes to `IN_PROGRESS`. An order still on the core default `counting_source`
  (`operator`) becomes `JKCONTROL_ORDER_COUNTING_SOURCE` (default `machine`): the core
  ignores machine counts for orders that are not machine-counted.
- 422 on `order_no` when the order does not exist, is closed (`DONE`, `REJECTED`,
  `CANCELLED`), is on hold (`PAUSED`, `BLOCKED`, `CHANGE_HOLD`), is planned for another line,
  has a planned start in the future, or has routing steps.
- Whole-batch orders without routing steps only: a step-based order is started step by step
  in the core, and a card cannot say which step.
- The core closes an order on its own once `produced_qty` reaches `planned_qty`; later cards
  for it are refused and later counts are no longer credited to it. Plan real quantities.

## Configuration

| Env | Default | |
|---|---|---|
| `JKCONTROL_CARD_SOURCE` | `custom_field` | `custom_field` or `code` (use the worker code as the card UID) |
| `JKCONTROL_CARD_CUSTOM_FIELD` | `rfid_card` | Worker custom field key |
| `JKCONTROL_EXCLUSIVE_WORKSTATION` | `true` | One operator per machine |
| `JKCONTROL_REASON_GRACE_MINUTES` | `30` | How late a cause may arrive after the restart |
| `JKCONTROL_ORDER_COUNTING_SOURCE` | `machine` | Counting source given to a card-started order left on `operator`; `machine`, `both`, or empty to leave it |

## Timezones

`occurred_at` is converted to the plant timezone (**Settings → timezone**, applied per request by
`ApplyPlantTimezone`; `APP_TIMEZONE` is only the fallback) before it is stored.

The core gateway endpoint (`/signals`) does **not** convert its `ts`: Eloquent stores the
wall-clock as received, so `11:00Z` lands as `11:00` on a São Paulo plant (three hours off).
Send `ts` with the plant's offset — `2026-10-19T08:00:00-03:00` — as the connector does.

## Tests

```bash
php artisan test modules/JkControl/tests
```

**Do not run this inside the `backend` container of a Docker Compose install.** That container
has `DB_CONNECTION=pgsql` and `DB_DATABASE` set by the compose file, `<env>` in `phpunit.xml`
does not override a variable that already exists, and `RefreshDatabase` would wipe the real
database. Run the tests in a throwaway container of the same image instead: no compose
environment, not on the OpenMES network, SQLite in memory. From the OpenMES checkout:

```bash
B=$PWD/backend W=/var/www/html
docker run --rm --entrypoint sh \
  -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
  -v $B/app:$W/app:ro -v $B/bootstrap/app.php:$W/bootstrap/app.php:ro -v $B/config:$W/config:ro \
  -v $B/database:$W/database:ro -v $B/lang:$W/lang:ro -v $B/modules:$W/modules:ro -v $B/routes:$W/routes:ro \
  -v $B/tests:$W/tests:ro -v $B/phpunit.xml:$W/phpunit.xml:ro \
  -v $B/composer.json:$W/composer.json:ro -v $B/composer.lock:$W/composer.lock:ro \
  ghcr.io/mes-open/openmes:latest -c '
    composer install --no-scripts -q && php artisan package:discover -q &&
    APP_KEY="base64:$(php -r "echo base64_encode(random_bytes(32));")" php artisan test modules/JkControl/tests'
```

The production image ships without dev dependencies, hence the `composer install`.

The tests extend the core's `Tests\Support\ModuleTestCase`, which registers this module's
provider and runs its migration inside each test's transaction.
