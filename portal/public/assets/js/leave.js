/**
 * Puke Portal - Leave Request JavaScript
 *
 * Handles leave request forms, modals, and swipe-to-approve functionality.
 * ES6+ with no dependencies.
 */

'use strict';

const Leave = {
    // Current request ID for modals
    currentRequestId: null,

    // Swipe state
    swipeState: {
        startX: 0,
        currentX: 0,
        isSwiping: false,
        threshold: 80,
        element: null,
    },

    /**
     * Initialize leave functionality
     */
    init() {
        this.cacheElements();
        this.bindEvents();
        this.initSwipeActions();
    },

    /**
     * Cache DOM elements
     */
    cacheElements() {
        this.elements = {
            leaveModal: document.getElementById('leave-modal'),
            cancelModal: document.getElementById('cancel-modal'),
            approveModal: document.getElementById('approve-modal'),
            denyModal: document.getElementById('deny-modal'),
            leaveForm: document.getElementById('leave-request-form'),
            cancelForm: document.getElementById('cancel-form'),
            trainingDateInput: document.getElementById('leave-training-date'),
            leaveDateText: document.getElementById('leave-date-text'),
            cancelDateText: document.getElementById('cancel-date-text'),
            approveMemberName: document.getElementById('approve-member-name'),
            denyMemberName: document.getElementById('deny-member-name'),
            denyDateText: document.getElementById('deny-date-text'),
        };
    },

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Modal backdrop clicks
        document.querySelectorAll('.modal-backdrop, .modal-close').forEach((el) => {
            el.addEventListener('click', (e) => {
                const modal = e.target.closest('.modal');
                if (modal) {
                    modal.hidden = true;
                }
            });
        });

        // Escape key closes modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeAllModals();
            }
        });

        // Handle leave form submission via AJAX
        if (this.elements.leaveForm) {
            this.elements.leaveForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.submitLeaveRequest();
            });
        }
    },

    /**
     * Initialize swipe-to-approve/deny for touch devices
     */
    initSwipeActions() {
        const cards = document.querySelectorAll('.pending-request-card');

        cards.forEach((card) => {
            // Touch events
            card.addEventListener('touchstart', (e) => this.handleTouchStart(e, card), { passive: true });
            card.addEventListener('touchmove', (e) => this.handleTouchMove(e, card), { passive: false });
            card.addEventListener('touchend', (e) => this.handleTouchEnd(e, card));

            // Mouse events for testing
            card.addEventListener('mousedown', (e) => this.handleMouseDown(e, card));
        });

        // Global mouse events
        document.addEventListener('mousemove', (e) => this.handleMouseMove(e));
        document.addEventListener('mouseup', (e) => this.handleMouseUp(e));
    },

    // ==========================================================================
    // Leave Request Modal
    // ==========================================================================

    /**
     * Show the leave request modal
     * @param {string} date - Training date (Y-m-d format)
     * @param {string} displayDate - Human-readable date
     */
    showRequestModal(date, displayDate) {
        if (!this.elements.leaveModal) return;

        this.elements.trainingDateInput.value = date;
        this.elements.leaveDateText.textContent = displayDate;

        // Clear previous reason
        const reasonField = document.getElementById('leave-reason');
        if (reasonField) {
            reasonField.value = '';
        }

        this.elements.leaveModal.hidden = false;
        document.body.style.overflow = 'hidden';

        // Focus on reason field
        if (reasonField) {
            setTimeout(() => reasonField.focus(), 100);
        }
    },

    /**
     * Close the leave request modal
     */
    closeModal() {
        if (this.elements.leaveModal) {
            this.elements.leaveModal.hidden = true;
            document.body.style.overflow = '';
        }
    },

    /**
     * Submit leave request via AJAX
     */
    async submitLeaveRequest() {
        const form = this.elements.leaveForm;
        const submitBtn = form.querySelector('[type="submit"]');

        // Disable button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        try {
            const formData = new FormData(form);
            const data = {
                training_date: formData.get('training_date'),
                reason: formData.get('reason'),
            };

            const response = await App.api('/api/leave', {
                method: 'POST',
                body: data,
            });

            if (response.success) {
                App.showToast('Leave request submitted successfully', 'success');
                this.closeModal();

                // Reload page to show updated list
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            }
        } catch (error) {
            const message = error.data?.errors
                ? Object.values(error.data.errors)[0]
                : error.message;
            App.showToast(message, 'error');

            // Re-enable button
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Request';
        }
    },

    // ==========================================================================
    // Cancel Modal
    // ==========================================================================

    /**
     * Show cancel confirmation modal
     * @param {number} requestId - Leave request ID
     * @param {string} dateText - Human-readable date
     */
    showCancelModal(requestId, dateText) {
        if (!this.elements.cancelModal) return;

        this.currentRequestId = requestId;
        this.elements.cancelDateText.textContent = dateText;
        this.elements.cancelForm.action = `/leave/${requestId}`;

        this.elements.cancelModal.hidden = false;
        document.body.style.overflow = 'hidden';
    },

    /**
     * Close cancel modal
     */
    closeCancelModal() {
        if (this.elements.cancelModal) {
            this.elements.cancelModal.hidden = true;
            document.body.style.overflow = '';
        }
        this.currentRequestId = null;
    },

    // ==========================================================================
    // Approve/Deny (Officer actions)
    // ==========================================================================

    /**
     * Show approve confirmation modal
     * @param {number} requestId - Leave request ID
     * @param {string} memberName - Member name
     */
    approve(requestId, memberName) {
        if (!this.elements.approveModal) return;

        this.currentRequestId = requestId;
        this.elements.approveMemberName.textContent = memberName;

        this.elements.approveModal.hidden = false;
        document.body.style.overflow = 'hidden';
    },

    /**
     * Close approve modal
     */
    closeApproveModal() {
        if (this.elements.approveModal) {
            this.elements.approveModal.hidden = true;
            document.body.style.overflow = '';
        }
        this.currentRequestId = null;
    },

    /**
     * Confirm and submit approval
     */
    async confirmApprove() {
        if (!this.currentRequestId) return;

        const btn = document.getElementById('confirm-approve-btn');
        btn.disabled = true;
        btn.textContent = 'Approving...';

        try {
            const response = await App.api(`/api/leave/${this.currentRequestId}/approve`, {
                method: 'PUT',
            });

            if (response.success) {
                App.showToast('Leave request approved', 'success');
                this.closeApproveModal();

                // Remove card from DOM or reload
                this.removeRequestCard(this.currentRequestId);
            }
        } catch (error) {
            App.showToast(error.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Approve';
        }
    },

    /**
     * Show deny confirmation modal
     * @param {number} requestId - Leave request ID
     * @param {string} memberName - Member name
     * @param {string} dateText - Human-readable date
     */
    deny(requestId, memberName, dateText) {
        if (!this.elements.denyModal) return;

        this.currentRequestId = requestId;
        this.elements.denyMemberName.textContent = memberName;
        this.elements.denyDateText.textContent = dateText;

        this.elements.denyModal.hidden = false;
        document.body.style.overflow = 'hidden';
    },

    /**
     * Close deny modal
     */
    closeDenyModal() {
        if (this.elements.denyModal) {
            this.elements.denyModal.hidden = true;
            document.body.style.overflow = '';
        }
        this.currentRequestId = null;
    },

    /**
     * Confirm and submit denial
     */
    async confirmDeny() {
        if (!this.currentRequestId) return;

        const btn = document.getElementById('confirm-deny-btn');
        btn.disabled = true;
        btn.textContent = 'Denying...';

        try {
            const response = await App.api(`/api/leave/${this.currentRequestId}/deny`, {
                method: 'PUT',
            });

            if (response.success) {
                App.showToast('Leave request denied', 'success');
                this.closeDenyModal();

                // Remove card from DOM or reload
                this.removeRequestCard(this.currentRequestId);
            }
        } catch (error) {
            App.showToast(error.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Deny';
        }
    },

    /**
     * Remove a request card from the DOM with animation
     * @param {number} requestId
     */
    removeRequestCard(requestId) {
        const card = document.querySelector(`[data-request-id="${requestId}"]`);
        if (card) {
            card.style.transition = 'all 0.3s ease';
            card.style.opacity = '0';
            card.style.transform = 'translateX(100%)';

            setTimeout(() => {
                card.remove();

                // Check if the date group is now empty
                const dateGroup = document.querySelector('.training-date-group');
                if (dateGroup) {
                    const remainingCards = dateGroup.querySelectorAll('.pending-request-card');
                    if (remainingCards.length === 0) {
                        dateGroup.remove();
                    }
                }

                // Check if all requests are gone
                const allCards = document.querySelectorAll('.pending-request-card');
                if (allCards.length === 0) {
                    // Reload to show empty state
                    window.location.reload();
                }
            }, 300);
        }
    },

    /**
     * Close all modals
     */
    closeAllModals() {
        this.closeModal();
        this.closeCancelModal();
        this.closeApproveModal();
        this.closeDenyModal();
    },

    // ==========================================================================
    // Swipe Gestures
    // ==========================================================================

    handleTouchStart(e, card) {
        const touch = e.touches[0];
        this.swipeState.startX = touch.clientX;
        this.swipeState.isSwiping = true;
        this.swipeState.element = card;
        card.style.transition = 'none';
    },

    handleTouchMove(e, card) {
        if (!this.swipeState.isSwiping || this.swipeState.element !== card) return;

        const touch = e.touches[0];
        const deltaX = touch.clientX - this.swipeState.startX;
        this.swipeState.currentX = deltaX;

        // Only allow horizontal swipe
        if (Math.abs(deltaX) > 10) {
            e.preventDefault();
        }

        // Apply transform with resistance at edges
        const resistance = 0.5;
        const transform = deltaX > 0
            ? Math.min(deltaX * resistance, this.swipeState.threshold * 2)
            : Math.max(deltaX * resistance, -this.swipeState.threshold * 2);

        const cardBody = card.querySelector('.card-body');
        if (cardBody) {
            cardBody.style.transform = `translateX(${transform}px)`;
        }

        // Show swipe actions
        const approveAction = card.querySelector('.swipe-action.approve');
        const denyAction = card.querySelector('.swipe-action.deny');

        if (deltaX > this.swipeState.threshold / 2 && denyAction) {
            denyAction.style.opacity = Math.min(Math.abs(deltaX) / this.swipeState.threshold, 1);
        } else if (deltaX < -this.swipeState.threshold / 2 && approveAction) {
            approveAction.style.opacity = Math.min(Math.abs(deltaX) / this.swipeState.threshold, 1);
        }
    },

    handleTouchEnd(e, card) {
        if (!this.swipeState.isSwiping || this.swipeState.element !== card) return;

        const deltaX = this.swipeState.currentX;
        const cardBody = card.querySelector('.card-body');
        const requestId = parseInt(card.dataset.requestId, 10);

        card.style.transition = 'transform 0.3s ease';
        if (cardBody) {
            cardBody.style.transition = 'transform 0.3s ease';
            cardBody.style.transform = 'translateX(0)';
        }

        // Reset swipe actions visibility
        card.querySelectorAll('.swipe-action').forEach((action) => {
            action.style.opacity = '0';
        });

        // Check if swipe threshold was met
        if (deltaX > this.swipeState.threshold) {
            // Swiped right = Deny
            const memberName = card.querySelector('.member-name')?.textContent || '';
            this.deny(requestId, memberName, '');
        } else if (deltaX < -this.swipeState.threshold) {
            // Swiped left = Approve
            const memberName = card.querySelector('.member-name')?.textContent || '';
            this.approve(requestId, memberName);
        }

        // Reset state
        this.swipeState.isSwiping = false;
        this.swipeState.currentX = 0;
        this.swipeState.element = null;
    },

    // Mouse events for desktop testing
    handleMouseDown(e, card) {
        if (e.target.closest('.btn')) return; // Don't interfere with buttons

        this.swipeState.startX = e.clientX;
        this.swipeState.isSwiping = true;
        this.swipeState.element = card;
        card.style.transition = 'none';
    },

    handleMouseMove(e) {
        if (!this.swipeState.isSwiping || !this.swipeState.element) return;

        const deltaX = e.clientX - this.swipeState.startX;
        this.swipeState.currentX = deltaX;

        const cardBody = this.swipeState.element.querySelector('.card-body');
        if (cardBody) {
            const resistance = 0.3;
            const transform = deltaX * resistance;
            cardBody.style.transform = `translateX(${transform}px)`;
        }
    },

    handleMouseUp(e) {
        if (!this.swipeState.isSwiping || !this.swipeState.element) return;

        const card = this.swipeState.element;
        const deltaX = this.swipeState.currentX;
        const cardBody = card.querySelector('.card-body');
        const requestId = parseInt(card.dataset.requestId, 10);

        if (cardBody) {
            cardBody.style.transition = 'transform 0.3s ease';
            cardBody.style.transform = 'translateX(0)';
        }

        // Check threshold
        if (Math.abs(deltaX) > this.swipeState.threshold * 2) {
            const memberName = card.querySelector('.member-name')?.textContent || '';
            if (deltaX > 0) {
                this.deny(requestId, memberName, '');
            } else {
                this.approve(requestId, memberName);
            }
        }

        // Reset
        this.swipeState.isSwiping = false;
        this.swipeState.currentX = 0;
        this.swipeState.element = null;
    },
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Leave.init());
} else {
    Leave.init();
}

// Export for global access
window.Leave = Leave;
