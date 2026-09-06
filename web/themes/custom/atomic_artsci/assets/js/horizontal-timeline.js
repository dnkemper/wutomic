/**
 * @file
 * Horizontal timeline component.
 *
 * Built on the same accessible-tabs pattern used by tabs.js: tablist of dots
 * along a horizontal rail, one panel visible at a time, keyboard nav
 * (left/right/home/end), URL-hash deep-linking, and prev/next arrow buttons.
 * The rail's red progress fill is driven by a CSS custom property that this
 * script updates as the active dot changes.
 */
(function (Drupal, once) {
  'use strict';

  var KEYS = {
    end: 35,
    home: 36,
    left: 37,
    right: 39
  };

  /**
   * Constructor.
   *
   * @param {HTMLElement} element
   *   The .h-timeline container.
   */
  function HTimeline(element) {
    if (!element) {
      return;
    }
    this.element = element;
    this.rail = element.querySelector('.h-timeline__rail');
    this.dots = Array.prototype.slice.call(element.querySelectorAll('.h-timeline__dot'));
    this.panels = Array.prototype.slice.call(element.querySelectorAll('.h-timeline__panel'));
    this.prev = element.querySelector('.h-timeline__arrow--prev');
    this.next = element.querySelector('.h-timeline__arrow--next');

    if (!this.rail || !this.dots.length || !this.panels.length) {
      return;
    }

    this.activeIndex = 0;
    this.init();
  }

  HTimeline.prototype.init = function () {
    var self = this;

    this.dots.forEach(function (dot, i) {
      dot.dataset.index = String(i);
    });
    this.panels.forEach(function (panel, i) {
      if (i !== 0) {
        panel.hidden = true;
      }
    });

    this.activateFromHash();
    this.bindEvents();
    this.update();

    // Recompute progress on resize so the rail fill stays in sync.
    window.addEventListener('resize', function () {
      self.updateProgress();
    });
  };

  HTimeline.prototype.bindEvents = function () {
    var self = this;

    this.dots.forEach(function (dot) {
      dot.addEventListener('click', function (event) {
        event.preventDefault();
        self.activate(parseInt(dot.dataset.index, 10), true);
      });
      dot.addEventListener('keydown', function (event) {
        self.onKeyDown(event);
      });
    });

    if (this.prev) {
      this.prev.addEventListener('click', function () {
        self.step(-1);
      });
    }
    if (this.next) {
      this.next.addEventListener('click', function () {
        self.step(1);
      });
    }

    window.addEventListener('popstate', function () {
      self.activateFromHash();
    });
  };

  HTimeline.prototype.step = function (delta) {
    var target = this.activeIndex + delta;
    if (target < 0 || target >= this.dots.length) {
      return;
    }
    this.activate(target, true);
  };

  HTimeline.prototype.onKeyDown = function (event) {
    var idx = this.activeIndex;
    var max = this.dots.length - 1;
    var key = event.keyCode;

    if (key === KEYS.left) {
      event.preventDefault();
      idx = idx === 0 ? max : idx - 1;
    }
    else if (key === KEYS.right) {
      event.preventDefault();
      idx = idx === max ? 0 : idx + 1;
    }
    else if (key === KEYS.home) {
      event.preventDefault();
      idx = 0;
    }
    else if (key === KEYS.end) {
      event.preventDefault();
      idx = max;
    }
    else {
      return;
    }
    this.activate(idx, true);
  };

  HTimeline.prototype.activate = function (index, setFocus) {
    if (index < 0 || index >= this.dots.length) {
      return;
    }
    this.activeIndex = index;

    this.dots.forEach(function (dot, i) {
      var active = i === index;
      var visited = i < index;
      dot.setAttribute('aria-selected', active ? 'true' : 'false');
      if (active) {
        dot.removeAttribute('tabindex');
      }
      else {
        dot.setAttribute('tabindex', '-1');
      }
      dot.classList.toggle('is-active', active);
      dot.classList.toggle('is-visited', visited);
    });

    this.panels.forEach(function (panel, i) {
      panel.hidden = i !== index;
    });

    this.update();

    var dotId = this.dots[index].id;
    if (dotId && window.history && window.history.replaceState) {
      window.history.replaceState('', '', '#' + dotId);
    }

    if (setFocus) {
      this.dots[index].focus();
    }
  };

  HTimeline.prototype.update = function () {
    this.rail.dataset.activeIndex = String(this.activeIndex);
    this.updateProgress();
    if (this.prev) {
      this.prev.disabled = this.activeIndex === 0;
    }
    if (this.next) {
      this.next.disabled = this.activeIndex === this.dots.length - 1;
    }
  };

  HTimeline.prototype.updateProgress = function () {
    var total = this.dots.length;
    if (total < 2) {
      this.rail.style.setProperty('--h-timeline-progress', '0%');
      return;
    }
    var pct = (this.activeIndex / (total - 1)) * 100;
    this.rail.style.setProperty('--h-timeline-progress', pct + '%');
  };

  HTimeline.prototype.activateFromHash = function () {
    var hash = window.location.hash.substring(1);
    if (!hash) {
      return;
    }
    var match = -1;
    for (var i = 0; i < this.dots.length; i++) {
      if (this.dots[i].id === hash) {
        match = i;
        break;
      }
    }
    if (match >= 0) {
      this.activate(match, false);
    }
  };

  Drupal.behaviors.atomicArtsciHorizontalTimeline = {
    attach: function (context) {
      var elements = once('h-timeline', '.h-timeline', context);
      elements.forEach(function (element) {
        new HTimeline(element);
      });
    }
  };

  // Expose for debugging.
  window.AtomicArtsciHorizontalTimeline = HTimeline;
})(Drupal, once);
