import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        datetime: String,
        format: { type: String, default: 'short' },
    };

    connect() {
        const date = new Date(this.datetimeValue);
        if (isNaN(date.getTime())) {
            return;
        }
        this.element.textContent = this.formatDate(date);
    }

    formatDate(date) {
        if (this.formatValue === 'date') {
            return date.toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            });
        }

        if (this.formatValue === 'long') {
            return date.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
            });
        }

        // short (default): "Jan 5, 3:42 PM"
        return (
            date.toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
            }) +
            ', ' +
            date.toLocaleTimeString(undefined, {
                hour: 'numeric',
                minute: '2-digit',
            })
        );
    }
}
