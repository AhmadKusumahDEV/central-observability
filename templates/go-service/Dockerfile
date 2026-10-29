FROM golang:1.26.10-alpine AS build

WORKDIR /src

COPY go.mod go.sum ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o /out/service ./cmd/app

FROM alpine:3.22

RUN adduser -D -u 10001 appuser
USER appuser

COPY --from=build /out/service /service

EXPOSE 8080
ENTRYPOINT ["/service"]
