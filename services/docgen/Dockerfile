# beantragt docgen: stateless DOCX rendering and PDF/A-2b finalization.
# Production dependencies only, no npm/yarn in the runtime image, non-root.
#
# The npm cache mount makes rebuilds work without registry access once the
# cache holds the four locked packages; on an empty cache npm ci fetches them
# (CI). See README.md "Offline build".

ARG NODE_IMAGE=node:24.22.1-alpine@sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53

FROM ${NODE_IMAGE} AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,id=beantragt-docgen-npm,sharing=locked \
    npm ci --omit=dev --ignore-scripts --prefer-offline --no-audit --no-fund
COPY src ./src
COPY scripts/check-dependency-tree.mjs ./scripts/
# Build gate: no angular-expressions, no docxtemplater add-on modules, no
# unreviewed packages or licenses in the shipped tree.
RUN node scripts/check-dependency-tree.mjs

FROM ${NODE_IMAGE} AS runtime
ENV NODE_ENV=production
RUN rm -rf /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/corepack \
      /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack \
      /opt/yarn-* /usr/local/bin/yarn /usr/local/bin/yarnpkg
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY package.json LICENSE ./
COPY src ./src
COPY scripts/healthcheck.mjs ./scripts/healthcheck.mjs
USER node
EXPOSE 3000
HEALTHCHECK --interval=15s --timeout=10s --start-period=20s --retries=5 \
  CMD ["node", "scripts/healthcheck.mjs"]
CMD ["node", "--disable-proto=delete", "src/server.js"]
