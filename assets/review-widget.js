/* Superman Reviews Widget v1.11.0 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', init);

  function init() {
    document.querySelectorAll('.superman-reviews-widget').forEach(setupWidget);
  }

  function setupWidget(widget) {
    var orientation = widget.getAttribute('data-orientation') || 'horizontal';
    var autoplay = widget.getAttribute('data-autoplay') === '1';

    var track = widget.querySelector('.srw-track');
    if (!track) return;

    var cards = Array.prototype.slice.call(track.querySelectorAll('.srw-card'));

    // Read-more toggles (both orientations)
    cards.forEach(function (card) {
      var btn = card.querySelector('.srw-read-more');
      if (!btn) return;
      btn.addEventListener('click', function () {
        var short = card.querySelector('.srw-comment-short');
        var full = card.querySelector('.srw-comment-full');
        if (!short || !full) return;
        var expanded = !full.hasAttribute('hidden');
        if (expanded) {
          full.setAttribute('hidden', '');
          short.removeAttribute('hidden');
          btn.textContent = btn.getAttribute('data-more') || 'More';
        } else {
          full.removeAttribute('hidden');
          short.setAttribute('hidden', '');
          btn.textContent = btn.getAttribute('data-less') || 'Less';
        }
      });
    });

    // Refresh relative times
    widget.querySelectorAll('.srw-time[data-time]').forEach(function (el) {
      var time = new Date(el.getAttribute('data-time'));
      if (!isNaN(time.getTime())) el.textContent = relativeTime(time);
    });

    // Vertical mode: nothing else to wire — just stack and let the page scroll
    if (orientation === 'vertical') return;

    // Horizontal: paginated carousel
    setupCarousel(widget, track, cards, autoplay);
  }

  function setupCarousel(widget, track, cards, autoplay) {
    var VISIBLE = computeVisible();
    var start = 0;
    var paused = false;
    var intervalId = null;

    var prevBtn = widget.querySelector('.srw-nav-prev');
    var nextBtn = widget.querySelector('.srw-nav-next');
    var dotsContainer = widget.querySelector('.srw-dots');

    function computeVisible() {
      var w = window.innerWidth;
      if (w < 700) return 1;
      if (w < 900) return 2;
      return 3;
    }

    function maxStart() {
      return Math.max(0, cards.length - VISIBLE);
    }

    function render() {
      cards.forEach(function (card, i) {
        var visible = i >= start && i < start + VISIBLE;
        if (visible) {
          card.classList.remove('srw-card-hidden');
          // Reset animation by toggling the class via reflow
          card.style.animation = 'none';
          // eslint-disable-next-line no-unused-expressions
          card.offsetHeight;
          card.style.animation = '';
        } else {
          card.classList.add('srw-card-hidden');
        }
      });
      renderDots();
    }

    function renderDots() {
      if (!dotsContainer) return;
      var pages = maxStart() + 1;
      dotsContainer.innerHTML = '';
      for (var i = 0; i < pages; i++) {
        var dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'srw-dot' + (i === start ? ' is-active' : '');
        dot.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        (function (idx) {
          dot.addEventListener('click', function () { go(idx - start); });
        })(i);
        dotsContainer.appendChild(dot);
      }
    }

    function go(delta) {
      var next = start + delta;
      var ms = maxStart();
      if (next < 0) next = ms;
      if (next > ms) next = 0;
      start = next;
      render();
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { go(-1); resetAutoplay(); });
    if (nextBtn) nextBtn.addEventListener('click', function () { go(1); resetAutoplay(); });

    // Touch swipe
    var touchStartX = 0;
    track.addEventListener('touchstart', function (e) {
      touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    track.addEventListener('touchend', function (e) {
      var diff = touchStartX - e.changedTouches[0].screenX;
      if (Math.abs(diff) > 50) { go(diff > 0 ? 1 : -1); resetAutoplay(); }
    }, { passive: true });

    // Hover pause
    widget.addEventListener('mouseenter', function () { paused = true; });
    widget.addEventListener('mouseleave', function () { paused = false; });

    function startAutoplay() {
      if (!autoplay) return;
      intervalId = setInterval(function () {
        if (!paused) go(1);
      }, 5000);
    }

    function resetAutoplay() {
      if (intervalId) clearInterval(intervalId);
      startAutoplay();
    }

    // Resize handler — recompute VISIBLE
    var resizeTimer = null;
    window.addEventListener('resize', function () {
      if (resizeTimer) clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        var nv = computeVisible();
        if (nv !== VISIBLE) {
          VISIBLE = nv;
          if (start > maxStart()) start = maxStart();
          render();
        }
      }, 120);
    });

    render();
    startAutoplay();
  }

  function relativeTime(date) {
    var diff = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (diff < 60) return 'just now';
    if (diff < 3600) { var m = Math.floor(diff / 60); return m + (m === 1 ? ' minute ago' : ' minutes ago'); }
    if (diff < 86400) { var h = Math.floor(diff / 3600); return h + (h === 1 ? ' hour ago' : ' hours ago'); }
    if (diff < 2592000) { var d = Math.floor(diff / 86400); return d + (d === 1 ? ' day ago' : ' days ago'); }
    if (diff < 31536000) { var mo = Math.floor(diff / 2592000); return mo + (mo === 1 ? ' month ago' : ' months ago'); }
    var y = Math.floor(diff / 31536000);
    return y + (y === 1 ? ' year ago' : ' years ago');
  }
})();
