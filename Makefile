.PHONY: init config up down logs ps demo test test-rules backup backup-powershell

init:
	sh scripts/init.sh

config:
	docker compose config --quiet

up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f --tail=200

ps:
	docker compose ps

demo:
	docker compose -f examples/docker-compose.client.yml --profile go up -d --build

test:
	sh scripts/e2e-smoke.sh

test-rules:
	docker run --rm --entrypoint /bin/promtool \
		-v "$(CURDIR)/config/prometheus:/etc/prometheus:ro" \
		prom/prometheus:v3.15.1 \
		test rules /etc/prometheus/tests/observability-alerts.test.yml

backup:
	sh scripts/backup.sh

backup-powershell:
	powershell -ExecutionPolicy Bypass -File .\scripts\backup.ps1

