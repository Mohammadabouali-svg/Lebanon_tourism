# Frontend (React + Vite + Tailwind)

The user interface of Lebanon Tourism: a single-page app that talks to the
Laravel API in `../backend`.

Everything about how it is built, and why, is in
[`docs/07-frontend.md`](../docs/07-frontend.md).

Day-to-day commands run inside Docker, from the project root (a WSL terminal
on Windows, Terminal on macOS):

```bash
make up                      # starts everything; the app is on http://localhost:5173
make npm c="run build"       # type-check + production build
make npm c="run lint"        # oxlint
make npm c="install some-package"
```
