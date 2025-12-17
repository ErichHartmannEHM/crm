#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Telethon-сидер чатов для клиентского бота.

Что делает:
- Логинится вашим пользовательским аккаунтом Telegram (MTProto).
- Находит все группы/супергруппы, где состоите вы.
- Проверяет, состоит ли в них сам бот (@username).
- Шлёт список chat_id + title на ваш сервер в /bot_clients/seed_import.php?secret=...

Требования:
    pip install telethon requests python-dotenv

Запуск:
    1) Скопируйте .env.example в .env и заполните.
    2) python3 seed_from_user.py
       (при первом запуске попросит код из Telegram, создаст файл сессии user.session)
"""
import os
import json
import asyncio
import requests
from dataclasses import dataclass
from typing import List

from telethon import TelegramClient, functions, types
from telethon.errors.rpcerrorlist import UserNotParticipantError, ParticipantIdInvalidError

from dotenv import load_dotenv
load_dotenv()

API_ID = int(os.getenv("API_ID", "0"))
API_HASH = os.getenv("API_HASH", "")
BOT_USERNAME = os.getenv("BOT_USERNAME", "")  # пример: illuminatorMarketing_bot (без @)
IMPORT_URL = os.getenv("IMPORT_URL", "")      # пример: https://service-ref.sbs/bot_clients/seed_import.php
SEED_SECRET = os.getenv("SEED_SECRET", "")    # должен совпасть с TG_CLIENTS_SEED_SECRET или seed_import.php?secret=...

assert API_ID and API_HASH and BOT_USERNAME and IMPORT_URL and SEED_SECRET, "Заполните API_ID, API_HASH, BOT_USERNAME, IMPORT_URL, SEED_SECRET в .env"

SESSION_NAME = os.getenv("SESSION_NAME", "user")

@dataclass
class ChatInfo:
    chat_id: int
    type: str
    title: str

def to_bot_api_chat_id(entity) -> int:
    # Для супергрупп/каналов chat_id в Bot API = -100 + <channel_id>
    if isinstance(entity, types.Channel):
        return int('-100' + str(entity.id))
    if isinstance(entity, types.Chat):
        return -int(entity.id)
    raise ValueError("Unknown entity type")

async def is_bot_member(client: TelegramClient, entity, bot_user) -> bool:
    try:
        if isinstance(entity, types.Channel):
            # Быстрый запрос — вернёт участника, если он есть
            await client(functions.channels.GetParticipantRequest(channel=entity, participant=bot_user))
            return True
        elif isinstance(entity, types.Chat):
            # Группы (до конвертации в супер): ищем по участникам
            plist = await client.get_participants(entity, search=bot_user.username, limit=200)
            return any(p.id == bot_user.id for p in plist)
    except (UserNotParticipantError, ParticipantIdInvalidError):
        return False
    except Exception:
        return False
    return False

async def main() -> None:
    client = TelegramClient(SESSION_NAME, API_ID, API_HASH)
    await client.start()
    bot_user = await client.get_entity(BOT_USERNAME if BOT_USERNAME.startswith('@') else '@' + BOT_USERNAME)

    results: List[ChatInfo] = []
    async for dlg in client.iter_dialogs():
        ent = dlg.entity
        if isinstance(ent, (types.Channel, types.Chat)):
            # Каналы без megagroup нам не интересны
            if isinstance(ent, types.Channel) and not ent.megagroup:
                continue
            # Проверка членства бота
            member = await is_bot_member(client, ent, bot_user)
            if member:
                try:
                    cid = to_bot_api_chat_id(ent)
                    results.append(ChatInfo(chat_id=cid, type=('supergroup' if isinstance(ent, types.Channel) else 'group'), title=dlg.name or ""))
                    print(f"[+] {dlg.name}  -> {cid}")
                except Exception as e:
                    print(f"[!] skip {dlg.name}: {e}")

    if not results:
        print("Не найдено групп, где бот состоит.")
        return

    payload = [dict(chat_id=r.chat_id, type=r.type, title=r.title) for r in results]
    r = requests.post(IMPORT_URL, params={'secret': SEED_SECRET}, json=payload, timeout=60)
    print("Импорт:", r.status_code, r.text)

if __name__ == "__main__":
    asyncio.run(main())
