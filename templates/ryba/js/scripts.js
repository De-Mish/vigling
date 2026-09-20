(function (document) {
	function galleryInfo(cont) {
		var node = cont ? cont.nextElementSibling : null;
		while (node && !(node.classList && node.classList.contains('masters__big-info'))) {
			node = node.nextElementSibling;
		}
		return node;
	}

	function galleryThumbs(cont) {
		var info = galleryInfo(cont);
		if (!info) {
			return [];
		}
		return info.querySelectorAll('.masters__small-img-item[data-full-src]');
	}

	function galleryContFrom(el) {
		if (!el || !el.closest) {
			return null;
		}
		var cont = el.closest('.masters__big-img-cont');
		if (cont) {
			return cont;
		}
		var info = el.closest('.masters__big-info');
		if (!info) {
			return null;
		}
		var prev = info.previousElementSibling;
		while (prev && !(prev.classList && prev.classList.contains('masters__big-img-cont'))) {
			prev = prev.previousElementSibling;
		}
		return prev;
	}

	function galleryIndex(cont) {
		return parseInt(cont.getAttribute('data-vg-idx') || '0', 10) || 0;
	}

	function showGalleryAt(cont, nextIdx) {
		if (!cont) {
			return;
		}
		var thumbs = galleryThumbs(cont);
		if (!thumbs.length) {
			return;
		}
		var idx = ((nextIdx % thumbs.length) + thumbs.length) % thumbs.length;
		var src = thumbs[idx].getAttribute('data-full-src') || '';
		var photo = cont.querySelector('.masters__big-img-photo');
		var item = cont.querySelector('.masters__big-img-item');
		if (photo && src) {
			if (photo.getAttribute('src') !== src) {
				photo.setAttribute('src', src);
			}
		} else if (item && src) {
			item.style.backgroundImage = 'url("' + String(src).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '")';
		}
		for (var i = 0; i < thumbs.length; i++) {
			if (i === idx) {
				thumbs[i].classList.add('is-current');
			} else {
				thumbs[i].classList.remove('is-current');
			}
		}
		cont.setAttribute('data-vg-idx', String(idx));
	}

	document.addEventListener('click', function (e) {
		var target = e.target;
		if (!target || !target.closest) {
			return;
		}
		var nextBtn = target.closest('.masters__big-img-cont .my-slick-next');
		var prevBtn = target.closest('.masters__big-img-cont .my-slick-prev');
		if (nextBtn || prevBtn) {
			var arrowCont = galleryContFrom(nextBtn || prevBtn);
			if (!arrowCont) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			showGalleryAt(arrowCont, galleryIndex(arrowCont) + (nextBtn ? 1 : -1));
			return;
		}
		var thumb = target.closest('.masters__small-img-item[data-full-src]');
		if (!thumb) {
			return;
		}
		var thumbCont = galleryContFrom(thumb);
		if (!thumbCont) {
			return;
		}
		e.preventDefault();
		var thumbs = galleryThumbs(thumbCont);
		var thumbIdx = Array.prototype.indexOf.call(thumbs, thumb);
		if (thumbIdx < 0) {
			return;
		}
		showGalleryAt(thumbCont, thumbIdx);
	});

	var touchX = null;
	var touchCont = null;
	document.addEventListener('touchstart', function (e) {
		var startEl = e.target && e.target.closest ? e.target.closest('.masters__big-img') : null;
		if (!startEl) {
			touchX = null;
			touchCont = null;
			return;
		}
		var t = e.changedTouches && e.changedTouches[0];
		touchX = t ? t.clientX : null;
		touchCont = galleryContFrom(startEl);
	}, { passive: true });
	document.addEventListener('touchend', function (e) {
		if (touchX === null || !touchCont) {
			return;
		}
		var t = e.changedTouches && e.changedTouches[0];
		var endX = t ? t.clientX : touchX;
		var dx = endX - touchX;
		var cont = touchCont;
		touchX = null;
		touchCont = null;
		if (Math.abs(dx) < 40) {
			return;
		}
		showGalleryAt(cont, galleryIndex(cont) + (dx < 0 ? 1 : -1));
	}, { passive: true });
})(document);

$(document).ready(function($){
	function safeSlick($els, opts) {
		if (!$els || !$els.length || typeof $els.slick !== 'function') {
			return;
		}
		try {
			$els.slick(opts);
		} catch (err) {}
	}

	safeSlick($('.slider__home'), {
		infinite: true,
		slidesToShow: 1,
		slidesToScroll: 1,
		pauseOnHover: false,
		pauseOnFocus: false,
		arrows: true
	});

	safeSlick($('.calendar__master').not('.calendar__master--manual').filter(function(){
		return $(this).closest('.modal').length === 0;
	}), {
		infinite: false,
		slidesToShow: 5,
		slidesToScroll: 1,
		dots: false,
		arrows: true,
		accessibility: false,
		responsive: [
			{
				breakpoint: 1024,
				settings: {slidesToShow: 5, slidesToScroll: 1}
			},
			{
				breakpoint: 820,
				settings: {slidesToShow: 1, slidesToScroll: 1}
			}
		]
	});

	safeSlick($('.news-slider'), {
		infinite: true,
		slidesToShow: 2,
		slidesToScroll: 2,
		arrows: true,
		responsive: [
			{
				breakpoint: 1024,
				settings: {
					slidesToShow: 2,
					slidesToScroll: 2
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

	$(window).scroll(function(){
		if ($(window).scrollTop() >= $('header').height())
			$('header').addClass('scrolled');
		else $('header').removeClass('scrolled');
	});
	$(window).scroll();
});
