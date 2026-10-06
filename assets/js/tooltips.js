/**
 * Shared portal tooltips — replaces native black title tooltips
 */
(function (window, document) {
  'use strict';

  var SELECTOR = '[title], [data-original-title]';

  function hideTooltip(element) {
    if (element && element._portalTooltip) {
      element._portalTooltip.remove();
      element._portalTooltip = null;
    }
  }

  function showTooltip(element) {
    var text = (element.getAttribute('data-original-title') || '').trim();
    if (!text || element._portalTooltip) {
      return;
    }

    var tooltip = document.createElement('div');
    tooltip.className = 'portal-tooltip';
    tooltip.textContent = text;
    if (text.length > 60) {
      tooltip.classList.add('is-wrap');
    }
    document.body.appendChild(tooltip);

    var rect = element.getBoundingClientRect();
    var tipWidth = tooltip.offsetWidth;
    var tipHeight = tooltip.offsetHeight;
    var left = rect.left + rect.width / 2 - tipWidth / 2;
    var top = rect.top - tipHeight - 10;

    left = Math.max(8, Math.min(left, window.innerWidth - tipWidth - 8));
    if (top < 8) {
      top = rect.bottom + 10;
      tooltip.classList.add('is-below');
    }

    tooltip.style.left = left + 'px';
    tooltip.style.top = top + 'px';
    requestAnimationFrame(function () {
      tooltip.classList.add('is-visible');
    });

    element._portalTooltip = tooltip;
  }

  function bindElement(element) {
    if (element.dataset.portalTooltipReady === '1') {
      return;
    }

    var originalTitle = (element.getAttribute('title') || element.getAttribute('data-original-title') || '').trim();
    if (!originalTitle) {
      return;
    }

    element.dataset.portalTooltipReady = '1';
    element.setAttribute('data-original-title', originalTitle);
    element.removeAttribute('title');

    element.addEventListener('mouseenter', function () {
      showTooltip(this);
    });
    element.addEventListener('focus', function () {
      showTooltip(this);
    });
    element.addEventListener('mouseleave', function () {
      hideTooltip(this);
    });
    element.addEventListener('blur', function () {
      hideTooltip(this);
    });
  }

  function init(root) {
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll(SELECTOR).forEach(bindElement);
  }

  window.PortalTooltips = {
    init: init,
    bind: bindElement
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      init();
    });
  } else {
    init();
  }
})(window, document);
