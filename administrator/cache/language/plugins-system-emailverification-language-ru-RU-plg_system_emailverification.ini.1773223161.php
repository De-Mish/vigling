<?php
defined('_JEXEC') or die;
return array (
  'PLG_SYSTEM_EMAILVERIFICATION' => 'Vigling Email Verification',
  'PLG_SYSTEM_EMAILVERIFICATION_DESCRIPTION' => 'Верификация email с grace-period',
  'PLG_SYSTEM_EMAILVERIFICATION_PENDING_WARNING' => 'Ваш аккаунт не активирован. У вас есть %s на активацию. Проверьте почту и папку "Спам".',
  'PLG_SYSTEM_EMAILVERIFICATION_ACCOUNT_BLOCKED_WARNING' => 'Ваш аккаунт временно заблокирован, так как вы его не активировали. Проверьте почту и папку "Спам".',
  'PLG_SYSTEM_EMAILVERIFICATION_VERIFY_SUCCESS' => 'Email успешно подтвержден. Аккаунт активирован.',
  'PLG_SYSTEM_EMAILVERIFICATION_TOKEN_INVALID' => 'Ссылка подтверждения недействительна.',
  'PLG_SYSTEM_EMAILVERIFICATION_TOKEN_EXPIRED' => 'Срок действия ссылки подтверждения истек. Запросите письмо повторно.',
  'PLG_SYSTEM_EMAILVERIFICATION_RESEND_SUCCESS' => 'Письмо с подтверждением отправлено повторно. Проверьте папку "Спам".',
  'PLG_SYSTEM_EMAILVERIFICATION_RESEND_LIMIT' => 'Письмо уже отправлялось недавно. Повторите попытку через пару минут.',
  'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC' => 'Если аккаунт требует подтверждения, письмо отправлено.',
  'PLG_SYSTEM_EMAILVERIFICATION_RESEND_ALREADY_VERIFIED' => 'Этот аккаунт уже подтвержден.',
  'PLG_SYSTEM_EMAILVERIFICATION_SEND_FAILED_NOTICE' => 'Не удалось отправить письмо подтверждения. Попробуйте позже.',
  'PLG_SYSTEM_EMAILVERIFICATION_MAIL_SUBJECT' => 'Подтверждение email на сайте Vigling',
  'PLG_SYSTEM_EMAILVERIFICATION_MAIL_BODY' => '<p>Здравствуйте, %s!</p><p>Для подтверждения email перейдите по ссылке:</p><p><a href="%s">%s</a></p><p>Если вы не регистрировались на Vigling, просто проигнорируйте это письмо.</p>',
);
