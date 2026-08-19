$(document).ready(function($){	
	
	
	$('.slider__home').slick({infinite: true,slidesToShow: 1,
		slidesToScroll:1,pauseOnHover:false,pauseOnFocus:false,arrows: true
	});
	
	$('.calendar__master').not('.calendar__master--manual').slick({
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
	var $bigImg = $('.masters__big-img');
	var $smallImg = $('.masters__small-img');
	if ($bigImg.length && $bigImg.children().length && !$bigImg.hasClass('slick-initialized')) {
		$bigImg.slick({
			slidesToShow: 1,
			slidesToScroll: 1,
			arrows: true,
			fade: true,
			infinite: true,
			mobileFirst: true,
			accessibility: false,
			prevArrow: $bigImg.closest('.masters__big-img-cont').find(".my-slick-prev"),
			nextArrow: $bigImg.closest('.masters__big-img-cont').find(".my-slick-next"),
			asNavFor: $smallImg.length ? '.masters__small-img' : null
		});
	}
	if ($smallImg.length && $smallImg.children().length && !$smallImg.hasClass('slick-initialized')) {
		$smallImg.slick({
			slidesToShow: 4,
			slidesToScroll: 1,
			asNavFor: $bigImg.length ? '.masters__big-img' : null,
			dots: false,
			arrows: false,
			centerMode: true,
			focusOnSelect: true,
			accessibility: false
		});
	}
	if ($bigImg.length || $smallImg.length) {
		$(window).on('resize orientationchange', function() {
			if ($bigImg.hasClass('slick-initialized')) $bigImg.slick('setPosition');
			if ($smallImg.hasClass('slick-initialized')) $smallImg.slick('setPosition');
		});
	}
	$(window).scroll(function(){
		if ($(window).scrollTop() >= $('header').height())
			$('header').addClass('scrolled');
		else $('header').removeClass('scrolled');
	});
	$(window).scroll();
});
