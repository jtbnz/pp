/**
 * Puke Portal - Notices Module
 *
 * Handles:
 * - Pull to refresh
 * - Real-time countdown timers
 * - SSE for real-time updates (optional)
 * - Load more pagination
 */

'use strict';

const Notices = {
    // Configuration
    config: {
        refreshThreshold: 80, // pixels to pull before refresh triggers
        countdownInterval: 1000, // update every second
        sseEndpoint: '/api/notices/stream',
        apiEndpoint: '/api/notices',
    },

    // State
    state: {
        isPulling: false,
        pullStartY: 0,
        pullDistance: 0,
        countdownTimers: [],
        sseConnection: null,
        currentPage: 1,
        isLoading: false,
    },

    // DOM elements
    elements: {},

    /**
     * Initialize the notices module
     */
    init() {
        this.cacheElements();
        this.bindEvents();
        this.initCountdowns();
        // SSE is optional - uncomment to enable
        // this.initSSE();

        console.log('[Notices] Module initialized');
    },

    /**
     * Cache DOM elements
     */
    cacheElements() {
        this.elements = {
            noticesList: document.getElementById('notices-list'),
            loadMoreBtn: document.getElementById('load-more-btn'),
            countdowns: document.querySelectorAll('.notice-countdown'),
        };
    },

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Pull to refresh
        if (this.elements.noticesList) {
            this.elements.noticesList.addEventListener('touchstart', (e) => this.handleTouchStart(e), { passive: true });
            this.elements.noticesList.addEventListener('touchmove', (e) => this.handleTouchMove(e), { passive: false });
            this.elements.noticesList.addEventListener('touchend', () => this.handleTouchEnd());
        }

        // Load more button
        if (this.elements.loadMoreBtn) {
            this.elements.loadMoreBtn.addEventListener('click', () => this.loadMore());
        }

        // Delete form confirmations
        document.querySelectorAll('.notice-card form[onsubmit]').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!confirm('Are you sure you want to delete this notice?')) {
                    e.preventDefault();
                }
            });
        });
    },

    // ========================================================================
    // Pull to Refresh
    // ========================================================================

    handleTouchStart(e) {
        // Only start pull if at top of scroll
        if (window.scrollY === 0) {
            this.state.isPulling = true;
            this.state.pullStartY = e.touches[0].clientY;
        }
    },

    handleTouchMove(e) {
        if (!this.state.isPulling) return;

        const currentY = e.touches[0].clientY;
        this.state.pullDistance = currentY - this.state.pullStartY;

        // Only handle if pulling down
        if (this.state.pullDistance > 0) {
            // Prevent default scrolling when pulling
            if (this.state.pullDistance > 10) {
                e.preventDefault();
            }

            // Show pull indicator
            this.showPullIndicator(Math.min(this.state.pullDistance, this.config.refreshThreshold * 1.5));
        }
    },

    handleTouchEnd() {
        if (!this.state.isPulling) return;

        if (this.state.pullDistance >= this.config.refreshThreshold) {
            this.refresh();
        }

        this.hidePullIndicator();
        this.state.isPulling = false;
        this.state.pullDistance = 0;
    },

    showPullIndicator(distance) {
        let indicator = document.querySelector('.pull-indicator');

        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'pull-indicator';
            indicator.innerHTML = '<span class="pull-icon">&#8635;</span><span class="pull-text">Pull to refresh</span>';
            this.elements.noticesList.insertBefore(indicator, this.elements.noticesList.firstChild);
        }

        const progress = distance / this.config.refreshThreshold;
        indicator.style.height = `${Math.min(distance, 60)}px`;
        indicator.style.opacity = Math.min(progress, 1);

        if (progress >= 1) {
            indicator.classList.add('ready');
            indicator.querySelector('.pull-text').textContent = 'Release to refresh';
        } else {
            indicator.classList.remove('ready');
            indicator.querySelector('.pull-text').textContent = 'Pull to refresh';
        }
    },

    hidePullIndicator() {
        const indicator = document.querySelector('.pull-indicator');
        if (indicator) {
            indicator.style.height = '0';
            indicator.style.opacity = '0';
            setTimeout(() => indicator.remove(), 200);
        }
    },

    /**
     * Refresh notices list
     */
    async refresh() {
        if (this.state.isLoading) return;

        this.state.isLoading = true;
        this.showLoadingState();

        try {
            const response = await App.api(this.config.apiEndpoint);
            this.renderNotices(response.data, true);
            this.state.currentPage = 1;

            if (window.App && App.showToast) {
                App.showToast('Notices refreshed', 'success', 2000);
            }
        } catch (error) {
            console.error('[Notices] Refresh failed:', error);
            if (window.App && App.showToast) {
                App.showToast('Failed to refresh notices', 'error');
            }
        } finally {
            this.state.isLoading = false;
            this.hideLoadingState();
        }
    },

    // ========================================================================
    // Countdown Timers
    // ========================================================================

    initCountdowns() {
        // Find all countdown elements
        const countdowns = document.querySelectorAll('.notice-countdown[data-seconds]');

        countdowns.forEach(el => {
            let seconds = parseInt(el.dataset.seconds, 10);

            if (seconds > 0) {
                const timer = setInterval(() => {
                    seconds--;

                    if (seconds <= 0) {
                        clearInterval(timer);
                        el.textContent = 'Expired';
                        el.classList.add('expired');

                        // Optionally hide/fade the notice
                        const noticeCard = el.closest('.notice-card, .notice-detail');
                        if (noticeCard) {
                            noticeCard.classList.add('notice-expired');
                        }
                    } else {
                        el.textContent = this.formatCountdown(seconds);
                    }
                }, this.config.countdownInterval);

                this.state.countdownTimers.push(timer);
            }
        });
    },

    /**
     * Format seconds into human-readable countdown
     */
    formatCountdown(seconds) {
        if (seconds < 60) {
            return `${seconds}s`;
        }

        if (seconds < 3600) {
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${minutes}m ${secs}s`;
        }

        if (seconds < 86400) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            return `${hours}h ${minutes}m`;
        }

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        return `${days}d ${hours}h`;
    },

    // ========================================================================
    // Server-Sent Events (Real-time updates)
    // ========================================================================

    initSSE() {
        if (!window.EventSource) {
            console.log('[Notices] SSE not supported');
            return;
        }

        try {
            this.state.sseConnection = new EventSource(this.config.sseEndpoint);

            this.state.sseConnection.onopen = () => {
                console.log('[Notices] SSE connected');
            };

            this.state.sseConnection.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleSSEMessage(data);
                } catch (e) {
                    console.error('[Notices] Failed to parse SSE message:', e);
                }
            };

            this.state.sseConnection.onerror = () => {
                console.log('[Notices] SSE connection error, reconnecting...');
                // Browser will automatically reconnect
            };
        } catch (e) {
            console.error('[Notices] Failed to initialize SSE:', e);
        }
    },

    handleSSEMessage(data) {
        switch (data.type) {
            case 'notice.created':
                this.handleNewNotice(data.notice);
                break;

            case 'notice.updated':
                this.handleUpdatedNotice(data.notice);
                break;

            case 'notice.deleted':
                this.handleDeletedNotice(data.noticeId);
                break;

            default:
                console.log('[Notices] Unknown SSE message type:', data.type);
        }
    },

    handleNewNotice(notice) {
        // Show toast notification
        if (window.App && App.showToast) {
            App.showToast(`New notice: ${notice.title}`, 'info');
        }

        // Refresh the list to show the new notice
        this.refresh();
    },

    handleUpdatedNotice(notice) {
        const card = document.querySelector(`.notice-card[data-notice-id="${notice.id}"]`);
        if (card) {
            // Could update in place, but refreshing is simpler
            this.refresh();
        }
    },

    handleDeletedNotice(noticeId) {
        const card = document.querySelector(`.notice-card[data-notice-id="${noticeId}"]`);
        if (card) {
            card.style.opacity = '0';
            card.style.transform = 'translateX(-100%)';
            setTimeout(() => card.remove(), 300);
        }
    },

    // ========================================================================
    // Load More / Pagination
    // ========================================================================

    async loadMore() {
        if (this.state.isLoading || !this.elements.loadMoreBtn) return;

        this.state.isLoading = true;
        this.elements.loadMoreBtn.disabled = true;
        this.elements.loadMoreBtn.textContent = 'Loading...';

        try {
            this.state.currentPage++;
            const response = await App.api(`${this.config.apiEndpoint}?page=${this.state.currentPage}`);

            if (response.data && response.data.length > 0) {
                this.renderNotices(response.data, false);

                // Hide button if no more pages
                if (this.state.currentPage >= response.meta.total_pages) {
                    this.elements.loadMoreBtn.style.display = 'none';
                }
            } else {
                this.elements.loadMoreBtn.style.display = 'none';
            }
        } catch (error) {
            console.error('[Notices] Load more failed:', error);
            this.state.currentPage--;
            if (window.App && App.showToast) {
                App.showToast('Failed to load more notices', 'error');
            }
        } finally {
            this.state.isLoading = false;
            this.elements.loadMoreBtn.disabled = false;
            this.elements.loadMoreBtn.textContent = 'Load More';
        }
    },

    // ========================================================================
    // Rendering
    // ========================================================================

    renderNotices(notices, replace = false) {
        const container = this.elements.noticesList?.querySelector('.notices-list');
        if (!container) return;

        if (replace) {
            container.innerHTML = '';
        }

        notices.forEach(notice => {
            const card = this.createNoticeCard(notice);
            container.appendChild(card);
        });

        // Re-initialize countdowns for new elements
        this.initCountdowns();
    },

    createNoticeCard(notice) {
        const article = document.createElement('article');
        article.className = `notice-card notice-type-${notice.type}`;
        article.dataset.noticeId = notice.id;

        if (notice.remaining_seconds) {
            article.dataset.expiresIn = notice.remaining_seconds;
        }

        let badgeHtml = '';
        if (notice.type === 'sticky') {
            badgeHtml = '<span class="notice-badge notice-badge-sticky"><span class="badge-icon">&#128204;</span> Pinned</span>';
        } else if (notice.type === 'urgent') {
            badgeHtml = '<span class="notice-badge notice-badge-urgent"><span class="badge-icon">&#9888;</span> Urgent</span>';
        } else if (notice.type === 'timed' && notice.remaining_seconds) {
            badgeHtml = `<span class="notice-badge notice-badge-timed"><span class="badge-icon">&#9201;</span> <span class="notice-countdown" data-seconds="${notice.remaining_seconds}">${this.formatCountdown(notice.remaining_seconds)}</span></span>`;
        }

        article.innerHTML = `
            <div class="notice-card-header">
                <div class="notice-card-meta">${badgeHtml}</div>
            </div>
            <div class="notice-card-body">
                <h3 class="notice-card-title">
                    <a href="/notices/${notice.id}">${this.escapeHtml(notice.title)}</a>
                </h3>
                ${notice.excerpt ? `<div class="notice-card-excerpt">${this.escapeHtml(notice.excerpt)}</div>` : ''}
            </div>
            <div class="notice-card-footer">
                <span class="notice-author">${notice.author?.name ? `By ${this.escapeHtml(notice.author.name)}` : ''}</span>
                <span class="notice-date">${this.formatRelativeTime(notice.created_at)}</span>
            </div>
        `;

        return article;
    },

    // ========================================================================
    // Utilities
    // ========================================================================

    showLoadingState() {
        const container = this.elements.noticesList;
        if (container) {
            container.classList.add('loading');
        }
    },

    hideLoadingState() {
        const container = this.elements.noticesList;
        if (container) {
            container.classList.remove('loading');
        }
    },

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    formatRelativeTime(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const diff = (now - date) / 1000;

        if (diff < 60) return 'just now';
        if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
        if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;

        return date.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
    },

    /**
     * Cleanup when leaving page
     */
    destroy() {
        // Clear countdown timers
        this.state.countdownTimers.forEach(timer => clearInterval(timer));
        this.state.countdownTimers = [];

        // Close SSE connection
        if (this.state.sseConnection) {
            this.state.sseConnection.close();
            this.state.sseConnection = null;
        }
    },
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Notices.init());
} else {
    Notices.init();
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => Notices.destroy());

// Export for use in other modules
window.Notices = Notices;
