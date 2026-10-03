Скрипты уведомлений по записям. Запускать системным кроном раз в минуту.

Из корня сайта (public_html):

  php components/com_pushnotify/cron/booking_in_30min.php   — «Через 30 минут приём»
  php components/com_pushnotify/cron/booking_started.php   — «Приём начался»

Отладка (запуск вручную с выводом): добавьте -v
  php components/com_pushnotify/cron/booking_started.php -v

Как работают скрипты
--------------------
• Оба скрипта независимы, порядок вызова не важен.
• booking_in_30min: выбирает записи из #__jsn_orders, у которых время приёма (поле time) через 0–30 минут. По каждой записи один раз отправляет «Через 30 минут приём» и помечает в #__viglin_booking_reminders (reminder_type=in_30min).
• booking_started: выбирает записи, у которых time УЖЕ наступило (time <= сейчас), и по которым ещё не отправляли «Приём начался». Отправляет push и помечает в viglin_booking_reminders (reminder_type=started).
• Уведомление «Приём начался» приходит только один раз на запись. Если запись уже есть в viglin_booking_reminders со started — скрипт её пропускает.
• Время «сейчас» считается по часовому поясу сайта (Настройки → Общие → Часовой пояс).

Если «Приём начался» не приходит: создайте тестовую запись на время через 1–2 минуты, дождитесь прохождения времени, запустите скрипт с -v и проверьте, что «Найдено записей» > 0. Если 0 — проверьте часовой пояс и что по этой записи нет строки в viglin_booking_reminders с reminder_type=started.

Пример крон-записей (подставьте путь к public_html):

  * * * * * cd /path/to/public_html && php components/com_pushnotify/cron/booking_in_30min.php
  * * * * * cd /path/to/public_html && php components/com_pushnotify/cron/booking_started.php

Можно объединить в одну строку:

  * * * * * cd /path/to/public_html && php components/com_pushnotify/cron/booking_in_30min.php; php components/com_pushnotify/cron/booking_started.php
