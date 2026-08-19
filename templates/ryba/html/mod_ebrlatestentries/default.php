<?php defined('_JEXEC') || die('Restricted access');

$user=JsnHelper::getUser();
$link = $user->get('id') ? '#' : JRoute::_('index.php?option=com_users&view=login&return='.base64_encode(JUri::current()));
$pages=ceil(count($posts)/2);

?>
<h2>Отзывы</h2>
<div id="review__master" class="review__master-head">
	<a href="<?php echo $link?>">Написать отзыв</a>
	<span><?php echo declOfNum(count($posts), ' ', array('отзыв','отзыва','отзывов'))?></span>
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
<div class="review__master-body <?php echo $suffix; ?>">
    <?php foreach($posts as $k=>$post) : ?>
        <div class="review__master-body-item flipInX<?php echo ($k>1) ? ' hidden' : ''?>">
			<div class="review__item-data">
				<span><?php echo $post['gbname']?></span>
				<i><?php echo JHTML::_('date', $post['gbdate'], JText::_('DATE_FORMAT_LC2'))?></i>
			</div>
			<div class="review__item-rate">
				<?php echo number_format($post['gbvote'], 1, '.', '')?>
				<ul class="category_cinfo-ratings">
					<?php foreach(range(1,5) as $r){
						if($r<=floor($post['gbvote']))
							echo '<li><i class="fa fa-star" aria-hidden="true"></i></li>';
						elseif($r==ceil($post['gbvote'])) 
							echo '<li><i class="fa fa-star-half" aria-hidden="true"></i></li>';
					} ?>
				</ul>
			</div>
            <div class="review__text">
                <?php echo $post['gbcomment']; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if($pages>1) : ?>
<div class="pagination__wrap page-nat ">
	<ul class="pagination">
	<?php for($i=1; $i<=$pages; $i++){ ?>
		<li><a<?php echo ($i==1) ? ' class="active"':''?> href="#"><?php echo $i?></a></li>
	<?php } ?>
	</ul>
	<div class="page__arrows">
		<a class="page__arrow-left" href="#"><i class="fa fa-angle-left" aria-hidden="true"></i></a>
		<a class="page__arrow-right" href="#"><i class="fa fa-angle-right" aria-hidden="true"></i></a>
	</div>
	<div class="clearFloat"></div>
</div>
<?php endif; ?>
<script>
	jQuery(document).ready(function($) {
		$('.pagination__wrap').on('click', 'a', function(e){
			e.preventDefault();
			var current = $('.pagination__wrap a.active');
			if(e.target.tagName=='I')
				e.target=$(e.target).parent()[0];

			if((e.target.className=='page__arrow-left') && !current.parent().prev().length)
				return false;
			if((e.target.className=='page__arrow-right') && !current.parent().next().length)
				return false;
			
			$('.pagination__wrap a.active').removeClass('active');
			if(e.target.className=='page__arrow-left')
				current.parent().prev().find('a').addClass('active');
			else if(e.target.className=='page__arrow-right')
				current.parent().next().find('a').addClass('active');
			else $(this).addClass('active');
			
			var start_idx = parseInt($('.pagination__wrap a.active').text());
			if(!start_idx)
				return false;

			$('.review__master-body-item').addClass('hidden');
			$('.review__master-body-item:eq('+((start_idx-1)*2)+')').removeClass('hidden');
			$('.review__master-body-item:eq('+((start_idx-1)*2+1)+')').removeClass('hidden');
		});
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


