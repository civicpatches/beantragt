# Hausschriften (local development)

Fonts in this directory are mounted read-only into the Gotenberg container at
`/usr/local/share/fonts/beantragt` (`.ddev/docker-compose.docgen.yaml`).
LibreOffice picks them up on its next start.

Only fonts licensed for server-side rendering are placed here. Proprietary
fonts are excluded from commits. Production mounts the municipality's fonts from
`DOCGEN_FONTS_DIR` (`deploy/docker-compose.docgen.yml`).
