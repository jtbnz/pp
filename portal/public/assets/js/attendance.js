/**
 * Attendance Gauges and Details
 *
 * Renders speedometer-style attendance gauges and handles
 * the popup modal for detailed attendance information.
 */

(function() {
    'use strict';

    const basePath = window.BASE_PATH || '';

    /**
     * Create an SVG speedometer gauge
     *
     * @param {Object} config - Gauge configuration
     * @param {number} config.percent - Current percentage value (0-100)
     * @param {number} config.threshold - Threshold value for color change
     * @param {string} config.label - Label to display below gauge
     * @param {string} config.id - Unique ID for the gauge
     */
    function createGauge(config) {
        const { percent, threshold, label, id } = config;
        const size = 140;
        const strokeWidth = 12;
        const radius = (size - strokeWidth) / 2;
        const circumference = radius * Math.PI; // Half circle
        const center = size / 2;

        // Calculate the stroke offset based on percentage
        const offset = circumference - (percent / 100) * circumference;

        // Determine color based on threshold
        const isAboveThreshold = percent >= threshold;
        const mainColor = isAboveThreshold ? '#2E7D32' : '#C62828';  // Green or Red
        const bgColor = '#E0E0E0';

        // Calculate needle angle (0% = -90deg, 100% = 90deg)
        const needleAngle = -90 + (percent / 100) * 180;

        // Calculate threshold position on arc
        const thresholdAngle = -90 + (threshold / 100) * 180;

        const svg = `
            <svg viewBox="0 0 ${size} ${size * 0.7}" class="attendance-gauge-svg" aria-label="${label}: ${percent}%">
                <defs>
                    <!-- Red to green gradient based on threshold -->
                    <linearGradient id="gauge-gradient-${id}" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#C62828" />
                        <stop offset="${threshold}%" style="stop-color:#C62828" />
                        <stop offset="${threshold}%" style="stop-color:#2E7D32" />
                        <stop offset="100%" style="stop-color:#2E7D32" />
                    </linearGradient>
                </defs>

                <!-- Background arc (gray) -->
                <path
                    d="M ${strokeWidth / 2} ${center} A ${radius} ${radius} 0 0 1 ${size - strokeWidth / 2} ${center}"
                    fill="none"
                    stroke="${bgColor}"
                    stroke-width="${strokeWidth}"
                    stroke-linecap="round"
                />

                <!-- Red section (below threshold) -->
                <path
                    d="M ${strokeWidth / 2} ${center} A ${radius} ${radius} 0 0 1 ${size - strokeWidth / 2} ${center}"
                    fill="none"
                    stroke="#FFCDD2"
                    stroke-width="${strokeWidth}"
                    stroke-linecap="round"
                    stroke-dasharray="${circumference * (threshold / 100)} ${circumference}"
                />

                <!-- Green section (above threshold) -->
                <path
                    d="M ${strokeWidth / 2} ${center} A ${radius} ${radius} 0 0 1 ${size - strokeWidth / 2} ${center}"
                    fill="none"
                    stroke="#C8E6C9"
                    stroke-width="${strokeWidth}"
                    stroke-linecap="round"
                    stroke-dasharray="${circumference * ((100 - threshold) / 100)} ${circumference}"
                    stroke-dashoffset="${-circumference * (threshold / 100)}"
                />

                <!-- Threshold marker (black line) -->
                <line
                    x1="${center}"
                    y1="${center}"
                    x2="${center + (radius - strokeWidth) * Math.cos(thresholdAngle * Math.PI / 180)}"
                    y2="${center + (radius - strokeWidth) * Math.sin(thresholdAngle * Math.PI / 180)}"
                    stroke="#000"
                    stroke-width="2"
                    stroke-linecap="round"
                />

                <!-- Needle -->
                <g transform="rotate(${needleAngle}, ${center}, ${center})">
                    <polygon
                        points="${center},${center - radius + 15} ${center - 4},${center} ${center + 4},${center}"
                        fill="${mainColor}"
                    />
                    <circle cx="${center}" cy="${center}" r="6" fill="${mainColor}" />
                </g>

                <!-- Percentage text -->
                <text x="${center}" y="${center + 25}" text-anchor="middle" class="gauge-percent">${percent}%</text>
            </svg>
        `;

        return svg;
    }

    /**
     * Initialize attendance display for a member
     */
    function initAttendance(memberId, container) {
        if (!memberId || !container) return;

        // Show loading state
        container.innerHTML = `
            <div class="attendance-loading">
                <div class="spinner"></div>
                <p>Loading attendance data...</p>
            </div>
        `;

        // Fetch attendance data
        fetch(`${basePath}/api/members/${memberId}/attendance`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Failed to load attendance data');
            }
            renderAttendance(container, data.stats, data.sync, memberId);
        })
        .catch(error => {
            console.error('Error loading attendance:', error);
            container.innerHTML = `
                <div class="attendance-error">
                    <p>Unable to load attendance data</p>
                    <button type="button" class="btn btn-sm" onclick="window.AttendanceModule.refresh(${memberId}, this.closest('.attendance-container'))">
                        Retry
                    </button>
                </div>
            `;
        });
    }

    /**
     * Render attendance gauges and stats
     */
    function renderAttendance(container, stats, sync, memberId) {
        const trainingGauge = createGauge({
            percent: stats.training.percent,
            threshold: stats.training.threshold,
            label: 'Training',
            id: 'training'
        });

        const calloutGauge = createGauge({
            percent: stats.callout.percent,
            threshold: stats.callout.threshold,
            label: 'Callouts',
            id: 'callout'
        });

        const syncInfo = sync
            ? `<span class="sync-time">Last updated: ${formatTimeAgo(sync.last_sync)}</span>`
            : '<span class="sync-time">Not synced yet</span>';

        container.innerHTML = `
            <div class="attendance-gauges">
                <div class="gauge-wrapper" data-type="training" title="Click for details">
                    ${trainingGauge}
                    <span class="gauge-label">Training</span>
                    <span class="gauge-stats">${stats.training.attended}/${stats.training.total - stats.training.leave}</span>
                </div>
                <div class="gauge-wrapper" data-type="callout" title="Click for details">
                    ${calloutGauge}
                    <span class="gauge-label">Callouts</span>
                    <span class="gauge-stats">${stats.callout.attended}/${stats.callout.total - stats.callout.leave}</span>
                </div>
            </div>
            <div class="attendance-footer">
                ${syncInfo}
                <button type="button" class="btn-link view-details" data-member-id="${memberId}">
                    View Details
                </button>
            </div>
        `;

        // Add click handlers
        container.querySelectorAll('.gauge-wrapper').forEach(wrapper => {
            wrapper.addEventListener('click', () => showDetailsModal(memberId, stats));
        });

        container.querySelector('.view-details')?.addEventListener('click', () => {
            showDetailsModal(memberId, stats);
        });
    }

    /**
     * Show the attendance details modal
     */
    function showDetailsModal(memberId, stats) {
        // Create modal if it doesn't exist
        let modal = document.getElementById('attendance-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'attendance-modal';
            modal.className = 'modal';
            modal.innerHTML = `
                <div class="modal-backdrop"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Attendance Details</h2>
                        <button type="button" class="modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body" id="attendance-modal-body">
                        <div class="attendance-loading">
                            <div class="spinner"></div>
                            <p>Loading...</p>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            // Close handlers
            modal.querySelector('.modal-backdrop').addEventListener('click', () => {
                modal.hidden = true;
            });
            modal.querySelector('.modal-close').addEventListener('click', () => {
                modal.hidden = true;
            });
        }

        // Show modal
        modal.hidden = false;
        const body = document.getElementById('attendance-modal-body');

        // Render stats summary
        body.innerHTML = `
            <div class="attendance-details">
                <div class="details-section">
                    <h3>12-Month Summary</h3>
                    <div class="stats-grid">
                        <div class="stat-card training">
                            <span class="stat-label">Training Attendance</span>
                            <span class="stat-value ${stats.training.above_threshold ? 'good' : 'low'}">${stats.training.percent}%</span>
                            <span class="stat-detail">${stats.training.attended} attended, ${stats.training.leave} on leave, ${stats.training.absent} absent</span>
                        </div>
                        <div class="stat-card callout">
                            <span class="stat-label">Callout Attendance</span>
                            <span class="stat-value ${stats.callout.above_threshold ? 'good' : 'low'}">${stats.callout.percent}%</span>
                            <span class="stat-detail">${stats.callout.attended} attended, ${stats.callout.leave} on leave, ${stats.callout.absent} absent</span>
                        </div>
                    </div>
                </div>

                <div class="details-section">
                    <h3>Riding Positions</h3>
                    <div class="position-stats">
                        <div class="position-item">
                            <span class="position-label">OIC</span>
                            <span class="position-value">${stats.positions.counts.OIC}</span>
                            <span class="position-percent">${stats.positions.percents.OIC}%</span>
                        </div>
                        <div class="position-item">
                            <span class="position-label">Driver</span>
                            <span class="position-value">${stats.positions.counts.driver}</span>
                            <span class="position-percent">${stats.positions.percents.driver}%</span>
                        </div>
                        <div class="position-item">
                            <span class="position-label">Crew</span>
                            <span class="position-value">${stats.positions.counts.crew}</span>
                            <span class="position-percent">${stats.positions.percents.crew}%</span>
                        </div>
                    </div>
                </div>

                <div class="details-section">
                    <h3>Recent Events</h3>
                    <div id="recent-events-list" class="recent-events">
                        <div class="attendance-loading">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Load recent events
        loadRecentEvents(memberId);
    }

    /**
     * Load and render recent events
     */
    function loadRecentEvents(memberId) {
        const container = document.getElementById('recent-events-list');
        if (!container) return;

        fetch(`${basePath}/api/members/${memberId}/attendance/recent?limit=10`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.events.length) {
                container.innerHTML = '<p class="text-secondary">No recent events found.</p>';
                return;
            }

            container.innerHTML = data.events.map(event => `
                <div class="event-item ${event.status === 'I' ? 'attended' : event.status === 'L' ? 'leave' : 'absent'}">
                    <div class="event-date">
                        <span class="event-day">${event.day_name.substring(0, 3)}</span>
                        <span class="event-full-date">${event.date_formatted}</span>
                    </div>
                    <div class="event-info">
                        <span class="event-type">${event.type_label}</span>
                        <span class="event-status">${event.status_label}</span>
                    </div>
                    ${event.status === 'I' ? `
                    <div class="event-position">
                        ${event.position_label}${event.truck ? ` - ${event.truck}` : ''}
                    </div>
                    ` : ''}
                </div>
            `).join('');
        })
        .catch(error => {
            console.error('Error loading recent events:', error);
            container.innerHTML = '<p class="text-secondary">Failed to load recent events.</p>';
        });
    }

    /**
     * Format a datetime string as relative time
     */
    function formatTimeAgo(dateStr) {
        if (!dateStr) return 'Never';

        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
        if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
        if (diffDays < 7) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;

        return date.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // Expose module
    window.AttendanceModule = {
        init: initAttendance,
        refresh: initAttendance,
        createGauge: createGauge,
        showDetails: showDetailsModal
    };

    // Auto-initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.querySelector('.attendance-container');
        const memberId = container?.dataset.memberId;
        if (container && memberId) {
            initAttendance(memberId, container);
        }
    });
})();
