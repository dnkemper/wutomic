/**
 * @file
 * Accessible tabs component using Drupal behaviors.
 *
 * This content is licensed according to the W3C Software License at
 * https://www.w3.org/Consortium/Legal/2015/copyright-software-and-document
 * This software or document includes material copied from or derived from
 * https://www.w3.org/TR/wai-aria-practices-1.1/examples/tabs/tabs-1/tabs.html
 */

(function (Drupal, once) {
  'use strict';

  // Key codes for keyboard navigation.
  const keys = {
    end: 35,
    home: 36,
    left: 37,
    up: 38,
    right: 39,
    down: 40,
    delete: 46
  };

  // Direction mapping for arrow keys.
  const direction = {
    37: -1, // left
    38: -1, // up
    39: 1,  // right
    40: 1   // down
  };

  /**
   * Tabs constructor.
   *
   * @param {HTMLElement} element
   *   The tabs container element.
   */
  function Tabs(element) {
    if (!element) {
      return;
    }

    this.element = element;
    this.tablist = element.querySelector('[role="tablist"]');
    this.tabs = element.querySelectorAll('[role="tab"]');
    this.panels = element.querySelectorAll('[role="tabpanel"]');

    // Exit if required elements are missing.
    if (!this.tablist || !this.tabs.length || !this.panels.length) {
      return;
    }

    // Warn if container lacks an ID.
    if (!element.hasAttribute('id')) {
      console.warn('[atomic_artsci] Tabs container needs a unique ID to function correctly.');
    }

    this.init();
  }

  /**
   * Initialize the tabs component.
   */
  Tabs.prototype.init = function () {
    // Hide all panels except the first (or the one matching URL hash).
    for (let i = 0; i < this.panels.length; i++) {
      if (i !== 0) {
        this.panels[i].hidden = true;
      }
    }

    // Set index on each tab for keyboard navigation.
    for (let i = 0; i < this.tabs.length; i++) {
      this.tabs[i].index = i;
    }

    // Activate tab from URL hash if present.
    this.activateTabByHash();

    // Bind event listeners.
    this.addListeners();
  };

  /**
   * Add event listeners to tabs.
   */
  Tabs.prototype.addListeners = function () {
    const self = this;

    for (let i = 0; i < this.tabs.length; i++) {
      this.tabs[i].addEventListener('click', function (event) {
        self.clickEventListener(event);
      });

      this.tabs[i].addEventListener('keydown', function (event) {
        self.keydownEventListener(event);
      });

      this.tabs[i].addEventListener('keyup', function (event) {
        self.keyupEventListener(event);
      });
    }

    // Listen for URL hash changes.
    window.addEventListener('popstate', function () {
      self.activateTabByHash();
    });
  };

  /**
   * Handle click on tab.
   */
  Tabs.prototype.clickEventListener = function (event) {
    const tab = event.target.closest('[role="tab"]');
    if (tab) {
      this.activateTab(tab, true);
    }
  };

  /**
   * Activate a tab and show its panel.
   *
   * @param {HTMLElement} tab
   *   The tab button to activate.
   * @param {boolean} setFocus
   *   Whether to set focus on the tab.
   */
  Tabs.prototype.activateTab = function (tab, setFocus) {
    // Deactivate all tabs first.
    this.deactivateTabs();

    // Activate the selected tab.
    tab.removeAttribute('tabindex');
    tab.setAttribute('aria-selected', 'true');

    // Show the associated panel.
    const controls = tab.getAttribute('aria-controls');
    const panel = document.getElementById(controls);
    if (panel) {
      panel.removeAttribute('hidden');
    }

    // Update URL hash for deep linking.
    const tabId = tab.id;
    if (tabId && window.history && history.replaceState) {
      history.replaceState('', '', '#' + tabId);
    }

    // Set focus if requested.
    if (setFocus) {
      tab.focus();
    }
  };

  /**
   * Activate tab based on URL hash.
   */
  Tabs.prototype.activateTabByHash = function () {
    const hash = window.location.hash.substring(1);

    if (!hash) {
      return;
    }

    const tabToFocus = document.getElementById(hash);

    if (!tabToFocus || tabToFocus.getAttribute('role') !== 'tab') {
      return;
    }

    // Verify the tab belongs to this tablist.
    const tabParent = tabToFocus.closest('.tabs-collection');
    if (tabParent === this.element) {
      this.activateTab(tabToFocus, false);
    }
  };

  /**
   * Deactivate all tabs and hide all panels.
   */
  Tabs.prototype.deactivateTabs = function () {
    for (let i = 0; i < this.tabs.length; i++) {
      this.tabs[i].setAttribute('tabindex', '-1');
      this.tabs[i].setAttribute('aria-selected', 'false');
    }

    for (let i = 0; i < this.panels.length; i++) {
      this.panels[i].hidden = true;
    }
  };

  /**
   * Handle keydown events for keyboard navigation.
   */
  Tabs.prototype.keydownEventListener = function (event) {
    const key = event.keyCode;

    switch (key) {
      case keys.end:
        event.preventDefault();
        this.activateTab(this.tabs[this.tabs.length - 1], true);
        break;

      case keys.home:
        event.preventDefault();
        this.activateTab(this.tabs[0], true);
        break;

      case keys.up:
      case keys.down:
        this.determineOrientation(event);
        break;
    }
  };

  /**
   * Handle keyup events for arrow key navigation.
   */
  Tabs.prototype.keyupEventListener = function (event) {
    const key = event.keyCode;

    switch (key) {
      case keys.left:
      case keys.right:
        this.determineOrientation(event);
        break;
    }
  };

  /**
   * Determine orientation and navigate accordingly.
   */
  Tabs.prototype.determineOrientation = function (event) {
    const key = event.keyCode;
    const vertical = this.tablist.getAttribute('aria-orientation') === 'vertical';
    let proceed = false;

    if (vertical) {
      if (key === keys.up || key === keys.down) {
        event.preventDefault();
        proceed = true;
      }
    }
    else {
      if (key === keys.left || key === keys.right) {
        proceed = true;
      }
    }

    if (proceed) {
      this.switchTabOnArrowPress(event);
    }
  };

  /**
   * Switch to next/previous tab on arrow key press.
   */
  Tabs.prototype.switchTabOnArrowPress = function (event) {
    const pressed = event.keyCode;
    const target = event.target;

    if (typeof target.index === 'undefined') {
      return;
    }

    // Calculate next index.
    let nextIndex = target.index + direction[pressed];

    // Wrap around.
    if (nextIndex < 0) {
      nextIndex = this.tabs.length - 1;
    }
    else if (nextIndex >= this.tabs.length) {
      nextIndex = 0;
    }

    this.tabs[nextIndex].focus();
    this.activateTab(this.tabs[nextIndex], false);
  };

  /**
   * Drupal behavior for tabs.
   */
  Drupal.behaviors.atomicArtsciTabs = {
    attach: function (context) {
      const elements = once('atomic-artsci-tabs', '.tabs-collection', context);

      elements.forEach(function (element) {
        new Tabs(element);
      });
    }
  };

  // Also expose globally for debugging.
  window.AtomicArtsciTabs = Tabs;

})(Drupal, once);
