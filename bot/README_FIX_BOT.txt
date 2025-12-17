# Telegram Bot Quick Fix (Admin build)

1) Configure token & admin chat:
   - Edit `config.php` and ensure:
     ```php
     'telegram' => [
       'bot_token' => '8474170084:AAFdYmeb8mtNv3wxdy0NagoNbFPugbgBQ1s',
       'admin_chat_id' => '-4732451617',
     ],
     ```

2) Set webhook to the current domain (auto-generates secret):
   - Open in browser: `https://YOUR_DOMAIN/bot/fix_webhook.php`
   - You should see JSON/OK from Telegram and the `secret_token` value saved in settings.

3) Test the bot is alive:
   - Open: `https://YOUR_DOMAIN/bot/selftest.php`
   - You should receive a Telegram message in the admin chat: “Bot self-test OK ...”.

Notes:
- Webhook handler: `/bot/webhook.php`
- Secret header must match the value stored in `settings` as `tg_webhook_secret`.
- If you move the site to another domain, repeat step (2).
