FROM node:24.22.1-alpine3.23 AS dependencies
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

FROM node:24.22.1-alpine3.23
WORKDIR /app
COPY --from=dependencies /app/node_modules ./node_modules
COPY package.json package-lock.json instrumentation.js ./ 
COPY src ./src
USER node
EXPOSE 8080
CMD ["node", "--require", "./instrumentation.js", "src/app.js"]
