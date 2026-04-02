/**
 * @file
 * Artsci Paged Carousel behavior.
 *
 * Shows N cards at a time with page-based navigation.
 * Responsive breakpoints:
 *   - Desktop (≥981px): 3 visible
 *   - Tablet (601–980px): 2 visible
 *   - Mobile (≤600px): 1 visible
 *
 * Features:
 *   - Arrow navigation with disabled state at boundaries
 *   - Dot pagination (auto-generated)
 *   - Touch/swipe support
 *   - Optional auto-play (pauses on hover/focus)
 *   - Keyboard navigation (ArrowLeft/Right)
 *   - Respects prefers-reduced-motion
 *
 * Activated by: [data-artsci-carousel][data-carousel-size="paged"]
 *
 * Data attributes:
 *   data-carousel-loop="true"     → Infinite loop (default: false)
 *   data-carousel-autoplay="5000" → Auto-advance in ms (default: off)
 *   data-carousel-per-page="3"    → Override desktop visible count
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.artsciPagedCarousel = {
    attach: function (context) {
      var carousels = once('paged-carousel', '[data-artsci-carousel][data-carousel-size="paged"]', context);

      carousels.forEach(function (el) {
        initPagedCarousel(el);
      });
    },
  };

  // ========================================================================
  // BREAKPOINTS — how many slides to show at each width
  // ========================================================================
  var BREAKPOINTS = [
    { max: 600, perPage: 1 },
    { max: 980, perPage: 2 },
    { max: Infinity, perPage: 3 },
  ];

  // ========================================================================
  // INIT
  // ========================================================================
  function initPagedCarousel(container) {
    var viewport = container.querySelector('[data-carousel-viewport]');
    var track = container.querySelector('[data-carousel-track]');
    var slides = Array.from(track.querySelectorAll('[data-carousel-slide]'));
    var prevBtn = container.querySelector('[data-carousel-prev]');
    var nextBtn = container.querySelector('[data-carousel-next]');
    var dotsContainer = container.querySelector('[data-carousel-dots]');

    if (slides.length < 1) return;

    // Options from data attributes.
    var loop = container.dataset.carouselLoop === 'true';
    var autoplayInterval = parseInt(container.dataset.carouselAutoplay, 10) || 0;
    var desktopPerPage = parseInt(container.dataset.carouselPerPage, 10) || 3;

    // Override the desktop breakpoint if custom per-page is set.
    if (desktopPerPage !== 3) {
      BREAKPOINTS[2].perPage = desktopPerPage;
    }

    // State.
    var currentPage = 0;
    var perPage = getPerPage();
    var totalPages = getTotalPages();
    var autoplayTimer = null;
    var isReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Gap from CSS (read computed value).
    var gap = parseFloat(getComputedStyle(track).gap) || 20;

    // ====================================================================
    // HELPERS
    // ====================================================================
    function getPerPage() {
      var w = window.innerWidth;
      for (var i = 0; i < BREAKPOINTS.length; i++) {
        if (w <= BREAKPOINTS[i].max) return BREAKPOINTS[i].perPage;
      }
      return BREAKPOINTS[BREAKPOINTS.length - 1].perPage;
    }

    function getTotalPages() {
      return Math.max(1, Math.ceil(slides.length / perPage));
    }

    function getSlideWidth() {
      // Calculate based on viewport width minus gaps.
      var viewportWidth = viewport.offsetWidth;
      return (viewportWidth - gap * (perPage - 1)) / perPage;
    }

    // ====================================================================
    // RENDER
    // ====================================================================
    function update() {
      var slideWidth = getSlideWidth();

      // Set each slide's flex-basis.
      slides.forEach(function (slide) {
        slide.style.flex = '0 0 ' + slideWidth + 'px';
      });

      // Calculate translate offset.
      var offset = currentPage * (slideWidth + gap) * perPage;

      // Clamp: don't scroll past the last page.
      var maxOffset = (slides.length - perPage) * (slideWidth + gap);
      if (maxOffset < 0) maxOffset = 0;
      offset = Math.min(offset, maxOffset);

      if (isReduced) {
        track.style.transition = 'none';
      } else {
        track.style.transition = 'transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
      }

      track.style.transform = 'translateX(-' + offset + 'px)';

      // Update aria attributes on slides.
      var startVisible = currentPage * perPage;
      var endVisible = Math.min(startVisible + perPage, slides.length);

      slides.forEach(function (slide, i) {
        var isVisible = i >= startVisible && i < endVisible;
        slide.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
        // Allow focus only on visible slides.
        var focusables = slide.querySelectorAll('a, button, input, [tabindex]');
        focusables.forEach(function (el) {
          el.setAttribute('tabindex', isVisible ? '0' : '-1');
        });
      });

      // Update arrow states.
      updateArrows();

      // Update dots.
      updateDots();
    }

    function updateArrows() {
      if (!prevBtn || !nextBtn) return;

      if (loop) {
        prevBtn.disabled = false;
        nextBtn.disabled = false;
        prevBtn.setAttribute('aria-disabled', 'false');
        nextBtn.setAttribute('aria-disabled', 'false');
        return;
      }

      var atFirst = currentPage <= 0;
      var atLast = currentPage >= totalPages - 1;

      prevBtn.disabled = atFirst;
      prevBtn.setAttribute('aria-disabled', atFirst ? 'true' : 'false');

      nextBtn.disabled = atLast;
      nextBtn.setAttribute('aria-disabled', atLast ? 'true' : 'false');
    }

    // ====================================================================
    // DOTS
    // ====================================================================
    function buildDots() {
      if (!dotsContainer) return;
      dotsContainer.innerHTML = '';

      for (var i = 0; i < totalPages; i++) {
        var dot = document.createElement('button');
        dot.className = 'artsci-carousel__dot' + (i === 0 ? ' is-active' : '');
        dot.setAttribute('aria-label', 'Go to page ' + (i + 1) + ' of ' + totalPages);
        dot.dataset.page = i;
        dot.addEventListener('click', function () {
          goToPage(parseInt(this.dataset.page, 10));
        });
        dotsContainer.appendChild(dot);
      }
    }

    function updateDots() {
      if (!dotsContainer) return;
      var dots = dotsContainer.querySelectorAll('.artsci-carousel__dot');
      dots.forEach(function (dot, i) {
        dot.classList.toggle('is-active', i === currentPage);
        dot.setAttribute('aria-current', i === currentPage ? 'step' : 'false');
      });
    }

    // ====================================================================
    // NAVIGATION
    // ====================================================================
    function goToPage(page) {
      if (loop) {
        currentPage = ((page % totalPages) + totalPages) % totalPages;
      } else {
        currentPage = Math.max(0, Math.min(page, totalPages - 1));
      }
      update();
      resetAutoplay();
    }

    function goNext() {
      goToPage(currentPage + 1);
    }

    function goPrev() {
      goToPage(currentPage - 1);
    }

    // ====================================================================
    // TOUCH / SWIPE
    // ====================================================================
    var touchStartX = 0;
    var touchStartY = 0;
    var touchDeltaX = 0;
    var isSwiping = false;
    var swipeThreshold = 50;

    viewport.addEventListener('touchstart', function (e) {
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
      touchDeltaX = 0;
      isSwiping = false;
      track.style.transition = 'none';
    }, { passive: true });

    viewport.addEventListener('touchmove', function (e) {
      var dx = e.touches[0].clientX - touchStartX;
      var dy = e.touches[0].clientY - touchStartY;

      // Only capture horizontal swipes.
      if (!isSwiping && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) {
        isSwiping = true;
      }

      if (!isSwiping) return;

      touchDeltaX = dx;

      // Apply drag offset.
      var slideWidth = getSlideWidth();
      var baseOffset = currentPage * (slideWidth + gap) * perPage;
      var maxOffset = (slides.length - perPage) * (slideWidth + gap);
      var dragOffset = Math.max(0, Math.min(baseOffset - touchDeltaX, maxOffset));

      track.style.transform = 'translateX(-' + dragOffset + 'px)';
      container.classList.add('is-dragging');
    }, { passive: true });

    viewport.addEventListener('touchend', function () {
      container.classList.remove('is-dragging');

      if (isSwiping) {
        if (touchDeltaX < -swipeThreshold) {
          goNext();
        } else if (touchDeltaX > swipeThreshold) {
          goPrev();
        } else {
          update(); // Snap back.
        }
      }

      isSwiping = false;
    });

    // ====================================================================
    // MOUSE DRAG (desktop)
    // ====================================================================
    var mouseDown = false;
    var mouseStartX = 0;
    var mouseDeltaX = 0;

    viewport.addEventListener('mousedown', function (e) {
      if (e.button !== 0) return; // Left click only.
      mouseDown = true;
      mouseStartX = e.clientX;
      mouseDeltaX = 0;
      track.style.transition = 'none';
      e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
      if (!mouseDown) return;
      mouseDeltaX = e.clientX - mouseStartX;

      var slideWidth = getSlideWidth();
      var baseOffset = currentPage * (slideWidth + gap) * perPage;
      var maxOffset = (slides.length - perPage) * (slideWidth + gap);
      var dragOffset = Math.max(0, Math.min(baseOffset - mouseDeltaX, maxOffset));

      track.style.transform = 'translateX(-' + dragOffset + 'px)';
      container.classList.add('is-dragging');
    });

    document.addEventListener('mouseup', function () {
      if (!mouseDown) return;
      mouseDown = false;
      container.classList.remove('is-dragging');

      if (Math.abs(mouseDeltaX) > swipeThreshold) {
        if (mouseDeltaX < -swipeThreshold) goNext();
        else goPrev();
      } else {
        update();
      }
    });

    // ====================================================================
    // AUTO-PLAY
    // ====================================================================
    function startAutoplay() {
      if (!autoplayInterval || isReduced) return;
      stopAutoplay();
      autoplayTimer = setInterval(goNext, autoplayInterval);
    }

    function stopAutoplay() {
      if (autoplayTimer) {
        clearInterval(autoplayTimer);
        autoplayTimer = null;
      }
    }

    function resetAutoplay() {
      stopAutoplay();
      startAutoplay();
    }

    // Pause on hover/focus.
    container.addEventListener('mouseenter', stopAutoplay);
    container.addEventListener('mouseleave', startAutoplay);
    container.addEventListener('focusin', stopAutoplay);
    container.addEventListener('focusout', startAutoplay);

    // ====================================================================
    // KEYBOARD
    // ====================================================================
    container.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { goNext(); e.preventDefault(); }
      if (e.key === 'ArrowLeft') { goPrev(); e.preventDefault(); }
    });

    // ====================================================================
    // RESIZE HANDLER
    // ====================================================================
    var resizeTimeout;
    function onResize() {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(function () {
        var newPerPage = getPerPage();
        gap = parseFloat(getComputedStyle(track).gap) || 20;

        if (newPerPage !== perPage) {
          perPage = newPerPage;
          totalPages = getTotalPages();
          currentPage = Math.min(currentPage, totalPages - 1);
          buildDots();
        }

        update();
      }, 100);
    }

    window.addEventListener('resize', onResize);

    // ====================================================================
    // BUTTON LISTENERS
    // ====================================================================
    if (nextBtn) nextBtn.addEventListener('click', goNext);
    if (prevBtn) prevBtn.addEventListener('click', goPrev);

    // ====================================================================
    // INITIALIZE
    // ====================================================================
    buildDots();
    update();
    startAutoplay();

    // Set ARIA role on the carousel region.
    container.setAttribute('role', 'region');
    container.setAttribute('aria-roledescription', 'carousel');

    if (viewport) {
      viewport.setAttribute('aria-live', 'polite');
    }
  }

})(Drupal);
