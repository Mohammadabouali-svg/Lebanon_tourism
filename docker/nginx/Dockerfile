# syntax=docker/dockerfile:1
#
# Production web server image: builds the React app, then copies only the
# resulting static files into a small nginx image. Node.js, node_modules and
# the TypeScript sources never reach production.

FROM node:24-trixie-slim AS frontend-build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM nginx:1.29-alpine AS prod
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
# Not in conf.d/: nginx loads every file there at http level. prod.conf
# includes this one inside its server and location blocks.
COPY docker/nginx/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=frontend-build /app/dist /usr/share/nginx/html
