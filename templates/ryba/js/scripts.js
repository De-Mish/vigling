$(document).ready(function($){	
	
	
	$('.slider__home').slick({infinite: true,slidesToShow: 1,
		slidesToScroll:1,pauseOnHover:false,pauseOnFocus:false,arrows: true
	});
	
	$('.calendar__master').not('.calendar__master--manual').filter(function(){
		return $(this).closest('.modal').length === 0;
	}).slick({
		infinite: false,
		slidesToShow: 5,
		slidesToScroll: 1,
		dots: false,
		arrows: true,
		accessibility: false,
		responsive: [
			{
				breakpoint: 1024,
				settings: {slidesToShow: 5,slidesToScroll: 1,}
			},
			{
				breakpoint: 820,
				settings: {slidesToShow: 1,slidesToScroll: 1}
			}
		]
	});
	
	
	$('.news-slider').slick({
		infinite: true,
		slidesToShow: 2,
		slidesToScroll: 2,
		arrows: true,
		responsive: [
			{
				breakpoint: 1024,
				settings: {
					slidesToShow: 2,
					slidesToScroll: 2,
				}
			},
			{
				breakpoint: 820,
				settings: {
					slidesToShow: 1,
					slidesToScroll: 1
				}
			}

		]
	});
	$('.masters__big-img-cont').each(function () {
		var $cont = $(this);
		var $bigImg = $cont.children('.masters__big-img');
		if (!$bigImg.length) {
			$bigImg = $cont.find('.masters__big-img').first();
		}
		var $info = $cont.nextAll('.masters__big-info').first();
		var $smallImg = $info.find('.masters__small-img').first();
		if ($smallImg.hasClass('slick-initialized')) {
			$smallImg.slick('unslick');
		}
		if ($bigImg.length && $bigImg.children('.masters__big-img-item').length && !$bigImg.hasClass('slick-initialized')) {
			$bigImg.slick({
				slidesToShow: 1,
				slidesToScroll: 1,
				arrows: true,
				fade: true,
				infinite: true,
				accessibility: false,
				prevArrow: $cont.find('.my-slick-prev'),
				nextArrow: $cont.find('.my-slick-next')
			});
		}
		function showBigAt(idx) {
			var count = $bigImg.hasClass('slick-initialized')
				? $bigImg.slick('getSlick').slideCount
				: $bigImg.children('.masters__big-img-item').length;
			if (!count) {
				return;
			}
			idx = ((idx % count) + count) % count;
			if ($bigImg.hasClass('slick-initialized')) {
				$bigImg.slick('slickGoTo', idx);
				return;
			}
			$bigImg.children('.masters__big-img-item').each(function (i) {
				$(this).css('display', i === idx ? 'block' : 'none');
			});
		}
		$info.find('.masters__gall-small').off('click.vgGall').on('click.vgGall', '.masters__small-img-item', function () {
			var idx = $smallImg.children('.masters__small-img-item').index(this);
			if (idx < 0) {
				return;
			}
			showBigAt(idx);
		});
		if (!$bigImg.hasClass('slick-initialized')) {
			$cont.find('.my-slick-next').off('click.vgGall').on('click.vgGall', function () {
				var $items = $bigImg.children('.masters__big-img-item');
				var cur = $items.index($items.filter(':visible').first());
				showBigAt(cur + 1);
			});
			$cont.find('.my-slick-prev').off('click.vgGall').on('click.vgGall', function () {
				var $items = $bigImg.children('.masters__big-img-item');
				var cur = $items.index($items.filter(':visible').first());
				showBigAt(cur - 1);
			});
		}
		$(window).on('resize.vgGall orientationchange.vgGall', function () {
			if ($bigImg.hasClass('slick-initialized')) {
				$bigImg.slick('setPosition');
			}
		});
	});
	$(window).scroll(function(){
		if ($(window).scrollTop() >= $('header').height())
			$('header').addClass('scrolled');
		else $('header').removeClass('scrolled');
	});
	$(window).scroll();
});
