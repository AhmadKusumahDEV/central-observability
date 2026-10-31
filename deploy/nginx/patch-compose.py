#!/usr/bin/env python3
"""Add the observability ingress to the existing VPS Nginx Compose file.

Run from /home/ahmadkusumahdev/nginx after making a backup:
    python3 patch-compose.py docker-compose.yml
"""

from pathlib import Path
import sys


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        print(f"already present: {label}")
        return text
    if old not in text:
        raise SystemExit(f"expected block not found: {label}")
    print(f"updated: {label}")
    return text.replace(old, new, 1)


def main() -> None:
    path = Path(sys.argv[1] if len(sys.argv) > 1 else "docker-compose.yml")
    text = path.read_text()

    text = replace_once(
        text,
        "    image: cloudflare/cloudflared:latest",
        "    image: cloudflare/cloudflared:2026.3.0",
        "pin cloudflared image",
    )
    text = replace_once(
        text,
        "    image: nginx:1.25-alpine",
        "    image: nginx:1.25.5-alpine",
        "pin nginx image",
    )

    volume_anchor = "      - ./temukas.conf:/etc/nginx/conf.d/temukas.conf:ro \n"
    volume_block = (
        volume_anchor
        + "      - ./observability.conf:/etc/nginx/conf.d/observability.conf:ro\n"
        + "      - ./secrets:/etc/nginx/secrets:ro\n"
    )
    text = replace_once(text, volume_anchor, volume_block, "mount observability.conf and secrets")

    network_anchor = "      - frontend-net # Meneruskan traffic ke services backend\n"
    network_block = network_anchor + "      - observability-ingress\n"
    text = replace_once(text, network_anchor, network_block, "attach nginx to observability-ingress")

    external_anchor = (
        "  frontend-net:\n"
        "    driver: bridge\n"
        "    external: true\n"
    )
    external_block = (
        external_anchor
        + "\n"
        + "  observability-ingress:\n"
        + "    external: true\n"
        + "    name: observability-ingress\n"
    )
    text = replace_once(text, external_anchor, external_block, "declare observability-ingress")

    path.write_text(text)
    print(f"wrote {path}")


if __name__ == "__main__":
    main()
