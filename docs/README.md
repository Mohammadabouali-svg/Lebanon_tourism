# The manual

Read in order the first time. Later, jump to the chapter you need.

| Chapter | What you learn |
|---|---|
| [Decision log](decisions.md) | Every important choice, why it was made, what was rejected |
| [01 Getting started](01-getting-started.md) | Running the stack on Windows (WSL 2) or macOS, daily workflow, troubleshooting |
| [02 Architecture](02-architecture.md) | The big picture, and the path of one request through every layer |
| [03 Authentication & authorization](03-authentication.md) | Sessions, CSRF, verification, password reset, roles, policies |
| [04 Database](04-database.md) | Schema, constraints, money, transactions and locking |
| [05 Bookings & payments](05-bookings-and-payments.md) | The booking flow, discounts, the wallet ledger, card payments |
| [06 Security](06-security.md) | Each weakness of the original project and how this one prevents it |
| [07 Frontend](07-frontend.md) | The React app: structure, data fetching, forms, guards |
| [08 Testing](08-testing.md) | How the test suite works and how to write a new test |
| [09 Deployment](09-deployment.md) | Production images, configuration, a go-live checklist |
| [10 Exercises](10-exercises.md) | Features left for you to build, roughly easiest first |

## How to read the code

Comments in the code explain **why**, rarely **what**. Look for the
`/* | ... */` blocks above classes and larger methods. If a piece of code
surprises you, there is usually a comment nearby saying what would go wrong
without it.

A good first exercise: pick one endpoint in
[`backend/routes/api.php`](../backend/routes/api.php) and follow it through
middleware → Form Request → controller → service → model → resource, using
your editor's "Go to definition". [Chapter 02](02-architecture.md) does
exactly that for booking a tour.
