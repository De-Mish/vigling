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
		if ($bigImg.hasClass('slick-initialized')) {
			$bigImg.slick('unslick');
		}
		if ($smallImg.hasClass('slick-initialized')) {
			$smallImg.slick('unslick');
		}
		var $slides = $bigImg.children('.masters__big-img-item');
		var $thumbs = $smallImg.children('.masters__small-img-item');
		var idx = 0;
		function showAt(nextIdx) {
			if (!$slides.length) {
				return;
			}
			idx = ((nextIdx % $slides.length) + $slides.length) % $slides.length;
			$slides.each(function (i) {
				$(this).css('display', i === idx ? 'block' : 'none');
			});
			$thumbs.removeClass('is-current').eq(idx).addClass('is-current');
		}
		showAt(0);
		$cont.find('.my-slick-next').off('click.vgGall').on('click.vgGall', function (e) {
			e.preventDefault();
			e.stopPropagation();
			showAt(idx + 1);
		});
		$cont.find('.my-slick-prev').off('click.vgGall').on('click.vgGall', function (e) {
			e.preventDefault();
			e.stopPropagation();
			showAt(idx - 1);
		});
		$smallImg.off('click.vgGall').on('click.vgGall', '.masters__small-img-item', function (e) {
			e.preventDefault();
			var thumbIdx = $thumbs.index(this);
			if (thumbIdx < 0) {
				return;
			}
			showAt(thumbIdx);
		});
		var touchX = null;
		$bigImg.off('touchstart.vgGall touchend.vgGall').on('touchstart.vgGall', function (e) {
			var t = e.originalEvent && e.originalEvent.touches && e.originalEvent.touches[0];
			touchX = t ? t.clientX : null;
		}).on('touchend.vgGall', function (e) {
			if (touchX === null) {
				return;
			}
			var t = e.originalEvent && e.originalEvent.changedTouches && e.originalEvent.changedTouches[0];
			var endX = t ? t.clientX : touchX;
			var dx = endX - touchX;
			touchX = null;
			if (Math.abs(dx) < 40) {
				return;
			}
			showAt(idx + (dx < 0 ? 1 : -1));
		});
	});
	$(window).scroll(function(){
		if ($(window).scrollTop() >= $('header').height())
			$('header').addClass('scrolled');
		else $('header').removeClass('scrolled');
	});
	$(window).scroll();
});
