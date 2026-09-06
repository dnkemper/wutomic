/**
 * @file
 * Unified Artsci Carousel behavior.
 *
 * Handles ALL carousel modes identified by [data-artsci-carousel]:
 *   - large: 3-card fan with center focus
 *   - mini:  Single slide visible at a time
 *   - paged: N cards per page with dots (responsive: 3→2→1)
 *   - stats: 4-card paged variant (shares paged behavior, distinct styling)
 *
 * Slide discovery:
 *   Drupal renders paragraph entities inside a field wrapper div.
 *   This JS finds slides via [data-carousel-slide] first, then
 *   falls back to direct children of the track's first child element
 *   (the field wrapper). This means paragraph templates MUST add
 *   data-carousel-slide to their root element.
 *
 * Data attributes on the container ([data-artsci-carousel]):
 *   data-carousel-size="large|mini|paged|stats"
 *   data-carousel-loop="true"       → Wrap navigation (default: false)
 *   data-carousel-autoplay="5000"   → Auto-advance ms (default: off)
 *   data-carousel-per-page="3"      → Visible count, may be fractional (e.g. 2.5)
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
        var mode = el.dataset.carouselSize || 'three';
        switch (mode) {
          case 'three':  initThreeCarousel(el);   break;
          case 'four':   initFourCarousel(el);   break;
          case 'one':    initOneCarousel(el);    break;
          case 'two':    initTwoCarousel(el);    break;
          default:        initThreeCarousel(el);   break;
        }
        // Track keyboard focus across all modes (adds .is-focused).
        trackFocusedSlide(el);
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
  // SHARED: Focus drives the active slide.
  //
  // When a focusable element inside a slide receives keyboard focus, make
  // that slide the single active slide — paginating it into view if needed —
  // so the active treatment (e.g. the teal panel) follows focus. When focus
  // moves to the nav controls or leaves the carousel, revert to the normal
  // page-leading active slide. Delegates on the container and drives the
  // per-mode hooks the paged inits expose (carouselFocusSlide / BlurSlide);
  // modes without those hooks fall back to toggling .is-active directly.
  // ==================================================================
  function trackFocusedSlide(container) {
    var track = container.querySelector('[data-carousel-track]');
    if (!track) return;
    var slides = findSlides(track);
    if (!slides.length) return;

    function setFocused(slide) {
      var index = slide ? slides.indexOf(slide) : -1;
      if (index !== -1 && typeof container.carouselFocusSlide === 'function') {
        container.carouselFocusSlide(index);
      } else if (typeof container.carouselBlurSlide === 'function') {
        container.carouselBlurSlide();
      } else {
        // Fan / single modes: no pagination hook, just flag the slide.
        slides.forEach(function (s) {
          s.classList.toggle('is-focused', s === slide);
        });
      }
    }

    container.addEventListener('focusin', function (e) {
      setFocused(e.target.closest('[data-carousel-slide]'));
    });

    container.addEventListener('focusout', function (e) {
      // Revert when focus leaves the carousel entirely.
      if (!container.contains(e.relatedTarget)) {
        setFocused(null);
      }
    });
  }

  // ==================================================================
  // SHARED: Reduced motion preference
  // ==================================================================
  var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ==================================================================
  // LARGE CAROUSEL — 3-card fan with center focus
  // ==================================================================
  function initThreeCarousel(container) {
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
      // Hero fan geometry. All three slides share one box size; the center
      // renders full-scale on top, the side cards are scaled down and shifted
      // outward so they peek from behind the center. Tunables:
      //   widthRatio (0.55) - center width as a fraction of the track
      //   maxWidth (640)    - cap so the card isn't huge on wide screens
      //   heightRatio       - now read from the media format and size below
      //   sideOffset (0.56) - side-card shift from center, fraction of width
      //   sideScale (0.82)  - scale applied to the side cards
      //
      // The slide box is sized inline here, so CSS cannot reach it. These
      // three maps mirror $shape-width/$shape-height, $size-scale and
      // $size-nudge in slider-carousel.scss and have to be kept in step with
      // them by hand.
      var shapeRatio = {
        portrait: 3 / 2,
        square: 1,
        landscape: 3 / 4,
        widescreen: 9 / 16,
      };
      var sizeScale = { small: 0.65, medium: 0.82, large: 1 };
      var sizeNudge = { small: 0.9, medium: 1, large: 1.15 };

      var media = slides.length ? slides[0].querySelector('.media') : null;
      var heightRatio = 0.7;
      var widthScale = 1;
      if (media) {
        Object.keys(shapeRatio).forEach(function (key) {
          if (media.classList.contains('media--' + key)) {
            heightRatio = shapeRatio[key];
          }
        });
        Object.keys(sizeScale).forEach(function (key) {
          if (media.classList.contains('media--' + key)) {
            widthScale = sizeScale[key];
            heightRatio *= sizeNudge[key];
          }
        });
      }

      var centerWidth = Math.max(
        200,
        Math.min(640, trackWidth * 0.55) * widthScale
      );
      var cardHeight = Math.round(centerWidth * heightRatio);
      var sideOffset = centerWidth * 0.56;
      var sideScale = 0.82;
      var leftPos = trackWidth / 2 - centerWidth / 2;

      // Size the track to the full-scale center card.
      track.style.height = cardHeight + 'px';

      slides.forEach(function (slide, i) {
        var pos = 'hidden';
        if (i === positions.left)   pos = 'left';
        if (i === positions.center) pos = 'center';
        if (i === positions.right)  pos = 'right';

        slide.setAttribute('data-slide-position', pos);
        slide.setAttribute('aria-hidden', pos === 'hidden' ? 'true' : 'false');
        slide.style.position = 'absolute';
        slide.style.top = '50%';
        slide.style.right = 'auto';
        slide.style.marginLeft = '0';
        slide.style.width = centerWidth + 'px';
        slide.style.height = cardHeight + 'px';
        slide.style.transition = prefersReduced ? 'none' : 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';

        switch (pos) {
          case 'left':
            slide.style.left = (leftPos - sideOffset) + 'px';
            slide.style.transform = 'translateY(-50%) scale(' + sideScale + ')';
            slide.style.opacity = '0.7';
            slide.style.zIndex = '1';
            slide.style.pointerEvents = 'auto';
            break;
          case 'center':
            slide.style.left = leftPos + 'px';
            slide.style.transform = 'translateY(-50%) scale(1)';
            slide.style.opacity = '1';
            slide.style.zIndex = '3';
            slide.style.pointerEvents = 'auto';
            break;
          case 'right':
            slide.style.left = (leftPos + sideOffset) + 'px';
            slide.style.transform = 'translateY(-50%) scale(' + sideScale + ')';
            slide.style.opacity = '0.7';
            slide.style.zIndex = '1';
            slide.style.pointerEvents = 'auto';
            break;
          default:
            // Parked behind the center card, faded out.
            slide.style.left = leftPos + 'px';
            slide.style.transform = 'translateY(-50%) scale(' + sideScale + ')';
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
  function initOneCarousel(container) {
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
  function initFourCarousel(container) {
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
    // Visible count may be fractional (e.g. 2.5 for the peek-style 'paged').
    var desktopCount = parseFloat(container.dataset.carouselPerPage) || 3;
    var autoplayTimer = null;

    // State.
    var currentPage = 0;
    // When focus has driven a specific slide active, its index lives here and
    // overrides the page-leading default; cleared on blur. See
    // trackFocusedSlide().
    var focusedIndex = null;
    // perPage drives layout width math and may be fractional.
    var perPage = getPerPage();
    // step drives pagination math and is always a whole-slide advance.
    var step = getStep(perPage);
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

    // Pagination always advances by whole slides. For a 2.5 perPage this
    // means step=2 — the half-peek slide on page N becomes the first
    // fully-visible slide on page N+1.
    function getStep(pp) {
      return Math.max(1, Math.floor(pp));
    }

    function getPages() {
      return Math.max(1, Math.ceil(slides.length / step));
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

      var offset = currentPage * step * (sw + gap);
      var maxOffset = Math.max(0, (slides.length - perPage) * (sw + gap));
      offset = Math.min(offset, maxOffset);

      track.style.transition = prefersReduced ? 'none' : 'transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
      track.style.transform = 'translateX(-' + offset + 'px)';

      // Aria. Use Math.ceil so the half-peek slide is still considered
      // visible for screen readers / focusability.
      var start = currentPage * step;
      var end = Math.min(start + Math.ceil(perPage), slides.length);
      slides.forEach(function (slide, i) {
        var vis = i >= start && i < end;
        slide.setAttribute('aria-hidden', vis ? 'false' : 'true');
        // Flag the leading (first fully-visible) slide of the current page as
        // active so styling can target it — e.g. the teal text panel on the
        // paged/2.5 style. The active slide follows pagination automatically.
        slide.classList.toggle('is-focused', i === (focusedIndex !== null ? focusedIndex : start));
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
        dot.classList.toggle('is-focused', i === currentPage);
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
        var base = currentPage * step * (sw + gap);
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
          step = getStep(perPage);
          totalPages = getPages();
          currentPage = Math.min(currentPage, totalPages - 1);
          buildDots();
        }
        update();
      }, 100);
    });

    // ---- Focus-driven active state ----
    // Focusing a slide makes it the single .is-focused slide and paginates it
    // into view; blur reverts to the page-leading slide. Driven by
    // trackFocusedSlide().
    container.carouselFocusSlide = function (index) {
      focusedIndex = index;
      var page = Math.min(Math.floor(index / step), totalPages - 1);
      if (page !== currentPage) {
        goToPage(page);
      } else {
        update();
      }
    };
    container.carouselBlurSlide = function () {
      if (focusedIndex === null) return;
      focusedIndex = null;
      update();
    };

    // ---- Init ----
    container.setAttribute('role', 'region');
    container.setAttribute('aria-roledescription', 'carousel');
    buildDots();
    update();
    startAutoplay();
  }

  // ==================================================================
  // PAGED CAROUSEL — N cards visible, page-based, dots
  // ==================================================================
  function initTwoCarousel(container) {
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
    // Visible count may be fractional (e.g. 2.5 for the peek-style 'paged').
    var desktopCount = parseFloat(container.dataset.carouselPerPage) || 3;
    var autoplayTimer = null;

    // State.
    var currentPage = 0;
    // When focus has driven a specific slide active, its index lives here and
    // overrides the page-leading default; cleared on blur. See
    // trackFocusedSlide().
    var focusedIndex = null;
    // perPage drives layout width math and may be fractional.
    var perPage = getPerPage();
    // step drives pagination math and is always a whole-slide advance.
    var step = getStep(perPage);
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

    // Pagination always advances by whole slides. For a 2.5 perPage this
    // means step=2 — the half-peek slide on page N becomes the first
    // fully-visible slide on page N+1.
    function getStep(pp) {
      return Math.max(1, Math.floor(pp));
    }

    function getPages() {
      return Math.max(1, Math.ceil(slides.length / step));
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

      var offset = currentPage * step * (sw + gap);
      var maxOffset = Math.max(0, (slides.length - perPage) * (sw + gap));
      offset = Math.min(offset, maxOffset);

      track.style.transition = prefersReduced ? 'none' : 'transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
      track.style.transform = 'translateX(-' + offset + 'px)';

      // Aria. Use Math.ceil so the half-peek slide is still considered
      // visible for screen readers / focusability.
      var start = currentPage * step;
      var end = Math.min(start + Math.ceil(perPage), slides.length);
      slides.forEach(function (slide, i) {
        var vis = i >= start && i < end;
        slide.setAttribute('aria-hidden', vis ? 'false' : 'true');
        // Flag the leading (first fully-visible) slide of the current page as
        // active so styling can target it — e.g. the teal text panel on the
        // paged/2.5 style. The active slide follows pagination automatically.
        slide.classList.toggle('is-focused', i === (focusedIndex !== null ? focusedIndex : start));
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
        dot.classList.toggle('is-focused', i === currentPage);
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
        var base = currentPage * step * (sw + gap);
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
          step = getStep(perPage);
          totalPages = getPages();
          currentPage = Math.min(currentPage, totalPages - 1);
          buildDots();
        }
        update();
      }, 100);
    });

    // ---- Focus-driven active state ----
    // Focusing a slide makes it the single .is-focused slide and paginates it
    // into view; blur reverts to the page-leading slide. Driven by
    // trackFocusedSlide().
    container.carouselFocusSlide = function (index) {
      focusedIndex = index;
      var page = Math.min(Math.floor(index / step), totalPages - 1);
      if (page !== currentPage) {
        goToPage(page);
      } else {
        update();
      }
    };
    container.carouselBlurSlide = function () {
      if (focusedIndex === null) return;
      focusedIndex = null;
      update();
    };

    // ---- Init ----
    container.setAttribute('role', 'region');
    container.setAttribute('aria-roledescription', 'carousel');
    buildDots();
    update();
    startAutoplay();
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
