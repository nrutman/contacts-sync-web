import { Controller } from "@hotwired/stimulus";

/**
 * Polls the SyncRun status API for pending/running rows and updates
 * the table row in-place when the status changes.
 *
 * Usage on a <tr>:
 *   data-controller="sync-status"
 *   data-sync-status-url-value="/api/sync-runs/{id}/status"
 *   data-sync-status-interval-value="2000"
 */
export default class extends Controller {
    static values = {
        url: String,
        interval: { type: Number, default: 2000 },
    };

    static targets = [
        "status",
        "source",
        "destination",
        "added",
        "removed",
        "duration",
    ];

    connect() {
        this.poll();
    }

    disconnect() {
        this.stopPolling();
    }

    poll() {
        this.timer = setInterval(() => {
            this.fetchStatus();
        }, this.intervalValue);
    }

    stopPolling() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    async fetchStatus() {
        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: "application/json" },
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            this.updateRow(data);

            // Stop polling once the run is no longer in progress
            if (data.status !== "pending" && data.status !== "running") {
                this.stopPolling();
            }
        } catch {
            // Silently ignore network errors; we'll retry on the next interval
        }
    }

    updateRow(data) {
        if (this.hasStatusTarget) {
            this.statusTarget.innerHTML = this.renderStatusBadge(data.status);
        }

        if (this.hasSourceTarget) {
            this.sourceTarget.textContent =
                data.sourceCount !== null ? data.sourceCount : "\u2014";
        }

        if (this.hasDestinationTarget) {
            this.destinationTarget.textContent =
                data.destinationCount !== null
                    ? data.destinationCount
                    : "\u2014";
        }

        if (this.hasAddedTarget) {
            if (data.addedCount !== null) {
                const cls =
                    data.addedCount > 0
                        ? "text-green-600 font-medium"
                        : "text-gray-500";
                this.addedTarget.innerHTML = `<span class="${cls}">+${data.addedCount}</span>`;
            } else {
                this.addedTarget.innerHTML =
                    '<span class="text-gray-400">\u2014</span>';
            }
        }

        if (this.hasRemovedTarget) {
            if (data.removedCount !== null) {
                const cls =
                    data.removedCount > 0
                        ? "text-red-600 font-medium"
                        : "text-gray-500";
                this.removedTarget.innerHTML = `<span class="${cls}">-${data.removedCount}</span>`;
            } else {
                this.removedTarget.innerHTML =
                    '<span class="text-gray-400">\u2014</span>';
            }
        }

        if (this.hasDurationTarget) {
            if (data.durationSeconds !== null) {
                this.durationTarget.textContent =
                    data.durationSeconds.toFixed(1) + "s";
            } else {
                this.durationTarget.textContent = "\u2014";
            }
        }
    }

    renderStatusBadge(status) {
        const styles = {
            success: "bg-green-100 text-green-800",
            failed: "bg-red-100 text-red-800",
            running: "bg-yellow-100 text-yellow-800",
            pending: "bg-gray-100 text-gray-800",
        };

        const css = styles[status] || "bg-gray-100 text-gray-800";
        const label = status.charAt(0).toUpperCase() + status.slice(1);

        let extra = "";
        if (status === "pending" || status === "running") {
            extra =
                '<svg class="ml-1 h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
        }

        return `<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${css}">${label}${extra}</span>`;
    }
}
