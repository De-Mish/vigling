jQuery(function($) {

if ($('.registration form').length > 0) {
    // при загрузке скрываем только служебные поля логина/email2 (подтверждение пароля оставляем видимым)
    $('#jform_username, #jform_email2').parents('.control-group').css('display', 'none');

    // при вводе email вводим в логин то же самое
    $('#jform_email1').on('input', function() {
        $('#jform_username').val($(this).val());
        $('#jform_email2').val($(this).val());
    });
}
});
