<?php
/**
 * Форма добавления/редактирования услуги
 * Joomla 4+ совместимая версия
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;

defined('_JEXEC') or die;

$db = Factory::getContainer()->get('DatabaseDriver');
$prefix = $db->getPrefix();
$userID = isset($user) ? (int)$user->id : 0;
$task = !empty($_GET['s_id']) ? 'edit' : 'create';
$empty = false;
$uploaddir = $_SERVER['DOCUMENT_ROOT'] . DS . 'images' . DS . 'gallery' . DS . $userID;

$spec = [];
$category = [];
$editData = [];

if ($userID) {
    // Получить специальности мастера (field_id=29)
    $query = $db->getQuery(true)
        ->select('DISTINCT value')
        ->from($db->quoteName($prefix . 'fields_values'))
        ->where('field_id = 29 AND item_id = ' . $userID);
    $db->setQuery($query);
    $specValues = $db->loadColumn();

    // Для каждой специальности получить ID категории
    if (!empty($specValues)) {
        $specIds = [];
        foreach ($specValues as $v) {
            // Значение может быть просто ID или список ID через запятую
            $ids = array_filter(array_map('intval', explode(',', $v)));
            $specIds = array_merge($specIds, $ids);
        }

        if (!empty($specIds)) {
            // Получить информацию о категориях
            $query = $db->getQuery(true)
                ->select(['id', 'title'])
                ->from($db->quoteName($prefix . 'categories'))
                ->where('id IN (' . implode(',', $specIds) . ')')
                ->where('published = 1')
                ->order('title ASC');
            $db->setQuery($query);
            $category = $db->loadAssocList();
        }
    }

    // При редактировании загрузить данные услуги
    if ($task === 'edit' && !empty($_GET['s_id'])) {
        $serviceId = (int)$_GET['s_id'];

        // Получить основные данные из #__content
        $query = $db->getQuery(true)
            ->select(['id', 'title', 'catid', 'created_by'])
            ->from($db->quoteName($prefix . 'content'))
            ->where('id = ' . $serviceId);
        $db->setQuery($query);
        $editData = $db->loadAssoc();

        if (!$editData) {
            $task = 'create';
            $editData = [];
        } else {
            // Получить доп. поля из #__fields_values
            $query = $db->getQuery(true)
                ->select(['field_id', 'value'])
                ->from($db->quoteName($prefix . 'fields_values'))
                ->where('item_id = ' . $serviceId)
                ->order('field_id');
            $db->setQuery($query);
            $fieldsData = $db->loadAssocList('field_id');

            // Преобразовать в ассоциативный массив field_id => value
            foreach ($fieldsData as $fieldId => $fieldData) {
                $editData['field_' . $fieldId] = $fieldData['value'];
            }
        }
    }
}

?>

<script>
jQuery(document).ready(function() {
    <?php if ($task !== 'edit') { ?>
        // Обработка выбора специализации
        jQuery(".spec_1").chosen().on('change', function () {
            let param = jQuery(this).val();
            if (param !== "0") {
                goPreloader('start');
                jQuery.ajax({
                    url: '/function.php',
                    data: {action: 'getCategory', param: param},
                    type: 'post',
                    success: function(output) {
                        jQuery("#spec_2 .sel").html(output);
                        jQuery("#spec_2").addClass('d-block');
                        jQuery('.spec_2').chosen();
                        goPreloader('stop');
                    },
                    error: function() {
                        alert('Ошибка загрузки категорий');
                        goPreloader('stop');
                    }
                });
            } else {
                jQuery("#spec_2 .sel").html('');
                jQuery("#spec_2").removeClass('d-block');
                jQuery("#spec_3 .sel").html('');
                jQuery("#spec_3").removeClass('d-block');
            }
        });

        // Обработка выбора категории
        jQuery("#spec_2").on('change', 'select', function () {
            let param = jQuery(".spec_2").val();
            if (param !== "0") {
                goPreloader('start');
                jQuery.ajax({
                    url: '/function.php',
                    data: {action: 'getMetod', param: param},
                    type: 'post',
                    success: function(output) {
                        jQuery("#spec_3 .sel").html(output);
                        jQuery("#spec_3").addClass('d-block');
                        jQuery('.spec_3').chosen();
                        goPreloader('stop');
                    },
                    error: function() {
                        alert('Ошибка загрузки методов');
                        goPreloader('stop');
                    }
                });
            } else {
                jQuery("#spec_3 .sel").html('');
                jQuery("#spec_3").removeClass('d-block');
            }
        });
    <?php } else { ?>
        // При редактировании - загрузить категории
        if (jQuery('select[name="specialisation"]').val() !== "0") {
            let param = jQuery('select[name="specialisation"]').val();
            jQuery.ajax({
                url: '/function.php',
                data: {action: 'getCategory', param: param},
                type: 'post',
                success: function(output) {
                    jQuery("#spec_2 .sel").html(output);
                    jQuery("#spec_2").addClass('d-block');
                    jQuery(".spec_2").val(<?php echo (int)$editData['catid']; ?>).chosen();
                }
            });
        }
    <?php } ?>

    // Инициализация timepicker
    jQuery('.time1').timepicker({
        'show2400': true,
        'minTime': '00:15',
        'maxTime': '04:00',
        'timeFormat': 'H:i',
        'step': '15'
    });

    jQuery('.time2').timepicker({
        'show2400': true,
        'minTime': '00:00',
        'maxTime': '01:00',
        'timeFormat': 'H:i',
        'step': '5'
    });

    // Выбор фото услуги
    jQuery(".s_img .thumbnail").on('click', function () {
        jQuery(".s_img .thumbnail").removeClass("select");
        jQuery(this).addClass("select");
        jQuery("input[name=foto_service]").val(jQuery(this).find("img").attr('src'));
    });

    // Отправка формы
    jQuery('#create_service').on('submit', function(e) {
        let err = false;
        e.preventDefault();

        // Валидация обязательных select полей
        jQuery("#create_service select:not('.spec_3')").each(function (index, el) {
            let n = jQuery(el).val();
            if (n == 0 || n === "") {
                err = true;
                let $container = jQuery(el).closest('.sel').find(".chzn-container");
                if (!$container.find(".choseninfo").length) {
                    $container.append('<i class="fa fa-exclamation-circle choseninfo" aria-hidden="true"></i>');
                }
            } else {
                jQuery(el).closest('.sel').find(".choseninfo").remove();
            }
        });

        if (!err) {
            let $data = jQuery(this).serialize();
            jQuery.ajax({
                url: '/templates/ryba/html/com_content/article/article_create.php',
                data: $data,
                type: 'POST',
                success: function(output) {
                    jQuery.fancybox.open(output);
                    jQuery('#create_service')[0].reset();
                    sendmoderatormail('create', <?php echo $userID; ?>);
                },
                error: function(xhr, status, error) {
                    alert('Ошибка: ' + error);
                }
            });
        }
    });
});
</script>

<?php if ($userID) { ?>
    <?php if (!empty($category)) { ?>
        <!-- Форма добавления/редактирования услуги -->
        <div class="container">
            <form action="" id="create_service" method="post">
                <div class="row">
                    <!-- Название услуги -->
                    <div class="col-12" id="spec_0">
                        <p>Название услуги</p>
                        <input class="form-control name validate" name="name" type="text" placeholder="Наименование"
                               value="<?php echo isset($editData['title']) ? htmlspecialchars($editData['title']) : ''; ?>"
                               required>
                    </div>

                    <!-- Специализация -->
                    <div class="col-12" id="spec_1">
                        <p>Специализация</p>
                        <div class='sel'>
                            <select class="form-control spec_1 chosen-select" name="specialisation"
                                    data-placeholder="Выбрать..." required>
                                <option value="0"></option>
                                <?php foreach ($category as $item) { ?>
                                    <option value="<?php echo (int)$item['id']; ?>"
                                            <?php echo (isset($editData['field_69']) && $editData['field_69'] == $item['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($item['title']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>

                    <!-- Категория (подкатегория) -->
                    <div class="col-12 d-none" id="spec_2">
                        <p>Категория</p>
                        <div class='sel'>
                            <select class="form-control spec_2 chosen-select" name="category"
                                    data-placeholder="Выбрать..." required>
                                <option value="0"></option>
                            </select>
                        </div>
                    </div>

                    <!-- Метод -->
                    <div class="col-12 d-none" id="spec_3">
                        <p>Метод</p>
                        <div class='sel'>
                            <select class="form-control spec_3 chosen-select" name="metod[]"
                                    data-placeholder="Выбрать..." multiple>
                                <option value="0"></option>
                            </select>
                        </div>
                    </div>

                    <!-- Тип мастера -->
                    <div class="col-12" id="spec_4">
                        <p>Тип мастера</p>
                        <div class='sel'>
                            <select class="form-control spec_4 chosen-select" name="type" data-placeholder="Выбрать..."
                                    required>
                                <option value="0">Выберите тип...</option>
                                <option value="Салон" <?php echo (isset($editData['field_57']) && $editData['field_57'] === 'Салон') ? 'selected' : ''; ?>>Салон</option>
                                <option value="Вызов на дом" <?php echo (isset($editData['field_57']) && $editData['field_57'] === 'Вызов на дом') ? 'selected' : ''; ?>>Вызов на дом</option>
                                <option value="Мастер на дому" <?php echo (isset($editData['field_57']) && $editData['field_57'] === 'Мастер на дому') ? 'selected' : ''; ?>>Мастер на дому</option>
                            </select>
                        </div>
                    </div>

                    <!-- Продолжительность -->
                    <div class="col-12" id="spec_5">
                        <p>Продолжительность</p>
                        <input class="form-control time1 validate" name="duration" placeholder="00:00" required
                               value="<?php echo isset($editData['field_66']) ? htmlspecialchars($editData['field_66']) : ''; ?>">
                    </div>

                    <!-- Перерыв после записи -->
                    <div class="col-12" id="spec_6">
                        <p>Перерыв после записи</p>
                        <input class="form-control time2 validate" name="pause" placeholder="00:00" required
                               value="<?php echo isset($editData['field_67']) ? htmlspecialchars($editData['field_67']) : ''; ?>">
                    </div>

                    <!-- Стоимость -->
                    <div class="col-12" id="spec_7">
                        <p>Стоимость</p>
                        <div class="row">
                            <div class="col-6">
                                <div class='sel'>
                                    <select class="form-control validate chosen-select" name="fix" data-placeholder="Выбрать тип цены..."
                                            required>
                                        <option value="0"></option>
                                        <option value="1" <?php echo (isset($editData['field_64']) && $editData['field_64'] == '1') ? 'selected' : ''; ?>>Фиксированная</option>
                                        <option value="2" <?php echo (isset($editData['field_64']) && $editData['field_64'] == '2') ? 'selected' : ''; ?>>Начальная</option>
                                    </select>
                                </div>
                                <input class="form-control cost validate" name="cost" type="number" min="0" step="100"
                                       placeholder="1 000" required
                                       value="<?php echo isset($editData['field_70']) ? htmlspecialchars($editData['field_70']) : ''; ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Фото услуги -->
                    <div class="col-12" id="spec_8">
                        <p>Фото услуги</p>
                        <ul class="d-flex s_img">
                            <?php
                            $url = '';
                            if (is_dir($uploaddir)) {
                                $files = glob($uploaddir . '/*.{jpg,png,jpeg,JPG,PNG,JPEG}', GLOB_BRACE);
                                if (!empty($files)) {
                                    foreach ($files as $n => $file) {
                                        $selected = ($n === 0) ? ' select' : '';
                                        if ($n === 0) {
                                            $url = "/images/gallery/" . $userID . '/' . basename($file);
                                        }
                                        ?>
                                        <li class="thumbnail<?php echo $selected; ?>">
                                            <img src="/images/gallery/<?php echo $userID; ?>/<?php echo basename($file); ?>"
                                                 alt="">
                                        </li>
                                        <?php
                                    }
                                } else {
                                    echo '<li class="thumbnail"><p>Нет загруженных фотографий</p></li>';
                                }
                            }
                            ?>
                        </ul>
                        <input type="hidden" name="foto_service" value="<?php echo htmlspecialchars($url); ?>">
                        <input type="hidden" name="user_id" value="<?php echo $userID; ?>">
                    </div>

                    <!-- Кнопка отправки -->
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <?php echo ($task === 'edit') ? 'Обновить услугу' : 'Добавить услугу'; ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php } else { ?>
        <!-- Профиль не заполнен -->
        <div class="container">
            <div class="row">
                <div class="col">
                    <p>Ваш профиль заполнен не полностью. <a
                            href="<?php echo Route::_('index.php?option=com_users&view=profile&layout=edit#profile-tab1', false); ?>">Перейдите
                            в кабинет и заполните Ваш профиль.</a></p>
                </div>
            </div>
        </div>
    <?php } ?>
<?php } else { ?>
    <!-- Пользователь не авторизован -->
    <div class="container">
        <div class="row">
            <div class="col">
                <p>Сессия истекла. Необходимо <a
                        href="<?php echo Route::_('index.php?option=com_users&view=login', false); ?>">авторизоваться</a>
                </p>
            </div>
        </div>
    </div>
<?php } ?>
