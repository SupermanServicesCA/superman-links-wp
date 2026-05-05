(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var widgets = document.querySelectorAll('.superman-reviews-widget');
    widgets.forEach(function(widget) {
      var track = widget.querySelector('.srw-track');
      var leftBtn = widget.querySelector('.srw-arrow-left');
      var rightBtn = widget.querySelector('.srw-arrow-right');

      if (!track) return;

      var scrollAmount = function() {
        var card = track.querySelector('.srw-card');
        if (!card) return 300;
        var style = getComputedStyle(track);
        var gap = parseInt(style.gap) || 16;
        return card.offsetWidth + gap;
      };

      if (leftBtn) {
        leftBtn.addEventListener('click', function() {
          track.scrollBy({ left: -scrollAmount(), behavior: 'smooth' });
        });
      }
      if (rightBtn) {
        rightBtn.addEventListener('click', function() {
          track.scrollBy({ left: scrollAmount(), behavior: 'smooth' });
        });
      }

      var updateArrows = function() {
        if (leftBtn) leftBtn.style.display = track.scrollLeft <= 0 ? 'none' : '';
        if (rightBtn) rightBtn.style.display = track.scrollLeft + track.clientWidth >= track.scrollWidth - 5 ? 'none' : '';
      };
      track.addEventListener('scroll', updateArrows);
      updateArrows();
      window.addEventListener('resize', updateArrows);

      // Read more / Read less toggles
      widget.querySelectorAll('.srw-read-more').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var card = btn.closest('.srw-card-comment');
          var short = card.querySelector('.srw-comment-short');
          var full = card.querySelector('.srw-comment-full');
          if (short.style.display === 'none') {
            short.style.display = '';
            full.style.display = 'none';
            btn.textContent = 'Read more';
          } else {
            short.style.display = 'none';
            full.style.display = '';
            btn.textContent = 'Read less';
          }
        });
      });

      // Touch swipe support
      var touchStartX = 0;
      var touchEndX = 0;
      track.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
      }, { passive: true });
      track.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        var diff = touchStartX - touchEndX;
        if (Math.abs(diff) > 50) {
          track.scrollBy({ left: diff > 0 ? scrollAmount() : -scrollAmount(), behavior: 'smooth' });
        }
      }, { passive: true });

      // Update relative times (keep them fresh on page load)
      widget.querySelectorAll('.srw-time[data-time]').forEach(function(el) {
        var time = new Date(el.getAttribute('data-time'));
        if (!isNaN(time.getTime())) {
          el.textContent = relativeTime(time);
        }
      });
    });

    function relativeTime(date) {
      var now = new Date();
      var diff = Math.floor((now - date) / 1000);
      if (diff < 60) return 'just now';
      if (diff < 3600) {
        var min = Math.floor(diff / 60);
        return min + (min === 1 ? ' minute ago' : ' minutes ago');
      }
      if (diff < 86400) {
        var hr = Math.floor(diff / 3600);
        return hr + (hr === 1 ? ' hour ago' : ' hours ago');
      }
      if (diff < 2592000) {
        var d = Math.floor(diff / 86400);
        return d + (d === 1 ? ' day ago' : ' days ago');
      }
      if (diff < 31536000) {
        var m = Math.floor(diff / 2592000);
        return m + (m === 1 ? ' month ago' : ' months ago');
      }
      var y = Math.floor(diff / 31536000);
      return y + (y === 1 ? ' year ago' : ' years ago');
    }
  });
})();
