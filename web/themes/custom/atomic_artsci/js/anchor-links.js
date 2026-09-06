/**
 * @file
 * Anchor Links block behavior.
 *
 * Handles click-driven active state and smooth scrolling to anchor targets.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.anchorLinks = {
    attach: function (context) {
      const anchorLinks = context.querySelectorAll('.anchor-links__link[data-anchor-link]');

      if (!anchorLinks.length) {
        return;
      }

      anchorLinks.forEach(function (link) {
        // Only attach once.
        if (link.dataset.anchorLinksProcessed) {
          return;
        }
        link.dataset.anchorLinksProcessed = 'true';

        link.addEventListener('click', function (e) {
          const href = this.getAttribute('href');

          // Only handle fragment links.
          if (!href || !href.startsWith('#')) {
            return;
          }

          const targetId = href.substring(1);
          const targetElement = document.getElementById(targetId);

          if (targetElement) {
            e.preventDefault();

            // Update active state on all links in this nav.
            const nav = this.closest('.anchor-links');
            if (nav) {
              nav.querySelectorAll('.anchor-links__link').forEach(function (navLink) {
                navLink.classList.remove('is-active');
              });
            }
            this.classList.add('is-active');

            // Smooth scroll to target.
            const headerOffset = getHeaderOffset();
            const elementPosition = targetElement.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

            window.scrollTo({
              top: offsetPosition,
              behavior: 'smooth'
            });

            // Update URL hash without jumping.
            history.pushState(null, null, href);
          }
        });
      });

      // Set active state based on current hash on page load.
      if (context === document) {
        const currentHash = window.location.hash;
        if (currentHash) {
          const activeLink = document.querySelector('.anchor-links__link[href="' + currentHash + '"]');
          if (activeLink) {
            document.querySelectorAll('.anchor-links__link').forEach(function (link) {
              link.classList.remove('is-active');
            });
            activeLink.classList.add('is-active');
          }
        }
      }
    }
  };

  /**
   * Calculate header offset for scroll position.
   *
   * Adjust this if you have a sticky header.
   *
   * @return {number}
   *   The pixel offset to account for fixed headers.
   */
  function getHeaderOffset() {
    // Check for sticky header and get its height.
    const header = document.querySelector('.site-header, header[role="banner"]');
    if (header) {
      const style = window.getComputedStyle(header);
      if (style.position === 'fixed' || style.position === 'sticky') {
        return header.offsetHeight + 20; // 20px extra padding.
      }
    }
    return 20;
  }

})(Drupal);
