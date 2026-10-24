<?php

namespace Joomla\Plugin\System\Emailverification\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\Application\AfterRouteEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Emailverification\Service\EmailVerificationService;

final class Emailverification extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute' => 'onAfterRoute',
        ];
    }

    public function onAfterRoute(AfterRouteEvent $event): void
    {
        $app = $event->getApplication();

        if ($app->isClient('administrator')) {
            return;
        }

        $this->loadLanguage();

        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            EmailVerificationService::ensureSchema($db);
            // Periodically enforce expired pending accounts even without explicit user login.
            EmailVerificationService::enforceExpiredPendingBatch($db, 200);
        } catch (\Throwable $e) {
            Log::add('emailverification_schema_init_failed: ' . $e->getMessage(), Log::ERROR, 'emailverification');
            return;
        }

        if ($this->handleVerificationToken($app, $db)) {
            return;
        }

        $identity = $app->getIdentity();
        $userId = (int) ($identity->id ?? 0);

        if ($userId <= 0) {
            return;
        }

        try {
            if (EmailVerificationService::enforceGraceForUser($db, $userId)) {
                $message = Text::_('PLG_SYSTEM_EMAILVERIFICATION_ACCOUNT_BLOCKED_WARNING');
                $app->enqueueMessage($message, 'warning');
                $app->logout($userId, ['clientid' => 0]);
                $app->redirect(Route::_('index.php?option=com_users&view=login', false));
                return;
            }

            $state = EmailVerificationService::getUserVerificationState($db, $userId);

            if (!$state) {
                return;
            }

            if (($state['status'] ?? '') === EmailVerificationService::STATUS_BLOCKED) {
                $app->enqueueMessage(Text::_('PLG_SYSTEM_EMAILVERIFICATION_ACCOUNT_BLOCKED_WARNING'), 'warning');
                $app->logout($userId, ['clientid' => 0]);
                $app->redirect(Route::_('index.php?option=com_users&view=login', false));
                return;
            }

            if (($state['status'] ?? '') === 'pending') {
                $app->enqueueMessage(
                    Text::sprintf('PLG_SYSTEM_EMAILVERIFICATION_PENDING_WARNING', EmailVerificationService::getGracePeriodHumanLabel()),
                    'warning'
                );
            }
        } catch (\Throwable $e) {
            Log::add('emailverification_on_after_route_failed: ' . $e->getMessage(), Log::ERROR, 'emailverification');
        }
    }

    private function handleVerificationToken($app, DatabaseInterface $db): bool
    {
        $input = $app->getInput();
        $option = $input->getCmd('option');
        $task = (string) $input->getString('task', '');

        if ($option !== 'com_users' || $task !== 'profile.verifyEmail') {
            return false;
        }

        $token = (string) $input->getString('token', '');
        $result = EmailVerificationService::verifyByToken($db, $token);

        if (!empty($result['ok'])) {
            $app->enqueueMessage(Text::_((string) $result['message_key']), 'message');
            $identity = $app->getIdentity();
            $target = ((int) ($identity->id ?? 0) > 0)
                ? 'index.php?option=com_users&view=profile'
                : 'index.php?option=com_users&view=login';
            $app->redirect(Route::_($target, false));
            return true;
        }

        $app->enqueueMessage(Text::_((string) ($result['message_key'] ?? 'PLG_SYSTEM_EMAILVERIFICATION_TOKEN_INVALID')), 'warning');
        $app->redirect(Route::_('index.php?option=com_users&view=login', false));
        return true;
    }
}
