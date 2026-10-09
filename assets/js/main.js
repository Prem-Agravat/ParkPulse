/**
 * ParkPulse Smart Parking System - Code2Web 2026
 * Core Client-Side Utilities & AJAX Fetch Helper
 */

'use strict';

window.ParkPulse = {
    // Sanitize string to prevent XSS in dynamic HTML injections
    escapeHtml: function(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    // Format number to ₹ INR currency string
    formatCurrency: function(amount) {
        return '₹' + parseFloat(amount || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    },

    // Read active CSRF token from page
    getCsrfToken: function() {
        const input = document.querySelector('input[name="csrf_token"]') || document.querySelector('meta[name="csrf-token"]');
        return input ? (input.value || input.getAttribute('content') || '') : '';
    },

    // Standardized JSON Fetch Helper with error handling and loading indicators
    fetchJson: async function(url, options = {}) {
        const defaultHeaders = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };

        const csrf = this.getCsrfToken();
        if (csrf && !options.headers?.['X-CSRF-Token']) {
            defaultHeaders['X-CSRF-Token'] = csrf;
        }

        options.headers = {
            ...defaultHeaders,
            ...(options.headers || {})
        };

        try {
            const response = await fetch(url, options);
            const data = await response.json();
            
            if (!response.ok) {
                const errorMsg = data.message || `Request failed with status ${response.status}`;
                throw new Error(errorMsg);
            }
            return data;
        } catch (error) {
            console.error('ParkPulse Fetch Error:', error);
            throw error;
        }
    },

    // Dynamic Bootstrap Toast Notification
    showToast: function(type, message, title = '') {
        let toastContainer = document.getElementById('toast-placement-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-placement-container';
            toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            toastContainer.style.zIndex = '1090';
            document.body.appendChild(toastContainer);
        }

        const bgClass = type === 'success' ? 'bg-success text-white' : 
                       (type === 'danger' ? 'bg-danger text-white' : 
                       (type === 'warning' ? 'bg-warning text-dark' : 'bg-primary text-white'));
                       
        const iconClass = type === 'success' ? 'bi-check-circle-fill' : 
                         (type === 'danger' ? 'bi-exclamation-octagon-fill' : 
                         (type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill'));

        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center ${bgClass} border-0 shadow-lg`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');

        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi ${iconClass} fs-5"></i>
                    <div>
                        ${title ? `<strong>${title}</strong><br>` : ''}
                        ${message}
                    </div>
                </div>
                <button type="button" class="btn-close ${type === 'warning' ? '' : 'btn-close-white'} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        toastContainer.appendChild(toastEl);
        const bsToast = new bootstrap.Toast(toastEl, { delay: 4500 });
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    },

    // QR Code Generator helper
    renderQrCode: function(elementId, text, size = 180) {
        const container = document.getElementById(elementId);
        if (!container) return;
        container.innerHTML = '';
        if (typeof QRCode !== 'undefined') {
            new QRCode(container, {
                text: text,
                width: size,
                height: size,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }
    }
};

// Initialize tooltips across the app
document.addEventListener('DOMContentLoaded', () => {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
