/**
 * Puke Portal - Calendar View Controller
 *
 * Handles calendar navigation (prev/today/next) and view switching (day/week/month).
 * ES6+ with no dependencies.
 */

'use strict';

(function() {
    // Get configuration from page
    const config = window.calendarConfig || {};
    const currentView = config.view || 'month';
    const currentDate = config.currentDate || new Date().toISOString().split('T')[0];

    /**
     * Update the URL and reload the page with new parameters
     * @param {Object} params - Parameters to update
     */
    function updateCalendar(params) {
        const url = new URL(window.location.href);

        Object.keys(params).forEach(key => {
            if (params[key] !== null && params[key] !== undefined) {
                url.searchParams.set(key, params[key]);
            } else {
                url.searchParams.delete(key);
            }
        });

        window.location.href = url.toString();
    }

    /**
     * Calculate the new date based on navigation direction and current view
     * @param {string} direction - 'prev', 'next', or 'today'
     * @param {string} view - 'day', 'week', or 'month'
     * @param {string} currentDateStr - Current date in YYYY-MM-DD format
     * @returns {string} New date in YYYY-MM-DD format
     */
    function calculateNewDate(direction, view, currentDateStr) {
        if (direction === 'today') {
            return new Date().toISOString().split('T')[0];
        }

        const date = new Date(currentDateStr + 'T12:00:00'); // Use noon to avoid timezone issues
        const multiplier = direction === 'prev' ? -1 : 1;

        switch (view) {
            case 'day':
                date.setDate(date.getDate() + (1 * multiplier));
                break;
            case 'week':
                date.setDate(date.getDate() + (7 * multiplier));
                break;
            case 'month':
            default:
                date.setMonth(date.getMonth() + (1 * multiplier));
                break;
        }

        return date.toISOString().split('T')[0];
    }

    /**
     * Initialize calendar controls
     */
    function init() {
        // View switcher buttons
        const viewButtons = document.querySelectorAll('.calendar-view-switcher [data-view]');
        viewButtons.forEach(button => {
            button.addEventListener('click', function() {
                const newView = this.dataset.view;
                if (newView && newView !== currentView) {
                    updateCalendar({ view: newView });
                }
            });
        });

        // Navigation buttons
        const prevButton = document.getElementById('calendar-prev');
        const nextButton = document.getElementById('calendar-next');
        const todayButton = document.getElementById('calendar-today');

        if (prevButton) {
            prevButton.addEventListener('click', function() {
                const newDate = calculateNewDate('prev', currentView, currentDate);
                updateCalendar({ date: newDate });
            });
        }

        if (nextButton) {
            nextButton.addEventListener('click', function() {
                const newDate = calculateNewDate('next', currentView, currentDate);
                updateCalendar({ date: newDate });
            });
        }

        if (todayButton) {
            todayButton.addEventListener('click', function() {
                const newDate = calculateNewDate('today', currentView, currentDate);
                updateCalendar({ date: newDate });
            });
        }

        // Day click handlers for quick navigation
        const dayElements = document.querySelectorAll('.calendar-day[data-date]');
        dayElements.forEach(day => {
            day.addEventListener('click', function(e) {
                // Don't trigger if clicking on an event link
                if (e.target.closest('.day-event')) {
                    return;
                }

                const clickedDate = this.dataset.date;
                if (clickedDate) {
                    // Navigate to day view for that date
                    updateCalendar({ view: 'day', date: clickedDate });
                }
            });
        });

        // Week column click handlers
        const weekColumns = document.querySelectorAll('.week-day-column[data-date]');
        weekColumns.forEach(column => {
            column.addEventListener('click', function(e) {
                // Don't trigger if clicking on an event link
                if (e.target.closest('.week-event')) {
                    return;
                }

                const clickedDate = this.dataset.date;
                if (clickedDate) {
                    // Navigate to day view for that date
                    updateCalendar({ view: 'day', date: clickedDate });
                }
            });
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            // Only handle if not in an input field
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }

            switch (e.key) {
                case 'ArrowLeft':
                    if (prevButton) {
                        e.preventDefault();
                        prevButton.click();
                    }
                    break;
                case 'ArrowRight':
                    if (nextButton) {
                        e.preventDefault();
                        nextButton.click();
                    }
                    break;
                case 't':
                case 'T':
                    if (todayButton) {
                        e.preventDefault();
                        todayButton.click();
                    }
                    break;
                case 'd':
                case 'D':
                    e.preventDefault();
                    updateCalendar({ view: 'day' });
                    break;
                case 'w':
                case 'W':
                    e.preventDefault();
                    updateCalendar({ view: 'week' });
                    break;
                case 'm':
                case 'M':
                    e.preventDefault();
                    updateCalendar({ view: 'month' });
                    break;
            }
        });

        console.log('[Calendar] Initialized with view:', currentView, 'date:', currentDate);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
