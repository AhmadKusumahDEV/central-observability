FROM rust:1.99.1-bookworm AS build

WORKDIR /src

COPY Cargo.toml Cargo.lock ./
COPY telemetry ./telemetry
COPY telemetry-tower ./telemetry-tower
COPY examples ./examples

RUN cargo build --release --locked -p observability-example-axum

FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --uid 10001 --create-home appuser

USER appuser

COPY --from=build /src/target/release/observability-example-axum /service

EXPOSE 8080
ENTRYPOINT ["/service"]
