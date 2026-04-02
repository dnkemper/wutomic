/**
 * @file
 * Unified Artsci Carousel behavior.
 *
 * Handles ALL carousel modes identified by [data-artsci-carousel]:
 *   - large:   3-card fan with center focus
 *   - mini:    Single slide visible at a time
 *   - paged:   N cards per page with dots (responsive: 3→2→1)
 *   - stories: Horizontal scroll with snap
 *
 * Slide discovery:
 *   Drupal renders paragraph entities inside a field wrapper div.
 *   This JS finds slides via [data-carousel-slide] first, then
 *   falls back to direct children of the track's first child element
 *   (the field wrapper). This means paragraph templates MUST add
 *   data-carousel-slide to their root element.
 *
 * Data attributes on the container ([data-artsci-carousel]):
 *   data-carousel-size="large|mini|paged|stories"
 *   data-carousel-loop="true"       → Wrap navigation (default: false)
 *   data-carousel-autoplay="5000"   → Auto-advance ms (default: off)
 *   data-carousel-per-page="3"      → Override desktop visible count (paged only)
 *
 * Internal elements (via data attributes):
 *   [data-carousel-track]      → Slide container
 *   [data-carousel-viewport]   → Overflow clip wrapper (paged)
 *   [data-carousel-prev]       → Previous button
 *   [data-carousel-next]       → Next button
 *   [data-carousel-dots]       → Dot pagination container (auto-filled)
 *   [data-carousel-slide]      → Individual slides (set by paragraph template)
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.artsciCarousel = {
    attach: function (context) {
      var carousels = once('artsci-carousel', '[data-artsci-carousel]', context);
      carousels.forEach(function (el) {
        var mode = el.dataset.carouselSize || 'large';
        switch (mode) {
          case 'paged':   initPagedCarousel(el);   break;
          case 'stories': initStoriesCarousel(el);  break;
          case 'mini':    initMiniCarousel(el);     break;
          default:        initLargeCarousel(el);    break;
        }
      });
    },
  };

  // ==================================================================
  // SHARED: Find slides inside a track element.
  //
  // Drupal renders: track > div.field > div.paragraph[data-carousel-slide]
  // This function handles both cases:
  //   1) Direct [data-carousel-slide] children (custom templates)
  //   2) Nested inside a field wrapper (standard Drupal rendering)
  // ==================================================================
  function findSlides(track) {
    // Try data attribute first.
    var slides = Array.from(track.querySelectorAll('[data-carousel-slide]'));
    if (slides.length > 0) return slides;

    // Fallback: look for .paragraph elements (Drupal default).
    slides = Array.from(track.querySelectorAll('.paragraph'));
    if (slides.length > 0) return slides;

    // Last resort: direct children of the first child (field wrapper).
    var fieldWrapper = track.firstElementChild;
    if (fieldWrapper && fieldWrapper.children.length > 0) {
      return Array.from(fieldWrapper.children);
    }

    return [];
  }

  // ==================================================================
  // SHARED: Reduced motion preference
  // ==================================================================
  var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ==================================================================
  // LARGE CAROUSEL — 3-card fan with center focus
  // ==================================================================
  function initLargeCarousel(container) {
    var track = container.querySelector('[data-carousel-track]');
    if (!track) return;
    var slides = findSlides(track);
    var prevBtn = container.querySelector('[data-carousel-prev]');
    var nextBtn = container.querySelector('[data-carousel-next]');

    if (slides.length < 1) return;

    var current = 0;
    var isAnimating = false;
    var total = slides.length;

    function mod(n) {
      return ((n % total) + total) % total;
    }

    function update() {
      var positions = {
        left:   mod(current - 1),
        center: mod(current),
        right:  mod(current + 1),
      };

      var trackWidth = track.offsetWidth;
      var slideWidth = Math.min(240, trackWidth * 0.3);

      slides.forEach(function (slide, i) {
        var pos = 'hidden';
        if (i === positions.left)   pos = 'left';
        if (i === positions.center) pos = 'center';
        if (i === positions.right)  pos = 'right';

        slide.setAttribute('data-slide-position', pos);
        slide.setAttribute('aria-hidden', pos === 'hidden' ? 'true' : 'false');
        slide.style.position = 'absolute';
        slide.style.top = '50%';
        slide.style.width = slideWidth + 'px';
        slide.style.transition = prefersReduced ? 'none' : 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';

        switch (pos) {
          case 'left':
            slide.style.left = '0';
            slide.style.right = 'auto';
            slide.style.transform = 'translateY(-50%) scale(0.82)';
            slide.style.opacity = '0.7';
            slide.style.zIndex = '1';
            slide.style.pointerEvents = 'auto';
            break;
          case 'center':
            slide.style.left = '50%';
            slide.style.right = 'auto';
            slide.style.marginLeft = -(slideWidth / 2) + 'px';
            slide.style.transform = 'translateY(-50%) scale(1)';
            slide.style.opacity = '1';
            slide.style.zIndex = '3';
            slide.style.pointerEvents = 'auto';
            break;
          case 'right':
            slide.style.left = 'auto';
            slide.style.right = '0';
            slide.style.transform = 'translateY(-50%) scale(0.82)';
            slide.style.opacity = '0.7';
            slide.style.zIndex = '1';
            slide.style.pointerEvents = 'auto';
            break;
          default:
            slide.style.opacity = '0';
            slide.style.zIndex = '0';
            slide.style.pointerEvents = 'none';
            break;
        }
      });
    }

    function goNext() {
      if (isAnimating) return;
      isAnimating = true;
      current = mod(current + 1);
      update();
      setTimeout(function () { isAnimating = false; }, 500);
    }

    function goPrev() {
      if (isAnimating) return;
      isAnimating = true;
      current = mod(current - 1);
      update();
      setTimeout(function () { isAnimating = false; }, 500);
    }

    if (nextBtn) nextBtn.addEventListener('click', goNext);
    if (prevBtn) prevBtn.addEventListener('click', goPrev);
    container.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { goNext(); e.preventDefault(); }
      if (e.key === 'ArrowLeft')  { goPrev(); e.preventDefault(); }
    });

    // Click on side slides to navigate.
    slides.forEach(function (slide) {
      slide.addEventListener('click', function () {
        var pos = slide.getAttribute('data-slide-position');
        if (pos === 'left')  goPrev();
        if (pos === 'right') goNext();
      });
    });

    current = Math.min(1, total - 1);
    update();
  }

  // ==================================================================
  // MINI CAROUSEL — single slide visible
  // ==================================================================
  function initMiniCarousel(container) {
    var track = container.querySelector('[data-carousel-track]');
    if (!track) return;
    var slides = findSlides(track);
    var prevBtn = container.querySelector('[data-carousel-prev]');
    var nextBtn = container.querySelector('[data-carousel-next]');

    if (slides.length < 1) return;

    var current = 0;
    var total = slides.length;

    function update() {
      slides.forEach(function (slide, i) {
        var isActive = i === current;
        slide.style.display = isActive ? 'block' : 'none';
        slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');
      });
    }

    function goNext() { current = (current + 1) % total; update(); }
    function goPrev() { current = (current - 1 + total) % total; update(); }

    if (nextBtn) nextBtn.addEventListener('click', goNext);
    if (prevBtn) prevBtn.addEventListener('click', goPrev);
    container.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { goNext(); e.preventDefault(); }
      if (e.key === 'ArrowLeft')  { goPrev(); e.preventDefault(); }
    });

    update();
  }

  // ==================================================================
  // PAGED CAROUSEL — N cards visible, page-based, dots
  // ==================================================================
  function initPagedCarousel(container) {
    var viewport = container.querySelector('[data-carousel-viewport]');
    var track = container.querySelector('[data-carousel-track]');
    if (!track) return;
    var slides = findSlides(track);
    var prevBtn = container.querySelector('[data-carousel-prev]');
    var nextBtn = container.querySelector('[data-carousel-next]');
    var dotsContainer = container.querySelector('[data-carousel-dots]');

    if (slides.length < 1) return;

    // Options.
    var loop = container.dataset.carouselLoop === 'true';
    var autoplayMs = parseInt(container.dataset.carouselAutoplay, 10) || 0;
    var desktopCount = parseInt(container.dataset.carouselPerPage, 10) || 3;
    var autoplayTimer = null;

    // State.
    var currentPage = 0;
    var perPage = getPerPage();
    var totalPages = getPages();

    // Remove the field wrapper's flex/layout interference.
    // Drupal wraps paragraphs in a .field div — we need the slides to
    // be direct flex children of a flex container.
    unwrapFieldDiv(track, slides);

    // Read CSS gap.
    var gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || 20;

    // ---- Breakpoints ----
    function getPerPage() {
      var w = window.innerWidth;
      if (w <= 600)  return 1;
      if (w <= 980)  return Math.min(2, desktopCount);
      return desktopCount;
    }

    function getPages() {
      return Math.max(1, Math.ceil(slides.length / perPage));
    }

    function getSlideWidth() {
      var vw = (viewport || track.parentElement || track).offsetWidth;
      return (vw - gap * (perPage - 1)) / perPage;
    }

    // ---- Render ----
    function update() {
      var sw = getSlideWidth();

      slides.forEach(function (slide) {
        slide.style.flex = '0 0 ' + sw + 'px';
        slide.style.maxWidth = sw + 'px';
      });

      var offset = currentPage * perPage * (sw + gap);
      var maxOffset = Math.max(0, (slides.length - perPage) * (sw + gap));
      offset = Math.min(offset, maxOffset);

      track.style.transition = prefersReduced ? 'none' : 'transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
      track.style.transform = 'translateX(-' + offset + 'px)';

      // Aria.
      var start = currentPage * perPage;
      var end = Math.min(start + perPage, slides.length);
      slides.forEach(function (slide, i) {
        var vis = i >= start && i < end;
        slide.setAttribute('aria-hidden', vis ? 'false' : 'true');
        slide.querySelectorAll('a, button, input, [tabindex]').forEach(function (el) {
          el.setAttribute('tabindex', vis ? '0' : '-1');
        });
      });

      updateArrows();
      updateDots();
    }

    function updateArrows() {
      if (!prevBtn || !nextBtn) return;
      if (loop) {
        prevBtn.disabled = false; nextBtn.disabled = false;
        return;
      }
      prevBtn.disabled = currentPage <= 0;
      prevBtn.setAttribute('aria-disabled', currentPage <= 0 ? 'true' : 'false');
      nextBtn.disabled = currentPage >= totalPages - 1;
      nextBtn.setAttribute('aria-disabled', currentPage >= totalPages - 1 ? 'true' : 'false');
    }

    // ---- Dots ----
    function buildDots() {
      if (!dotsContainer) return;
      dotsContainer.innerHTML = '';
      for (var i = 0; i < totalPages; i++) {
        var dot = document.createElement('button');
        dot.className = 'artsci-carousel__dot';
        dot.setAttribute('aria-label', 'Page ' + (i + 1) + ' of ' + totalPages);
        dot.dataset.page = i;
        dot.addEventListener('click', function () { goToPage(parseInt(this.dataset.page, 10)); });
        dotsContainer.appendChild(dot);
      }
      updateDots();
    }

    function updateDots() {
      if (!dotsContainer) return;
      dotsContainer.querySelectorAll('.artsci-carousel__dot').forEach(function (dot, i) {
        dot.classList.toggle('is-active', i === currentPage);
        dot.setAttribute('aria-current', i === currentPage ? 'step' : 'false');
      });
    }

    // ---- Navigation ----
    function goToPage(page) {
      currentPage = loop
        ? ((page % totalPages) + totalPages) % totalPages
        : Math.max(0, Math.min(page, totalPages - 1));
      update();
      resetAutoplay();
    }
    function goNext() { goToPage(currentPage + 1); }
    function goPrev() { goToPage(currentPage - 1); }

    if (nextBtn) nextBtn.addEventListener('click', goNext);
    if (prevBtn) prevBtn.addEventListener('click', goPrev);
    container.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { goNext(); e.preventDefault(); }
      if (e.key === 'ArrowLeft')  { goPrev(); e.preventDefault(); }
    });

    // ---- Touch / swipe ----
    addSwipe(viewport || track, {
      onMove: function (dx) {
        var sw = getSlideWidth();
        var base = currentPage * perPage * (sw + gap);
        var max = Math.max(0, (slides.length - perPage) * (sw + gap));
        track.style.transition = 'none';
        track.style.transform = 'translateX(-' + Math.max(0, Math.min(base - dx, max)) + 'px)';
        container.classList.add('is-dragging');
      },
      onEnd: function (dx) {
        container.classList.remove('is-dragging');
        if (dx < -50) goNext();
        else if (dx > 50) goPrev();
        else update();
      },
    });

    // ---- Autoplay ----
    function startAutoplay() {
      if (!autoplayMs || prefersReduced) return;
      stopAutoplay();
      autoplayTimer = setInterval(goNext, autoplayMs);
    }
    function stopAutoplay() { clearInterval(autoplayTimer); autoplayTimer = null; }
    function resetAutoplay() { stopAutoplay(); startAutoplay(); }

    container.addEventListener('mouseenter', stopAutoplay);
    container.addEventListener('mouseleave', startAutoplay);
    container.addEventListener('focusin', stopAutoplay);
    container.addEventListener('focusout', startAutoplay);

    // ---- Resize ----
    var resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || 20;
        var newPP = getPerPage();
        if (newPP !== perPage) {
          perPage = newPP;
          totalPages = getPages();
          currentPage = Math.min(currentPage, totalPages - 1);
          buildDots();
        }
        update();
      }, 100);
    });

    // ---- Init ----
    container.setAttribute('role', 'region');
    container.setAttribute('aria-roledescription', 'carousel');
    buildDots();
    update();
    startAutoplay();
  }

  // ==================================================================
  // STORIES CAROUSEL — horizontal scroll with snap + paged option
  // ==================================================================
  function initStoriesCarousel(container) {
    var track = container.querySelector('[data-carousel-track]');
    if (!track) return;
    var slides = findSlides(track);
    var prevBtn = container.querySelector('[data-carousel-prev]');
    var nextBtn = container.querySelector('[data-carousel-next]');

    if (slides.length < 1) return;

    // Stories uses native scroll with CSS scroll-snap.
    // Compute scroll amount from the first card's width.
    var scrollAmount = 220;

    function recalcScroll() {
      if (slides[0]) {
        scrollAmount = slides[0].offsetWidth + 16;
      }
    }

    function updateArrowState() {
      if (!prevBtn || !nextBtn) return;
      var atStart = track.scrollLeft <= 5;
      var atEnd = track.scrollLeft >= (track.scrollWidth - track.clientWidth - 5);
      prevBtn.disabled = atStart;
      prevBtn.setAttribute('aria-disabled', atStart ? 'true' : 'false');
      nextBtn.disabled = atEnd;
      nextBtn.setAttribute('aria-disabled', atEnd ? 'true' : 'false');
    }

    if (prevBtn) prevBtn.addEventListener('click', function () {
      track.scrollBy({ left: -scrollAmount, behavior: prefersReduced ? 'auto' : 'smooth' });
    });
    if (nextBtn) nextBtn.addEventListener('click', function () {
      track.scrollBy({ left: scrollAmount, behavior: prefersReduced ? 'auto' : 'smooth' });
    });

    var scrollTimeout;
    track.addEventListener('scroll', function () {
      clearTimeout(scrollTimeout);
      scrollTimeout = setTimeout(updateArrowState, 50);
    }, { passive: true });

    container.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { track.scrollBy({ left: scrollAmount, behavior: 'smooth' }); e.preventDefault(); }
      if (e.key === 'ArrowLeft')  { track.scrollBy({ left: -scrollAmount, behavior: 'smooth' }); e.preventDefault(); }
    });

    if (typeof ResizeObserver !== 'undefined') {
      new ResizeObserver(function () {
        recalcScroll();
        updateArrowState();
      }).observe(track);
    }

    recalcScroll();
    updateArrowState();
  }

  // ==================================================================
  // HELPER: Unwrap Drupal field wrapper div.
  //
  // Drupal renders: track > div.field > [slides]
  // The paged carousel needs: track > [slides]  (direct flex children)
  //
  // This moves slides out of the field wrapper and removes it,
  // so CSS flexbox on the track works correctly.
  // ==================================================================
  function unwrapFieldDiv(track, slides) {
    var fieldWrapper = track.querySelector('.field');
    if (!fieldWrapper) return;
    // Only unwrap if the field wrapper is the direct parent of the slides.
    if (fieldWrapper === slides[0].parentElement) {
      slides.forEach(function (slide) {
        track.appendChild(slide);
      });
      fieldWrapper.remove();
    }
  }

  // ==================================================================
  // HELPER: Unified touch/mouse drag handler
  // ==================================================================
  function addSwipe(el, callbacks) {
    var startX = 0, startY = 0, deltaX = 0, swiping = false;
    var threshold = 50;

    // Touch.
    el.addEventListener('touchstart', function (e) {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      deltaX = 0; swiping = false;
    }, { passive: true });

    el.addEventListener('touchmove', function (e) {
      var dx = e.touches[0].clientX - startX;
      var dy = e.touches[0].clientY - startY;
      if (!swiping && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) swiping = true;
      if (!swiping) return;
      deltaX = dx;
      if (callbacks.onMove) callbacks.onMove(dx);
    }, { passive: true });

    el.addEventListener('touchend', function () {
      if (swiping && callbacks.onEnd) callbacks.onEnd(deltaX);
      swiping = false;
    });

    // Mouse drag.
    var mouseDown = false, mouseStartX = 0, mouseDx = 0;

    el.addEventListener('mousedown', function (e) {
      if (e.button !== 0) return;
      mouseDown = true; mouseStartX = e.clientX; mouseDx = 0;
      e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
      if (!mouseDown) return;
      mouseDx = e.clientX - mouseStartX;
      if (callbacks.onMove) callbacks.onMove(mouseDx);
    });

    document.addEventListener('mouseup', function () {
      if (!mouseDown) return;
      mouseDown = false;
      if (callbacks.onEnd) callbacks.onEnd(mouseDx);
    });
  }

})(Drupal);
