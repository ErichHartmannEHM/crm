Как установить Telegram webhook БЕЗ SSH

1) Залейте проект на хостинг (через DirectAdmin → Файлы).
2) Убедитесь, что доступен https://ВАШ_ДОМЕН/bot/webhook.php
3) Откройте в браузере https://ВАШ_ДОМЕН/bot/webhook_tools.php
   Там 3 кнопки: Установить, Проверить, Удалить. Также можно указать secret_token.

Примечания:
- Токен бота хранится в config.php → 'telegram'['bot_token']
- Если укажете secret в форме, он сохранится как settings.key = 'tg_webhook_secret'
- При нажатии «Установить webhook» автоматически отправится secret (если он не пустой).
