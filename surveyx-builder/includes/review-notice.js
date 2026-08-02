/**
 * SurveyX review-request admin notice behaviour.
 * Config (ajaxUrl, nonce) is provided via the localized `surveyxReviewData`
 * object. Enqueued by includes/review-notice.php.
 */
;(function () {
  'use strict'

  var data = window.surveyxReviewData || {}
  var notice = document.querySelector('[data-surveyx-review]')
  if (!notice) {
    return
  }

  function record(choice) {
    var body = new FormData()
    body.append('action', 'surveyx_review_action')
    body.append('choice', choice)
    body.append('nonce', data.nonce)

    if (navigator.sendBeacon) {
      navigator.sendBeacon(data.ajaxUrl, body)
    } else {
      fetch(data.ajaxUrl, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        keepalive: true
      })
    }
  }

  notice.addEventListener('click', function (e) {
    var el = e.target.closest('[data-surveyx-review-action]')
    if (el) {
      var choice = el.getAttribute('data-surveyx-review-action')
      record(choice)
      // "Rate" is a real link to WordPress.org — let it open, just hide the notice.
      if (!el.href || '_blank' !== el.getAttribute('target')) {
        e.preventDefault()
      }
      notice.style.display = 'none'
      return
    }
    // Native WP dismiss (X) snoozes for a while.
    if (e.target.classList.contains('notice-dismiss')) {
      record('later')
    }
  })
})()
