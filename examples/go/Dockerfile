# syntax=docker/dockerfile:1.7
FROM golang:1.26.10-alpine3.23 AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/demo .

FROM alpine:3.23.7
RUN apk add --no-cache ca-certificates \
    && addgroup -S app \
    && adduser -S -G app app
COPY --from=build /out/demo /usr/local/bin/demo
USER app
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/demo"]

