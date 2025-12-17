<?php

class RefundScraper
{
    /**
     * Загружает страницу ПриватБанка и вытаскивает историю заявки.
     *
     * @param string     $id    ID заявки ПриватБанка
     * @param array|null $proxy Настройки прокси (из ProxyManager) или null
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   rows?: array<int,array{order:int,date:string,time:string,status:string,comment:string}>,
     *   current_status?: ?string,
     *   current_dt?: ?string,
     *   current_comment?: ?string,
     *   crm_status?: string
     * }
     */
    public static function fetch(string $id, ?array $proxy = null): array
    {
        $url = "https://privatbank.ua/refund/{$id}";
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => ['Accept-Language: uk,ru;q=0.9,en;q=0.7'],
        ]);

        // Прокси (если задан)
        if ($proxy && !empty($proxy['proxy_url'])) {
            $parts = parse_url($proxy['proxy_url']);
            if (!empty($parts['host']) && !empty($parts['port'])) {
                curl_setopt($ch, CURLOPT_PROXY, $parts['host'] . ':' . $parts['port']);
                if (!empty($parts['user']) || !empty($parts['pass'])) {
                    curl_setopt(
                        $ch,
                        CURLOPT_PROXYUSERPWD,
                        ($parts['user'] ?? '') . ':' . ($parts['pass'] ?? '')
                    );
                }
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        }

        $html = curl_exec($ch);
        if ($html === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'CURL: ' . $err];
        }

        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code >= 400) {
            return ['ok' => false, 'error' => 'HTTP_' . $code];
        }

        // Ищем блок с таблицей истории заявки
        if (preg_match('~<article[^>]+id=["\']refund_table["\'][^>]*>(.*?)</article>~is', $html, $m)) {
            $block = $m[1];
        } else {
            // fallback: берём всю страницу, как в старой версии
            $block = $html;
        }

        // Внутри блока ищем конкретную таблицу
        if (!preg_match('~<table[^>]*>(.*?)</table>~is', $block, $m)) {
            return ['ok' => false, 'error' => 'NO_TABLE'];
        }
        $table = $m[1];

        $rows  = [];
        $order = 0;

        if (preg_match_all('~<tr[^>]*>(.*?)</tr>~is', $table, $trm)) {
            foreach ($trm[1] as $rowHtml) {
                if (!preg_match_all('~<t[dh][^>]*>(.*?)</t[dh]>~is', $rowHtml, $tdm)) {
                    continue;
                }

                $cells = array_map(function ($x) {
                    $x = strip_tags($x);
                    $x = html_entity_decode($x, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $x = preg_replace('~\s+~u', ' ', $x);
                    return trim($x);
                }, $tdm[1]);

                // Ожидаем минимум 4 колонки: дата, час, статус, комментарий
                if (count($cells) < 4) {
                    continue;
                }

                // Первая строка таблицы — заголовок ("Дата", "Час" и т.п.),
                // поэтому оставляем только строки, где первая ячейка похожа на дату 31.12.2025
                if (!preg_match('~^\d{2}\.\d{2}\.\d{4}$~', $cells[0])) {
                    continue;
                }

                $order++;
                $rows[] = [
                    'order'   => $order,
                    'date'    => $cells[0],
                    'time'    => $cells[1],
                    'status'  => $cells[2],
                    'comment' => $cells[3],
                ];
            }
        }

        if (!$rows) {
            // Такое возможно, если ID заявки не существует
            // или ПриватБанк временно не отдаёт таблицу.
            return ['ok' => false, 'error' => 'NO_ROWS'];
        }

        // Текущий статус — последняя строка
        $current        = $rows[count($rows) - 1];
        $curStatus      = $current['status']  ?? null;
        $curComment     = $current['comment'] ?? null;
        $curDt          = null;

        if (!empty($current['date'])) {
            $curDt = $current['date'];
            if (!empty($current['time'])) {
                $curDt .= ' ' . $current['time'];
            }
        }

        $crmStatus = self::detectCrmStatus((string)($curStatus ?? ''), (string)($curComment ?? ''));

        return [
            'ok'              => true,
            'rows'            => $rows,
            'current_status'  => $curStatus,
            'current_dt'      => $curDt,
            'current_comment' => $curComment,
            'crm_status'      => $crmStatus,
        ];
    }

    /**
     * Определяет внутренний статус CRM по статусу и комментарию Привата.
     *
     * Возвращает одну из строк:
     *  - "Выиграно"
     *  - "Требует внимания"
     *  - "В работе"
     */
    public static function detectCrmStatus(string $extStatus, string $comment): string
    {
        $text = mb_strtolower(trim($extStatus . ' ' . $comment), 'UTF-8');

        if ($text === '') {
            return 'В работе';
        }

        // 1) Заявка выиграна (банк пишет, что закрито на твою користь, будет возврат и т.п.)
        $wonPatterns = [
            // украинский
            'заявку закрито на вашу користь',
            'заявка закрита на вашу користь',
            'заявку вирішено на вашу користь',
            'заявку закрито на користь клієнта',
            'заявка закрита на користь клієнта',
            'очікуйте на повернення коштів найближчим часом',
            'кошти буде повернуто',
            'кошти буде повернено',
            'кошти повернуто',
            'повернення коштів здійснено',

            // русские варианты на всякий случай
            'заявка закрыта в вашу пользу',
            'заявка закрыта на вашу пользу',
            'заявка закрыта в вашу пользу. ожидайте возврат средств',
            'ожидайте возврат средств',
            'деньги будут возвращены',
            'деньги возвращены',
            'возврат средств осуществлён',
            'возврат средств осуществлен',
        ];

        foreach ($wonPatterns as $needle) {
            if (mb_strpos($text, $needle) !== false) {
                return 'Выиграно';
            }
        }

        // 2) Требует внимания (банк просит что‑то сделать: прислать документы, написать на e‑mail, в поддержку и т.п.)
        $attentionPatterns = [
            'для оскарження заявки',
            'для оскарження операції',
            'для оскарження транзакції',
            'для оскарження потрібно надати',
            'потрібно надати',
            'необхідно надати',
            'необходимо надать',
            'надішліть',
            'надіслати',
            'отправьте',
            'відправити',
            'на електронну пошту',
            'на электронную почту',
            'chargeback@privatbank.ua',
            'зверніться до служби підтримки',
            'зверніться у службу підтримки',
            'зверніться до банку',
            'зверніться в банк',
            'звернитесь в банк',
            'зателефонуйте',
            'зверніться у відділення',
            'подайте звернення',
            'подайте заявление',
            'потрібно звернутися',
            'необхідно звернутися',
        ];

        foreach ($attentionPatterns as $needle) {
            if (mb_strpos($text, $needle) !== false) {
                return 'Требует внимания';
            }
        }

        // 3) По умолчанию — заявка просто в процессе рассмотрения
        return 'В работе';
    }
}
