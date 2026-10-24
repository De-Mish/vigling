<?php

/**
 * @copyright
 * @package    EBR - Easybook Reloaded - Latest Entries Module Joomla 3.x - Module
 * @author     Viktor Vogel <admin@kubik-rubik.de>
 * @version    3.3.0-FREE - 2020-09-12
 * @link       https://kubik-rubik.de/ebr-easybook-reloaded
 *
 * @license    GNU/GPL
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
defined('_JEXEC') || die('Restricted access');

$user=JsnHelper::getUser();
$link = $user->get('id') ? '#' : JRoute::_('index.php?option=com_users&view=login&return='.base64_encode(JUri::current()));
?>
<h2>Отзывы</h2>
<div id="review__master" class="review__master-head">
	<a href="<?php echo $link?>">Написать отзыв</a>
	<span class="easylast_noentry <?php echo $suffix; ?>">
		<?php echo JText::_('Нет отзывов'); ?>
	</span>
	<form id="review-form" name="review-form" class="form-horizontal collapsed" action="/" method="post">
		<div class="col-md-6" style="">
			<div class="form-group">
				<input type="text" id="name" class="form-control" name="name" required value="<?php echo $user->getValue('name')?>" placeholder="Ваше имя" />
			</div>
			<div class="form-group">
				<textarea class="form-control" id="message" name="message" placeholder="Текст отзыва..." rows="5" required></textarea>
			</div>
			<div class="form-group inline">
				<div class="review__item-rate">
					<ul class="category_cinfo-ratings ratings-big">
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
					</ul>
					<input type="text" name="rating" value="5.0" class="rating" readonly />	
				</div>
				<button type="button" class="btn2 btn__time-zapis">Отправить</button>
				<img src="/templates/ryba/images/loading.gif" alt="" />
				<input type="hidden" name="task" value="review" />
				<?php echo JHtml::_('form.token'); ?>	
			</div>
		</div>
	</form>
</div>
<script>
	jQuery(document).ready(function($) {
		$('#review-form .ratings-big i').on('click', function(e){
			var is_half = (1!=parseInt(e.target.clientWidth / (e.pageX-$(this).offset().left)));
			$(e.target).parent().nextAll().find('i').removeClass('fa-star fa-star-half-o').addClass('fa-star-o');
			$(e.target).parent().prevAll().find('i').removeClass('fa-star-o fa-star-half-o').addClass('fa-star');
			
			$(this).removeClass('fa-star fa-star-o fa-star-half-o').addClass(is_half ? 'fa-star-half-o' : 'fa-star');
			var rating = $(this).parent().parent().find('i.fa-star').length;
			if($(this).parent().parent().find('i.fa-star-half-o').length)
				rating = rating+0.5;
			else rating = rating+'.0';
			$('#review-form .review__item-rate .rating').val(rating);
		});
		
		$('#review-form button').on('click', function(e){
			if(!document.forms["review-form"].name.value)
				document.forms["review-form"].name.setCustomValidity("I expect an e-mail, darling!");
			else document.forms["review-form"].name.setCustomValidity('');
			if(!document.forms["review-form"].message.value)
				document.forms["review-form"].message.setCustomValidity("I expect an e-mail, darling!");
			else document.forms["review-form"].message.setCustomValidity('');
			$.ajax({type: 'POST', url: '<?php echo JUri::current()?>', dataType: 'json', data: $('#review-form').serialize(),
				beforeSend: function () { $('#review-form button').parent().addClass('waiting') },
				success: function (data) {
					$('#review-form .result-error').remove();
					if (data.success) {
						$('#review-form button').hide();
						$('#review-form button').prev().hide();
						$('#review-form button').parent().prepend('<div class="result-message"><i class="fa fa-check" style="color: green;"></i> Отзыв успешно добавлен, он будет отображаться после проверки администратором</div>');
					}
					else $('#review-form button').parent().prepend('<div class="result-error"><i class="fa fa-exclamation" style="color: red;"></i> '+data.message+'</div>');
				},
				complete: function () { $('#review-form button').parent().removeClass('waiting') }
			});
		});
		$('.review__master-head>a').click(function(e){
			if($(e.target).attr('href')=='#'){
				e.preventDefault();
				$('#review-form').toggleClass('show');
			}
		});
	});
</script>



