<?php
return [
  'db' => [
    'host'    => 'localhost',
    'port'    => 3306,
    'name'    => 'rhusm_mariadb',
    'user'    => 'rhusm_zaur',
    'pass'    => 'doC-04%Tay$q!@3t',
    'charset' => 'utf8mb4',
  ],

  'app' => [
    'name'     => 'CARD Wallet',
    'timezone' => 'Europe/Kyiv',
    'base_url' => '/',
  ],

  'security' => [
    'secret_key' => 'GMEoyDh6Vk7RJESLgujiZ8xOG1GMod0I9BY4MxAhujC6ge3ERRaUjglZlg6B4aQP',
  ],

  // Админ-бот
  'telegram' => [
    'bot_token'     => '8474170084:AAFdYmeb8mtNv3wxdy0NagoNbFPugbgBQ1s',
    'admin_chat_id' => '-4732451617',
  ],

  // Бот для баеров (команд)
  'telegram_buyer' => [
    'bot_token' => '5844728415:AAGdi7b-eGCJupXIQHEnGqjucAPAEXRMaPs',
  ],

  'uploads' => [
    'statements_dir' => __DIR__ . '/storage/uploads/statements',
  ],
];
