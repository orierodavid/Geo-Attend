(function () {
  'use strict';
  const root = document.querySelector('[data-geo-attend]');
  if (!root || !window.GeoAttend) return;
  const api = window.GeoAttend.api;
  let config = null;
  function escapeHtml(value) { return String(value == null ? '' : value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
  function formatTime(value) {
    const match = String(value || '').match(/^(\d{1,2}):(\d{2})$/);
    if (!match) return String(value || '');
    const hour = Number(match[1]); const minute = Number(match[2]);
    if (hour > 23 || minute > 59) return String(value || '');
    const suffix = hour >= 12 ? 'PM' : 'AM'; const displayHour = hour % 12 || 12;
    return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`;
  }
  function render(message) { root.innerHTML = message; }
  function app() {
    const c = config;
    const departments = c.departments || [];
    const locations = c.locations || [];
    const members = c.members || [];
    const days = (c.schedule && c.schedule.days) || [];
    const start = formatTime(c.schedule && c.schedule.start);
    const end = formatTime(c.schedule && c.schedule.end);
    root.innerHTML = `
      <div class="geo-attend-card">
        <div class="geo-attend-brand">${c.organization_logo ? `<img src="${escapeHtml(c.organization_logo)}" alt="">` : '<span class="geo-attend-mark">G</span>'}
          <div><strong>${escapeHtml(c.organization_name)}</strong><small>Attendance</small></div>
        </div>
        <div class="geo-attend-status ${c.open ? 'is-open' : 'is-closed'}">${c.open ? 'CHECK-IN OPEN' : 'CHECK-IN CLOSED'}</div>
        <div class="geo-attend-intro"><span>ATTENDANCE</span><h2>${c.open ? 'Mark your attendance' : 'Check-in is currently closed'}</h2><p>${c.open ? `Available ${escapeHtml(days.join(', '))} · ${escapeHtml(start)}–${escapeHtml(end)}` : `Available ${escapeHtml(days.join(', '))} between ${escapeHtml(start)} and ${escapeHtml(end)}.`}</p></div>
        ${c.open ? `
        <form class="geo-attend-form" id="geo-attend-form">
          <label>Department<select id="geo-department" required><option value="">Select department</option>${departments.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('')}</select></label>
          <label>Name<select id="geo-member" required disabled><option value="">Select department first</option></select></label>
          <label>Attendance location<select id="geo-location" required>${locations.map(l => `<option value="${l.id}">${escapeHtml(l.name)} · within ${Math.round(l.radius_meters)}m</option>`).join('')}</select></label>
          <label>4-digit PIN<input id="geo-pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9]{4}" placeholder="••••" required></label>
          <div class="geo-location-status" id="geo-location-status">Location will be requested when you check in.</div>
          <div class="geo-attend-error" id="geo-error" hidden></div>
          <button type="submit" id="geo-submit">CHECK IN</button>
        </form>` : `<div class="geo-attend-closed">Next available check-in window: ${escapeHtml(start)}–${escapeHtml(end)}.</div>`}
      </div>`;
    if (!c.open) return;
    const dept = document.getElementById('geo-department');
    const member = document.getElementById('geo-member');
    const form = document.getElementById('geo-attend-form');
    const error = document.getElementById('geo-error');
    const status = document.getElementById('geo-location-status');
    dept.addEventListener('change', function () {
      const selected = Number(this.value);
      const list = members.filter(m => Number(m.department_id) === selected);
      member.innerHTML = '<option value="">Select your name</option>' + list.map(m => `<option value="${m.id}">${escapeHtml(m.name)}</option>`).join('');
      member.disabled = !selected;
    });
    form.addEventListener('submit', async function (event) {
      event.preventDefault(); error.hidden = true;
      const button = document.getElementById('geo-submit');
      button.disabled = true; button.textContent = 'VERIFYING LOCATION…'; status.textContent = 'Requesting location permission…';
      if (!navigator.geolocation) return fail('Location services are not supported by this browser.');
      navigator.geolocation.getCurrentPosition(async function (position) {
        status.textContent = `Location ready · ±${Math.round(position.coords.accuracy)}m accuracy`;
        try {
          const response = await fetch(api + '/check-in', { method: 'POST', headers: {'Content-Type':'application/json','X-WP-Nonce':window.GeoAttend.nonce}, body: JSON.stringify({ member_id:Number(member.value), location_id:Number(document.getElementById('geo-location').value), pin:document.getElementById('geo-pin').value, lat:position.coords.latitude, lng:position.coords.longitude, accuracy:position.coords.accuracy }) });
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || 'Unable to complete check-in.');
          root.innerHTML = `<div class="geo-attend-card geo-attend-success"><div class="geo-success-icon">✓</div><span>ATTENDANCE CONFIRMED</span><h2>You’re marked present.</h2><p>${escapeHtml(data.name)} · ${escapeHtml(data.status === 'late' ? 'Late attendance recorded.' : 'Attendance recorded successfully.')} ${escapeHtml(data.time || '')}</p><button type="button" id="geo-again">BACK TO CHECK-IN</button></div>`;
          document.getElementById('geo-again').addEventListener('click', () => app());
        } catch (e) { fail(e.message); }
      }, function (e) { fail(e.code === 1 ? 'Location permission was denied. Allow location access and try again.' : e.code === 2 ? 'Your location is unavailable. Turn on Location Services and try again.' : 'Location request timed out. Please try again.'); }, {enableHighAccuracy:true, timeout:20000, maximumAge:0});
      function fail(message) { error.textContent = message; error.hidden = false; button.disabled = false; button.textContent = 'CHECK IN'; status.textContent = 'Location is required to verify attendance.'; }
    });
  }
  fetch(api + '/config', {headers:{'X-WP-Nonce':window.GeoAttend.nonce}, cache:'no-store'}).then(r => r.json()).then(data => { if (data.code) throw new Error(data.message || 'Unable to load attendance configuration.'); config = data; app(); }).catch(e => render(`<div class="geo-attend-card geo-attend-error-card"><strong>Geo-Attend unavailable</strong><p>${escapeHtml(e.message)}</p></div>`));
})();
