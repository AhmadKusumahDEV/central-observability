# Secrets

Create local secret files by running the init script:

```sh
sh scripts/init.sh
```

On Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\init.ps1
```

The script never overwrites an existing secret.

| File | Purpose |
|---|---|
| `grafana_admin_password` | Initial password for the `admin` Grafana user |
| `telegram_bot_token` | Telegram bot token from BotFather |
| `telegram_chat_id` | Target Telegram chat ID |

To enable Telegram notifications, replace the placeholder bot token and chat ID,
then restart Alertmanager:

```sh
docker compose restart alertmanager
```

Do not commit these files. They are ignored by Git.

