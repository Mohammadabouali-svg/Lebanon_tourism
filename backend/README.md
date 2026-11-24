# Backend: Laravel API

The JSON API of Lebanon Tourism. Run it through Docker from the project root
(see the [main README](../README.md)); nothing needs to be installed locally.

Start reading here:

- [`routes/api.php`](routes/api.php): every endpoint and who may call it
- [`app/Services/BookingService.php`](app/Services/BookingService.php): the core business logic
- [`database/migrations`](database/migrations): the schema and its constraints
- [`tests/Feature`](tests/Feature): the rules, written as executable examples

The manual in [`../docs`](../docs/README.md) explains the architecture
([chapter 02](../docs/02-architecture.md)) and every design decision
([decision log](../docs/decisions.md)).

```bash
make test        # from the project root
make artisan c="route:list --path=api"
```
