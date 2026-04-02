/**
 * @file
 * Artsci Stories Carousel behavior.
 *
 * Handles the horizontal scrolling portrait card carousel
 * identified by [data-stories-carousel].
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.artsciStoriesCarousel = {
    attach: function (context) {
      const carousels = once('stories-carousel', '[data-stories-carousel]', context);

      carousels.forEach(function (el) {
        initStoriesCarousel(el);
      });
    },
  };

  function initStoriesCarousel(container) {
    const track = container.querySelector('[data-stories-track]');
    const prevBtn = container.querySelector('[data-stories-prev]');
    const nextBtn = container.querySelector('[data-stories-next]');

    if (!track) return;

    // How far to scroll per click (roughly one card width + gap).
    var scrollAmount = 220;

    function updateArrowState() {
      if (!prevBtn || !nextBtn) return;

      var atStart = track.scrollLeft <= 5;
      var atEnd = track.scrollLeft >= (track.scrollWidth - track.clientWidth - 5);

      prevBtn.disabled = atStart;
      prevBtn.setAttribute('aria-disabled', atStart ? 'true' : 'false');

      nextBtn.disabled = atEnd;
      nextBtn.setAttribute('aria-disabled', atEnd ? 'true' : 'false');
    }

    function scrollPrev() {
      track.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
    }

    function scrollNext() {
      track.scrollBy({ left: scrollAmount, behavior: 'smooth' });
    }

    // Button listeners.
    if (prevBtn) prevBtn.addEventListener('click', scrollPrev);
    if (nextBtn) nextBtn.addEventListener('click', scrollNext);

    // Update arrow state on scroll.
    var scrollTimeout;
    track.addEventListener('scroll', function () {
      clearTimeout(scrollTimeout);
      scrollTimeout = setTimeout(updateArrowState, 50);
    }, { passive: true });

    // Keyboard navigation when track or container is focused.
    container.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { scrollNext(); e.preventDefault(); }
      if (e.key === 'ArrowLeft') { scrollPrev(); e.preventDefault(); }
    });

    // Recalculate on resize.
    var resizeObserver;
    if (typeof ResizeObserver !== 'undefined') {
      resizeObserver = new ResizeObserver(function () {
        // Recalculate scroll amount based on first card width.
        var firstCard = track.querySelector('.featured-item--portrait');
        if (firstCard) {
          scrollAmount = firstCard.offsetWidth + 16; // card width + gap
        }
        updateArrowState();
      });
      resizeObserver.observe(track);
    }

    // Initialize.
    updateArrowState();
  }

})(Drupal);
