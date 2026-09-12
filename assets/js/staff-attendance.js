(function () {
  'use strict';
  const portal = document.querySelector('.geo-staff-portal');
  const cfg = window.GeoAttendStaff;
  if (!portal || !cfg) return;

  const api = cfg.api;
  const nonce = cfg.nonce;

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>\"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#039;' })[c];
    });
  }

  function renderCard(state, detail) {
    let body = '';
    if (state === 'loading') {
      body = '<div class="geo-clock-icon">⌖</div><div><span class="geo-clock-kicker">ATTENDANCE</span><h3>Checking your attendance status</h3><p>Confirming your staff account and today’s attendance.</p></div>';
    } else if (state === 'ready') {
      body = '<div class="geo-clock-icon">⌖</div><div><span class="geo-clock-kicker">ATTENDANCE</span><h3>Clock in</h3><p>Verify your current location to record today’s attendance. Your signed-in account is used automatically.</p><button type="button" id="geo-staff-clock-in">CLOCK IN</button><div class="geo-clock-status" id="geo-staff-clock-status">Location verification starts when you clock in.</div></div>';
    } else if (state === 'checking') {
      body = '<div class="geo-clock-icon is-pulse">⌖</div><div><span class="geo-clock-kicker">ATTENDANCE</span><h3>Verifying your location…</h3><p id="geo-staff-clock-status">Requesting your precise location.</p><button type="button" id="geo-staff-clock-in" disabled>VERIFYING…</button></div>';
    } else if (state === 'approved') {
      body = '<div class="geo-clock-icon is-approved">✓</div><div><span class="geo-clock-kicker">CLOCK IN APPROVED</span><h3>You are marked present.</h3><p>' + esc(detail.name) + ' · ' + esc(detail.status_label) + ' at ' + esc(detail.time) + '.</p><div class="geo-clock-distance">' + esc(detail.distance) + 'm from ' + esc(detail.location) + ' · allowed radius ' + esc(detail.radius) + 'm</div></div>';
    } else if (state === 'error') {
      body = '<div class="geo-clock-icon is-error">!</div><div><span class="geo-clock-kicker">CLOCK IN DISAPPROVED</span><h3>Location verification failed</h3><p>' + esc(detail.message) + '</p>' + (detail.distance ? '<div class="geo-clock-distance">Distance: ' + esc(detail.distance) + 'm · allowed radius: ' + esc(detail.radius) + 'm</div>' : '') + '<button type="button" id="geo-staff-clock-in">TRY AGAIN</button></div>';
    }
    let card = document.querySelector('.geo-staff-attendance');
    if (!card) {
      card = document.createElement('section');
      card.className = 'geo-staff-attendance';
      const top = portal.querySelector('.geo-staff-top');
      if (top && top.nextSibling) portal.insertBefore(card, top.nextSibling); else portal.insertBefore(card, portal.firstChild);
    }
    card.innerHTML = '<div class="geo-clock-card ' + state + '">' + body + '</div>';
    const button = document.getElementById('geo-staff-clock-in');
    if (button) button.addEventListener('click', clockIn);
  }

  async function status() {
    renderCard('loading');
    try {
      const response = await fetch(api + '/staff/attendance-status', { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Unable to load attendance status.');
      if (data.attendance && data.attendance.checked_in) {
        renderCard('approved', { name: data.member.name, status_label: data.attendance.status === 'late' ? 'Late attendance recorded' : 'Attendance recorded', time: data.attendance.time, distance: '—', location: data.attendance.location || 'workplace', radius: '—' });
      } else if (data.schedule && data.schedule.open) {
        renderCard('ready');
      } else {
        renderCard('error', { message: 'Clock in is currently closed. Your attendance window is ' + data.schedule.start + '–' + data.schedule.end + '.' });
      }
    } catch (e) {
      renderCard('error', { message: e.message });
    }
  }

  function clockIn() {
    renderCard('checking');
    if (!navigator.geolocation) {
      renderCard('error', { message: 'Location services are not supported by this browser.' });
      return;
    }
    navigator.geolocation.getCurrentPosition(async function (position) {
      const accuracy = Number(position.coords.accuracy);
      const statusText = document.getElementById('geo-staff-clock-status');
      if (statusText) statusText.textContent = 'Location received · ±' + Math.round(accuracy) + 'm accuracy. Checking workplace distance…';
      try {
        const response = await fetch(api + '/staff/check-in', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
          credentials: 'same-origin',
          body: JSON.stringify({ lat: position.coords.latitude, lng: position.coords.longitude, accuracy: accuracy })
        });
        const data = await response.json();
        if (!response.ok) {
          renderCard('error', { message: data.message || 'Clock in was disapproved.', distance: data.data && data.data.distance_meters, radius: data.data && data.data.radius_meters });
          return;
        }
        renderCard('approved', { name: data.name, status_label: data.status === 'late' ? 'Late attendance recorded' : 'Attendance recorded', time: data.time, distance: data.distance_meters, location: data.location, radius: data.radius_meters });
      } catch (e) {
        renderCard('error', { message: e.message || 'Unable to complete clock in.' });
      }
    }, function (e) {
      let message = 'Location verification failed. Please try again.';
      if (e.code === 1) message = 'Location permission was denied. Allow location access for this site and try again.';
      if (e.code === 2) message = 'Your location is unavailable. Turn on Location Services and try again.';
      if (e.code === 3) message = 'Location request timed out. Move to an open area and try again.';
      renderCard('error', { message: message });
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  }

  status();
})();
