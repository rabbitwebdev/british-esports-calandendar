(function () {
  function parseData(wrapper) {
    const script = wrapper.querySelector('.bef-calendar-data');
    if (!script) return null;
    try {
      return JSON.parse(script.textContent || '{}');
    } catch (error) {
      console.error('BEF Calendar JSON parse error:', error);
      return null;
    }
  }

  function pad(value) {
    return String(value).padStart(2, '0');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function dateFromKey(dateString) {
    if (!dateString || typeof dateString !== 'string') return null;
    const parts = dateString.split('-').map(Number);
    if (parts.length !== 3 || parts.some((part) => Number.isNaN(part))) {
      return null;
    }

    const [year, month, day] = parts;
    return new Date(year, month - 1, day, 12, 0, 0, 0);
  }

  function dateToKey(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  function formatHumanDate(dateString) {
    const date = dateFromKey(dateString);
    if (!date) return dateString || '';

    return date.toLocaleDateString(undefined, {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric'
    });
  }

  function formatEventDateRange(event) {
    if (!event.date) return '';
    const startText = formatHumanDate(event.date);
    if (event.endDate && event.endDate !== event.date) {
      return `${startText} - ${formatHumanDate(event.endDate)}`;
    }
    return startText;
  }

  function formatTimeRange(event) {
    if (!event.startTime && !event.endTime) return '';
    if (event.startTime && event.endTime) return `${event.startTime} - ${event.endTime}`;
    return event.startTime || event.endTime || '';
  }

  function getEventDates(event) {
    const dates = [];
    const start = dateFromKey(event.date);
    const end = event.endDate ? dateFromKey(event.endDate) : dateFromKey(event.date);

    if (!start || !end) {
      return dates;
    }

    const cursor = new Date(start.getFullYear(), start.getMonth(), start.getDate(), 12, 0, 0, 0);
    const endTime = new Date(end.getFullYear(), end.getMonth(), end.getDate(), 12, 0, 0, 0).getTime();

    while (cursor.getTime() <= endTime) {
      dates.push(dateToKey(cursor));
      cursor.setDate(cursor.getDate() + 1);
    }

    return dates;
  }

  function groupEventsByDate(events) {
    const grouped = new Map();
    events.forEach((event) => {
      getEventDates(event).forEach((dateKey) => {
        if (!grouped.has(dateKey)) grouped.set(dateKey, []);
        grouped.get(dateKey).push(event);
      });
    });
    return grouped;
  }

  function sortEvents(events) {
    return [...events].sort((a, b) => {
      const aKey = `${a.date || ''} ${a.startTime || '23:59'}`;
      const bKey = `${b.date || ''} ${b.startTime || '23:59'}`;
      return aKey.localeCompare(bKey);
    });
  }

  function buildActions(event, labels) {
    const ticketLabel = escapeHtml(event.ticketLabel || labels.getTickets || 'Get Tickets');
    const hasViewLink = Boolean(event.url);
    const hasTicketLink = Boolean(event.ticketUrl);
    const duplicateLinks = hasViewLink && hasTicketLink && event.url === event.ticketUrl;
    const actions = [];

    if (hasTicketLink) {
      actions.push(`<a href="${escapeHtml(event.ticketUrl)}" class="bef-event-link bef-event-link--ticket" target="_blank" rel="noopener noreferrer">${ticketLabel}</a>`);
    }

    if (hasViewLink && !duplicateLinks) {
      const externalAttrs = event.source === 'eventbrite' ? ' target="_blank" rel="noopener noreferrer"' : '';
      actions.push(`<a href="${escapeHtml(event.url)}" class="bef-event-link bef-event-link--detail"${externalAttrs}>${escapeHtml(event.linkLabel || labels.viewEvent)}</a>`);
    }

    return actions.length ? `<div class="bef-event-actions">${actions.join('')}</div>` : '';
  }

  function renderSidebar(wrapper, labels, groupedEvents, selectedKey) {
    const selectedDateEl = wrapper.querySelector('.bef-selected-date');
    const eventListEl = wrapper.querySelector('.bef-event-list');
    if (!selectedDateEl || !eventListEl) return;

    selectedDateEl.textContent = formatHumanDate(selectedKey);
    const events = sortEvents(groupedEvents.get(selectedKey) || []);

    if (!events.length) {
      eventListEl.innerHTML = `<p class="bef-empty-state">${escapeHtml(labels.noEvents || 'No events on this day.')}</p>`;
      return;
    }

    eventListEl.innerHTML = events.map((event) => {
      const thumb = event.thumbnail ? `<div class="bef-event-thumb"><img src="${escapeHtml(event.thumbnail)}" alt=""></div>` : '';
      const location = event.location ? `<p class="bef-event-location">${escapeHtml(event.location)}</p>` : '';
      const excerpt = event.excerpt ? `<p class="bef-event-excerpt">${escapeHtml(event.excerpt)}</p>` : '';
      const time = formatTimeRange(event) ? `<p class="bef-event-time">${escapeHtml(formatTimeRange(event))}</p>` : '';
      const source = event.sourceLabel ? `<p class="bef-event-source">${escapeHtml(event.sourceLabel)}</p>` : '';
      const recurrence = event.recurrenceSummary ? `<p class="bef-event-recurrence">${escapeHtml(event.recurrenceSummary)}</p>` : '';

      return `
        <article class="bef-event-card">
          ${thumb}
          <div class="bef-event-card__content">
            ${source}
            ${recurrence}
            <h4>${escapeHtml(event.title)}</h4>
            ${time}
            ${location}
            ${excerpt}
            ${buildActions(event, labels)}
          </div>
        </article>
      `;
    }).join('');
  }

  function renderAgenda(wrapper, labels, events) {
    const agendaListEl = wrapper.querySelector('.bef-agenda-list');
    if (!agendaListEl) return;

    const sortedEvents = sortEvents(events);

    if (!sortedEvents.length) {
      agendaListEl.innerHTML = `<p class="bef-agenda-empty">${escapeHtml(labels.agendaEmpty || 'No events available right now.')}</p>`;
      return;
    }

    const grouped = new Map();
    sortedEvents.forEach((event) => {
      const key = event.date || '';
      if (!grouped.has(key)) grouped.set(key, []);
      grouped.get(key).push(event);
    });

    agendaListEl.innerHTML = Array.from(grouped.entries()).map(([dateKey, dateEvents]) => {
      const heading = formatHumanDate(dateKey);

      const cards = dateEvents.map((event) => {
        const thumb = event.thumbnail ? `<div class="bef-agenda-thumb"><img src="${escapeHtml(event.thumbnail)}" alt=""></div>` : '';
        const time = formatTimeRange(event) ? `<span>${escapeHtml(formatTimeRange(event))}</span>` : '';
        const location = event.location ? `<span>${escapeHtml(event.location)}</span>` : '';
        const source = event.sourceLabel ? `<span>${escapeHtml(event.sourceLabel)}</span>` : '';
        const excerpt = event.excerpt ? `<p class="bef-agenda-excerpt">${escapeHtml(event.excerpt)}</p>` : '';
        const recurrence = event.recurrenceSummary ? `<p class="bef-event-recurrence">${escapeHtml(event.recurrenceSummary)}</p>` : '';

        return `
          <article class="bef-agenda-card">
            ${thumb}
            <div class="bef-agenda-content">
              <h4>${escapeHtml(event.title)}</h4>
              <div class="bef-agenda-meta">
                <span>${escapeHtml(formatEventDateRange(event))}</span>
                ${time}
                ${location}
                ${source}
              </div>
              ${recurrence}
              ${excerpt}
              ${buildActions(event, labels)}
            </div>
          </article>
        `;
      }).join('');

      return `
        <section class="bef-agenda-group">
          <h3 class="bef-agenda-date">${escapeHtml(heading)}</h3>
          ${cards}
        </section>
      `;
    }).join('');
  }

  function renderCalendar(wrapper) {
    const data = parseData(wrapper);
    if (!data) return;

    const events = Array.isArray(data.events) ? data.events : [];
    const labels = data.labels || {};
    const settings = data.settings || {};
    const groupedEvents = groupEventsByDate(events);

    const monthEl = wrapper.querySelector('.bef-calendar-month');
    const weekdaysEl = wrapper.querySelector('.bef-calendar-weekdays');
    const gridEl = wrapper.querySelector('.bef-calendar-grid');
    const navButtons = wrapper.querySelectorAll('[data-bef-nav]');
    const viewButtons = wrapper.querySelectorAll('[data-bef-view]');
    const monthPanel = wrapper.querySelector('.bef-view-panel--month');
    const agendaPanel = wrapper.querySelector('.bef-view-panel--agenda');
    const showSidebar = wrapper.dataset.showSidebar === '1';

    if (!monthEl || !weekdaysEl || !gridEl || !monthPanel || !agendaPanel) return;

    weekdaysEl.innerHTML = (labels.dayNames || []).map((day) => `<div>${escapeHtml(day)}</div>`).join('');

    const now = new Date();
    const todayKey = dateToKey(now);
    let visibleDate = new Date(now.getFullYear(), now.getMonth(), 1, 12, 0, 0, 0);
    let selectedKey = todayKey;
    let currentView = settings.view === 'agenda' ? 'agenda' : 'month';

    if (!groupedEvents.has(selectedKey) && events[0] && events[0].date) {
      selectedKey = events[0].date;
      const selectedDate = dateFromKey(selectedKey);
      if (selectedDate) {
        visibleDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1, 12, 0, 0, 0);
      }
    }

    function syncViewButtons() {
      wrapper.classList.toggle('is-view-agenda', currentView === 'agenda');
      wrapper.classList.toggle('is-view-month', currentView === 'month');

      monthPanel.hidden = currentView !== 'month';
      agendaPanel.hidden = currentView !== 'agenda';

      viewButtons.forEach((button) => {
        const isActive = button.dataset.befView === currentView;
        button.classList.toggle('is-active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });
    }

    function renderGrid() {
      const year = visibleDate.getFullYear();
      const month = visibleDate.getMonth();
      const firstDay = new Date(year, month, 1, 12, 0, 0, 0);
      const lastDay = new Date(year, month + 1, 0, 12, 0, 0, 0);
      const daysInMonth = lastDay.getDate();
      const startOffset = (firstDay.getDay() + 6) % 7;
      const monthName = Array.isArray(labels.monthNames) && labels.monthNames[month] ? labels.monthNames[month] : firstDay.toLocaleDateString(undefined, { month: 'long' });

      monthEl.textContent = `${monthName} ${year}`;

      let html = '';

      for (let i = 0; i < startOffset; i += 1) {
        html += '<button type="button" class="bef-day is-empty" disabled></button>';
      }

      for (let day = 1; day <= daysInMonth; day += 1) {
        const current = new Date(year, month, day, 12, 0, 0, 0);
        const key = dateToKey(current);
        const dayEvents = groupedEvents.get(key) || [];
        const isToday = key === todayKey;
        const isSelected = key === selectedKey;
        const countMarkup = dayEvents.length
          ? `<span class="bef-day-count">${dayEvents.length}</span><span class="bef-day-dots">${'<i></i>'.repeat(Math.min(dayEvents.length, 3))}</span>`
          : '';

        html += `
          <button type="button" class="bef-day ${isToday ? 'is-today' : ''} ${isSelected ? 'is-selected' : ''} ${dayEvents.length ? 'has-events' : ''}" data-date="${key}">
            <span class="bef-day-number">${day}</span>
            ${countMarkup}
          </button>
        `;
      }

      gridEl.innerHTML = html;

      gridEl.querySelectorAll('[data-date]').forEach((button) => {
        button.addEventListener('click', () => {
          selectedKey = button.dataset.date;
          renderGrid();
          if (showSidebar) {
            renderSidebar(wrapper, labels, groupedEvents, selectedKey);
          }
        });
      });

      if (showSidebar) {
        renderSidebar(wrapper, labels, groupedEvents, selectedKey);
      }
    }

    function renderAgendaView() {
      renderAgenda(wrapper, labels, events);
    }

    function renderActiveView() {
      syncViewButtons();

      if (currentView === 'agenda') {
        renderAgendaView();
        return;
      }

      renderGrid();
    }

    navButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const action = button.dataset.befNav;
        if (action === 'prev') {
          visibleDate = new Date(visibleDate.getFullYear(), visibleDate.getMonth() - 1, 1, 12, 0, 0, 0);
        }
        if (action === 'next') {
          visibleDate = new Date(visibleDate.getFullYear(), visibleDate.getMonth() + 1, 1, 12, 0, 0, 0);
        }
        if (action === 'today') {
          visibleDate = new Date(now.getFullYear(), now.getMonth(), 1, 12, 0, 0, 0);
          selectedKey = todayKey;
        }
        if (currentView === 'month') {
          renderGrid();
        }
      });
    });

    viewButtons.forEach((button) => {
      button.addEventListener('click', () => {
        currentView = button.dataset.befView === 'agenda' ? 'agenda' : 'month';
        renderActiveView();
      });
    });

    if (!settings.showViewToggle && viewButtons.length) {
      const toggle = viewButtons[0].closest('.bef-view-toggle');
      if (toggle) {
        toggle.hidden = true;
      }
    }

    renderActiveView();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.bef-calendar-wrap').forEach(renderCalendar);
  });
})();
