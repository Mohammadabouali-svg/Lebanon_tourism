# 08. Testing

```bash
make test                      # everything
make test-filter f=BookingTest # one class
make test-filter f=overbooked  # tests whose name contains "overbooked"
```

## Why tests matter here

Every security fix in [chapter 06](06-security.md) has a test that tries the
attack. If someone later removes a check by accident, a test fails before the
change reaches production. Tests are the proof that the rules hold, and they
are documentation that cannot go out of date.

## How the suite is organised

```
backend/tests/
├── TestCase.php                 base class: makes requests look like they come from the SPA
├── Unit/
│   └── MoneyAndDiscountTest.php rounding, formatting, CSV and LIKE escaping (no HTTP, no DB)
└── Feature/                     real HTTP requests against the app, real Postgres
    ├── Auth/                    registration, login, verification, password reset
    ├── Booking/                 booking rules, wallet, cards, cancellation, discounts
    ├── Operator/                tour management, CSV export
    ├── Admin/                   wallet credits, suspensions, approvals, moderation
    ├── AccessControlTest.php    who may call what
    ├── ReviewTest.php           review rules, photo uploads and cleanup, photo migration
    ├── TourCatalogTest.php      public listing, filters, search
    └── TourSearchIndexTest.php  the search indexes exist and the planner can use them
```

- **Unit tests** check one function in isolation. Fast, good for edge cases
  (rounding 149.85 cents).
- **Feature tests** send real HTTP requests through the whole stack:
  middleware, validation, controller, service, database, JSON. Most tests
  here are feature tests, because the risks are in how the pieces fit together.

## The tools

**`RefreshDatabase`** migrates the test database once, then wraps every test
in a transaction that is rolled back. Every test starts empty.

The tests use their own database, `lebanon_tourism_test` (see
`phpunit.xml`), so they never touch your demo data.

**Factories** build valid records with one line:

```php
$traveler = User::factory()->withBalance(50_000)->create();
$operator = User::factory()->operator(OperatorStatus::Pending)->create();
$tour     = Tour::factory()->for($operator, 'operator')->withDeparture()->create();
$draft    = Tour::factory()->draft()->create();
// Dates and spots live on departures (Exercise 10):
$full     = Departure::factory()->for($tour)->create(['capacity' => 3, 'booked_spots' => 3]);
$past     = Departure::factory()->started()->create();
```

**`actingAs($user)`** logs a user in for the following requests.

**Fakes** replace side effects with recorders:

```php
Notification::fake();      // no e-mails sent, but you can assert which would be
Storage::fake('public');   // uploads go to a temporary disk

Notification::assertSentTo($user, VerifyEmailNotification::class);
Storage::disk('public')->assertExists($path);
```

**Swapping a dependency**: the payment gateway is resolved from the
container, so a test can replace it:

```php
$this->app->instance(PaymentGateway::class, FakePaymentGateway::decliningAll());
```

**Response assertions** read like sentences:

```php
$this->postJson("/api/tours/{$tour->id}/bookings", ['spots' => 2, 'payment_method' => 'wallet'])
    ->assertUnprocessable()
    ->assertJsonPath('code', 'not_enough_spots');
```

## Anatomy of a good test

```php
public function test_insufficient_wallet_balance_changes_nothing(): void
{
    // Arrange: the situation
    $traveler = $this->traveler(balanceCents: 5_000);
    $departure = Departure::factory()->for(Tour::factory()->state(['price_cents' => 12_000]))->create();

    // Act: one request
    $this->actingAs($traveler)
        ->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet'])
        // Assert: the response...
        ->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_funds');

    // ...and the state of the world afterwards
    $this->assertSame(0, $departure->fresh()->booked_spots);
    $this->assertSame(5_000, $traveler->wallet->fresh()->balance_cents);
}
```

- The **name** says the rule in plain English. When it fails, the name alone
  tells you what broke.
- **One behaviour per test.**
- Check the **side effects**, not just the status code. "422" alone would not
  prove that the spots were released.
- Use `->fresh()` to reload from the database; the PHP object in the test
  does not see changes made during the request.

## Writing a test for a new feature

Say you add "operators can't set a price above $10,000" (exercise 2, now
implemented):

1. `TourRequest` already had a looser `max` on `price_cents` ($100,000), so
   the change is to lower it to `max:1000000` and give it a readable message.
2. Add a test next to the others in `tests/Feature/Operator/TourManagementTest.php`:

```php
public function test_prices_above_ten_thousand_dollars_are_refused(): void
{
    $this->actingAs($this->operator)
        ->postJson('/api/operator/tours', $this->validPayload(['price_cents' => 1_000_001]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('price_cents');
}
```

3. Write the test *before* the change and run `make test-filter f=ten_thousand`.
   It fails (201 instead of 422) because $10,000.01 was still allowed. Then
   lower the limit and watch it pass. A test you have never seen fail proves
   nothing. The real tests also check the exact boundary ($10,000 accepted),
   updates, and that nothing was written to the database.

## Two testing gotchas met while building this project

- **Sanctum remembers the user within one test.** All requests of a test run
  in the same application instance. After logging out, call
  `$this->app['auth']->forgetGuards()` before checking that the next request
  is anonymous (see `LoginTest`).
- **Fake uploads report a MIME type based on the file name.**
  `UploadedFile::fake()->createWithContent('photo.jpg', '<?php ...')` passes
  the `image` rule in tests, unlike a real upload. The re-encoding step still
  refuses it, which is exactly why the code has more than one layer.

## Formatting

```bash
make pint
```

Laravel Pint formats the PHP code to the project's style. Run it before
committing; CI should run `pint --test`.
