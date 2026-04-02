/**
 * @file
 * Section Carousel.
 *
 * Finds all direct-child blocks inside a .section--carousel section
 * and turns them into a sliding carousel. CSS handles widths.
 */

(function (Drupal) {
  'use strict';

  var BREAKPOINTS = { mobile: 576, tablet: 992 };

  function parseConfig(section) {
    var desktopPerPage = 3;
    var classes = section.className.split(/\s+/);
    for (var i = 0; i < classes.length; i++) {
      var m = classes[i].match(/^carousel-per-page--(\d+)$/);
      if (m) { desktopPerPage = parseInt(m[1], 10); break; }
    }
    return {
      desktopPerPage: desktopPerPage,
      autoplay: section.classList.contains('section--carousel-autoplay') ? 5000 : 0,
      loop: !section.classList.contains('section--carousel-no-loop')
    };
  }

  function getResponsivePerPage(n) {
    var w = window.innerWidth;
    if (w < BREAKPOINTS.mobile) return 1;
    if (w < BREAKPOINTS.tablet) return Math.min(2, n);
    return n;
  }

  function initCarousel(section) {
    console.log('[carousel] Init on:', section);
    console.log('[carousel] Section classes:', section.className);

    // ---- Find the blocks ----
    // Strategy: find the deepest container that holds .block children.
    // Layout Builder nests things differently depending on layout plugin.
    // We look for .layout__region first, then fall back to any container
    // that has .block children.

    var region = null;
    var slides = [];

    // Try 1: .layout__region with direct .block children.
    var regions = section.querySelectorAll('.layout__region');
    console.log('[carousel] Found .layout__region elements:', regions.length);

    for (var r = 0; r < regions.length; r++) {
      var blocks = regions[r].querySelectorAll(':scope > .block');
      console.log('[carousel] Region', r, 'has', blocks.length, 'direct .block children');
      if (blocks.length > 0) {
        region = regions[r];
        slides = Array.from(blocks);
        break;
      }
    }

    // Try 2: If no direct .block children, try all .block descendants of region.
    if (slides.length === 0 && regions.length > 0) {
      for (var r2 = 0; r2 < regions.length; r2++) {
        var allBlocks = regions[r2].querySelectorAll('.block');
        console.log('[carousel] Region', r2, 'has', allBlocks.length, 'total .block descendants');
        if (allBlocks.length > 0) {
          region = regions[r2];
          slides = Array.from(allBlocks);
          break;
        }
      }
    }

    // Try 3: No .layout__region at all — look for .block anywhere in section.
    if (slides.length === 0) {
      var anyBlocks = section.querySelectorAll('.block');
      console.log('[carousel] Fallback: found', anyBlocks.length, '.block elements in section');
      if (anyBlocks.length > 0) {
        // Use the parent of the first block as the region.
        region = anyBlocks[0].parentElement;
        slides = Array.from(region.children);
      }
    }

    // Try 4: Last resort — just use direct children of section.
    if (slides.length === 0) {
      console.log('[carousel] Last resort: using section direct children');
      region = section;
      slides = Array.from(section.children);
    }

    console.log('[carousel] Using region:', region);
    console.log('[carousel] Found', slides.length, 'slides');

    if (slides.length === 0) {
      console.warn('[carousel] No slides found, aborting.');
      return;
    }

    var config = parseConfig(section);
    var perPage = getResponsivePerPage(config.desktopPerPage);
    var currentIndex = 0;
    var autoplayTimer = null;

    console.log('[carousel] Config:', config);
    console.log('[carousel] Per page (responsive):', perPage);

    // ---- Build DOM ----

    var track = document.createElement('div');
    track.className = 'artsci-carousel__track';

    var viewport = document.createElement('div');
    viewport.className = 'artsci-carousel__viewport';

    for (var i = 0; i < slides.length; i++) {
      slides[i].classList.add('artsci-carousel__slide');
      track.appendChild(slides[i]);
    }
    viewport.appendChild(track);

    // Replace region contents with viewport.
    region.innerHTML = '';
    region.appendChild(viewport);

    // ---- Controls ----

    var controls = document.createElement('div');
    controls.className = 'artsci-carousel__controls';

    var prevBtn = document.createElement('button');
    prevBtn.className = 'artsci-carousel__arrow artsci-carousel__arrow--prev';
    prevBtn.setAttribute('type', 'button');
    prevBtn.setAttribute('aria-label', 'Previous');
    prevBtn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>';

    var nextBtn = document.createElement('button');
    nextBtn.className = 'artsci-carousel__arrow artsci-carousel__arrow--next';
    nextBtn.setAttribute('type', 'button');
    nextBtn.setAttribute('aria-label', 'Next');
    nextBtn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>';

    var dotsContainer = document.createElement('div');
    dotsContainer.className = 'artsci-carousel__dots';

    controls.appendChild(prevBtn);
    controls.appendChild(dotsContainer);
    controls.appendChild(nextBtn);
    region.appendChild(controls);

    // ---- Dots ----

    function buildDots() {
      dotsContainer.innerHTML = '';
      var maxIdx = Math.max(0, slides.length - perPage);
      for (var i = 0; i <= maxIdx; i++) {
        var dot = document.createElement('button');
        dot.className = 'artsci-carousel__dot';
        dot.setAttribute('type', 'button');
        dot.setAttribute('aria-label', 'Slide ' + (i + 1));
        dot.dataset.index = i;
        dot.addEventListener('click', function () {
          goTo(parseInt(this.dataset.index, 10));
        });
        dotsContainer.appendChild(dot);
      }
    }

    // ---- Movement ----

    function getSlideStep() {
      if (slides.length < 2) return slides[0] ? slides[0].offsetWidth : 0;
      var rect0 = slides[0].getBoundingClientRect();
      var rect1 = slides[1].getBoundingClientRect();
      var step = rect1.left - rect0.left;
      console.log('[carousel] Slide step:', step, '(slide0 left:', rect0.left, 'slide1 left:', rect1.left, ')');
      return step;
    }

    function goTo(index) {
      var maxIdx = Math.max(0, slides.length - perPage);
      if (config.loop) {
        if (index > maxIdx) index = 0;
        if (index < 0) index = maxIdx;
      } else {
        index = Math.max(0, Math.min(index, maxIdx));
      }

      currentIndex = index;
      var step = getSlideStep();
      track.style.transform = 'translateX(-' + (currentIndex * step) + 'px)';

      var dots = dotsContainer.querySelectorAll('.artsci-carousel__dot');
      for (var i = 0; i < dots.length; i++) {
        dots[i].classList.toggle('artsci-carousel__dot--active', i === currentIndex);
      }

      if (!config.loop) {
        prevBtn.disabled = currentIndex === 0;
        nextBtn.disabled = currentIndex >= maxIdx;
      }
    }

    function next() { goTo(currentIndex + 1); }
    function prev() { goTo(currentIndex - 1); }

    // ---- Events ----

    prevBtn.addEventListener('click', function (e) { e.preventDefault(); prev(); resetAutoplay(); });
    nextBtn.addEventListener('click', function (e) { e.preventDefault(); next(); resetAutoplay(); });

    var touchStartX = 0;
    viewport.addEventListener('touchstart', function (e) {
      touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    viewport.addEventListener('touchend', function (e) {
      var diff = touchStartX - e.changedTouches[0].screenX;
      if (Math.abs(diff) > 50) { diff > 0 ? next() : prev(); resetAutoplay(); }
    }, { passive: true });

    section.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { prev(); resetAutoplay(); }
      if (e.key === 'ArrowRight') { next(); resetAutoplay(); }
    });

    // ---- Autoplay ----

    function startAutoplay() {
      if (config.autoplay > 0) autoplayTimer = setInterval(next, config.autoplay);
    }
    function resetAutoplay() {
      if (autoplayTimer) { clearInterval(autoplayTimer); startAutoplay(); }
    }
    section.addEventListener('mouseenter', function () { if (autoplayTimer) clearInterval(autoplayTimer); });
    section.addEventListener('mouseleave', startAutoplay);

    // ---- Responsive ----

    var resizeTimeout;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(function () {
        var newPP = getResponsivePerPage(config.desktopPerPage);
        if (newPP !== perPage) { perPage = newPP; buildDots(); }
        goTo(Math.min(currentIndex, Math.max(0, slides.length - perPage)));
      }, 150);
    });

    // ---- Init ----

    section.classList.add('artsci-carousel--initialized');
    section.setAttribute('role', 'region');
    section.setAttribute('aria-roledescription', 'carousel');

    if (slides.length <= perPage) {
      section.classList.add('artsci-carousel--no-scroll');
      controls.style.display = 'none';
    }

    buildDots();
    goTo(0);
    startAutoplay();

    console.log('[carousel] ✅ Initialized with', slides.length, 'slides,', perPage, 'per page');
  }

  Drupal.behaviors.sectionCarousel = {
    attach: function (context) {
      // Log what we're looking for.
      var all = context.querySelectorAll ? context.querySelectorAll('.section--carousel') : [];
      var fresh = context.querySelectorAll ? context.querySelectorAll('.section--carousel:not(.artsci-carousel--initialized)') : [];
      console.log('[carousel] Behavior attach. Total .section--carousel:', all.length, 'Uninitialized:', fresh.length);

      for (var i = 0; i < fresh.length; i++) {
        initCarousel(fresh[i]);
      }
    }
  };

})(Drupal);
