<?php
/**
 * Профиль мастера с отображением услуг
 * Joomla 4+ совместимая версия
 * Заменяет старый JSN файл
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\Access\Access;

$app = Factory::getApplication();
$db = Factory::getContainer()->get('DatabaseDriver');
$prefix = $db->getPrefix();

$currentUser = $app->getIdentity();
$profileUser = isset($this->data) ? $this->data : null;

if (!$profileUser || !$profileUser->id) {
    echo '<div class="container"><p>Профиль не найден</p></div>';
    return;
}

$profileUserId = (int)$profileUser->id;
$currentUserId = $currentUser ? (int)$currentUser->id : 0;
$isProfileOwner = ($currentUserId === $profileUserId);

// Проверка что это мастер
$profileGroups = Access::getGroupsByUser($profileUserId, false);
$isMasterProfile = in_array(3, $profileGroups) || in_array(8, $profileGroups);

if (!$isMasterProfile) {
    echo '<div class="container"><p>Это не профиль мастера</p></div>';
    return;
}

?>

<div class="master-profile">
    <!-- Кнопки управления -->
    <div class="jsn-p-opt">
        <?php if ($app->getInput()->get('back') == '1') : ?>
            <a class="btn btn-xs btn-default" href="#" onclick="window.history.back(); return false;">
                <i class="fa fa-share"></i> <?php echo Text::_('COM_USERS_BACK') ?: 'Назад'; ?>
            </a>
        <?php endif; ?>

        <?php if ($isProfileOwner) : ?>
            <a class="btn btn-xs btn-default" href="<?php echo Route::_('index.php?option=com_users&view=profile&layout=edit', false); ?>">
                <i class="fa fa-cog"></i> <?php echo Text::_('COM_USERS_EDIT_PROFILE') ?: 'Редактировать профиль'; ?>
            </a>
            <a class="btn btn-xs btn-default" href="<?php echo Route::_('index.php?view=profile&layout=appointments', false); ?>">
                <i class="fa fa-calendar"></i> <?php echo Text::_('COM_USERS_APPOINTMENTS') ?: 'Записи'; ?>
            </a>
        <?php endif; ?>

        <?php if (!$isProfileOwner && $currentUserId > 0) :
            // Проверка контактных данных для отправки сообщения
            try {
                $contactQuery = $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName($prefix . 'contact_details'))
                    ->where($db->quoteName('user_id') . ' = ' . $profileUserId)
                    ->where($db->quoteName('published') . ' = 1');
                $db->setQuery($contactQuery);
                $contactId = $db->loadResult();

                if ($contactId) {
                    $contactMenu = $app->getMenu()->getItems('link', 'index.php?option=com_contact&view=featured', true);
                    $itemid = '';
                    if (is_array($contactMenu) && !empty($contactMenu)) {
                        $itemid = current($contactMenu)->id;
                    }
                    ?>
                    <a class="btn btn-xs btn-default"
                       href="<?php echo Route::_('index.php?option=com_contact&view=contact&Itemid=' . (int)$itemid . '&id=' . (int)$contactId, false); ?>">
                        <i class="fa fa-envelope"></i> <?php echo Text::_('JGLOBAL_EMAIL') ?: 'Email'; ?>
                    </a>
                    <?php
                }
            } catch (Exception $e) {
                // Игнорировать ошибки
            }
        endif; ?>
    </div>

    <!-- Список услуг по специальностям -->
    <div class="master-services-list">
        <?php
        // Получить специальности мастера (field_id=29)
        $query = $db->getQuery(true)
            ->select('DISTINCT value')
            ->from($db->quoteName($prefix . 'fields_values'))
            ->where('field_id = 29')
            ->where('item_id = ' . $profileUserId);
        $db->setQuery($query);
        $specValues = $db->loadColumn();

        if (!empty($specValues)) {
            $specIds = [];
            foreach ($specValues as $v) {
                $ids = array_filter(array_map('intval', explode(',', $v)));
                $specIds = array_merge($specIds, $ids);
            }

            if (!empty($specIds)) {
                // Получить информацию о категориях специальностей
                $categoryQuery = $db->getQuery(true)
                    ->select(['id', 'title'])
                    ->from($db->quoteName($prefix . 'categories'))
                    ->where('id IN (' . implode(',', $specIds) . ')')
                    ->where('published = 1')
                    ->order('title ASC');
                $db->setQuery($categoryQuery);
                $categories = $db->loadAssocList();

                foreach ($categories as $category) {
                    $categoryId = (int)$category['id'];
                    $categoryTitle = htmlspecialchars($category['title']);

                    // Получить услуги в этой категории и её дочерних категориях
                    $serviceQuery = $db->getQuery(true)
                        ->select(['c.id', 'c.title', 'c.alias', 'c.state', 'c.catid'])
                        ->from($db->quoteName($prefix . 'content', 'c'))
                        ->innerJoin($db->quoteName($prefix . 'categories', 'cat') . ' ON c.catid = cat.id')
                        ->where('cat.parent_id = ' . $categoryId)
                        ->where('c.created_by = ' . $profileUserId)
                        ->where('c.state IN (0, 1)')
                        ->order('c.title ASC');
                    $db->setQuery($serviceQuery);
                    $services = $db->loadAssocList();

                    if (!empty($services)) {
                        ?>
                        <div class="priceList-section">
                            <h3 class="priceList-section-title"><?php echo $categoryTitle; ?></h3>
                            <div class="priceList">
                                <?php foreach ($services as $service) :
                                    $serviceId = (int)$service['id'];
                                    $serviceName = htmlspecialchars($service['title']);
                                    $isPublished = (int)$service['state'] === 1;
                                    $catId = (int)$service['catid'];
                                    ?>
                                    <div class="priceList__item d-flex justify-content-between align-items-center">
                                        <div class="priceList__item-coll">
                                            <?php echo $serviceName; ?>
                                        </div>
                                        <div class="icons-coll d-flex align-items-center gap-2">
                                            <?php if (!$isPublished) : ?>
                                                <button class="btn btn-sm btn-outline-secondary" title="На модерации">
                                                    <i class="fa fa-times" aria-hidden="true"></i>
                                                </button>
                                            <?php else : ?>
                                                <button class="btn btn-sm btn-outline-success" title="Опубликовано">
                                                    <i class="fa fa-check" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($isProfileOwner) : ?>
                                                <a href="<?php echo Route::_('index.php?option=com_content&task=article.edit&id=' . $serviceId, false); ?>"
                                                   class="btn btn-sm btn-outline-primary" title="Редактировать">
                                                    <i class="fa fa-pencil" aria-hidden="true"></i>
                                                </a>
                                                <button class="btn btn-sm btn-outline-danger delete-service"
                                                        data-id="<?php echo $serviceId; ?>"
                                                        data-name="<?php echo htmlspecialchars($serviceName); ?>"
                                                        title="Удалить">
                                                    <i class="fa fa-trash" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php
                    }
                }
            }
        } else {
            echo '<div class="alert alert-info">Специальности не указаны</div>';
        }
        ?>
    </div>

    <!-- Справка по редактированию -->
    <?php if ($isProfileOwner) : ?>
        <div class="alert alert-info mt-3">
            <p>Для добавления новых услуг перейдите в <a href="<?php echo Route::_('index.php?option=com_content&task=article.add&catid=16', false); ?>">личный кабинет</a></p>
        </div>
    <?php endif; ?>
</div>

<style>
.master-services-list {
    margin-top: 2rem;
}

.priceList-section {
    margin-bottom: 2rem;
}

.priceList-section-title {
    font-size: 1.25rem;
    font-weight: 600;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 0.5rem;
    margin-bottom: 1rem;
}

.priceList {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.priceList__item {
    padding: 0.75rem 1rem;
    background-color: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    transition: background-color 0.2s;
}

.priceList__item:hover {
    background-color: #f0f0f0;
}

.priceList__item-coll {
    flex: 1;
    font-weight: 500;
}

.icons-coll {
    gap: 0.5rem;
}

.gap-2 {
    gap: 0.5rem;
}
</style>
