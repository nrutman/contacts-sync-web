import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        csrfToken: String,
        syncCsrfToken: String,
    };

    static targets = [
        "selectAll",
        "rowCheckbox",
        "toolbar",
        "selectedCount",
        "dropdown",
        "syncDialog",
        "syncList",
        "syncCloseButton",
        "scheduleDialog",
        "scheduleOutput",
    ];

    connect() {
        this.updateToolbar();
        this.handleClickOutside = this.handleClickOutside.bind(this);
        document.addEventListener("click", this.handleClickOutside);
    }

    disconnect() {
        document.removeEventListener("click", this.handleClickOutside);
    }

    // --- Selection ---

    toggleAll() {
        const checked = this.selectAllTarget.checked;
        this.rowCheckboxTargets.forEach((cb) => (cb.checked = checked));
        this.updateToolbar();
    }

    toggleRow() {
        this.updateSelectAll();
        this.updateToolbar();
    }

    updateSelectAll() {
        const total = this.rowCheckboxTargets.length;
        const checked = this.rowCheckboxTargets.filter((cb) => cb.checked).length;

        this.selectAllTarget.checked = checked === total;
        this.selectAllTarget.indeterminate = checked > 0 && checked < total;
    }

    updateToolbar() {
        const checked = this.selectedIds();
        const count = checked.length;

        if (this.hasToolbarTarget) {
            this.toolbarTarget.classList.toggle("hidden", count === 0);
        }

        if (count === 0) {
            this.closeDropdown();
        }

        if (this.hasSelectedCountTarget) {
            this.selectedCountTarget.textContent =
                count === 1 ? "1 selected" : `${count} selected`;
        }
    }

    selectedIds() {
        return this.rowCheckboxTargets
            .filter((cb) => cb.checked)
            .map((cb) => cb.value);
    }

    selectedNames() {
        return this.rowCheckboxTargets
            .filter((cb) => cb.checked)
            .map((cb) => cb.dataset.listName);
    }

    // --- Dropdown ---

    toggleDropdown(event) {
        event.stopPropagation();
        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.toggle("hidden");
        }
    }

    closeDropdown() {
        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.add("hidden");
        }
    }

    handleClickOutside(event) {
        if (
            this.hasDropdownTarget &&
            this.hasToolbarTarget &&
            !this.toolbarTarget.contains(event.target)
        ) {
            this.closeDropdown();
        }
    }

    // --- Bulk Activate / Deactivate ---

    async activate() {
        this.closeDropdown();
        const ids = this.selectedIds();
        if (ids.length === 0) return;

        await this.bulkAction("/api/sync-lists/bulk/activate", { ids });
        window.location.reload();
    }

    async deactivate() {
        this.closeDropdown();
        const ids = this.selectedIds();
        if (ids.length === 0) return;

        await this.bulkAction("/api/sync-lists/bulk/deactivate", { ids });
        window.location.reload();
    }

    // --- Bulk Schedule ---

    openSchedule() {
        this.closeDropdown();
        if (this.hasScheduleDialogTarget) {
            this.scheduleDialogTarget.showModal();
        }
    }

    closeSchedule() {
        if (this.hasScheduleDialogTarget) {
            this.scheduleDialogTarget.close();
        }
    }

    async applySchedule() {
        const ids = this.selectedIds();
        if (ids.length === 0) return;

        const cronExpression = this.hasScheduleOutputTarget
            ? this.scheduleOutputTarget.value
            : "";

        await this.bulkAction("/api/sync-lists/bulk/schedule", {
            ids,
            cronExpression,
        });

        this.closeSchedule();
        window.location.reload();
    }

    // --- Bulk Sync ---

    async syncSelected() {
        const ids = this.selectedIds();
        const names = this.selectedNames();
        if (ids.length === 0) return;

        this.buildSyncRows(ids, names);
        this.syncCloseButtonTarget.disabled = true;
        this.syncDialogTarget.showModal();

        for (let i = 0; i < ids.length; i++) {
            this.updateSyncRow(ids[i], "syncing");

            try {
                const response = await fetch(
                    `/api/sync-lists/${ids[i]}/sync`,
                    {
                        method: "POST",
                        headers: {
                            "X-CSRF-Token": this.syncCsrfTokenValue,
                            "Content-Type": "application/json",
                        },
                    },
                );

                const data = await response.json();

                if (data.success) {
                    this.updateSyncRow(
                        ids[i],
                        "done",
                        `+${data.addedCount} / -${data.removedCount}`,
                    );
                } else {
                    this.updateSyncRow(
                        ids[i],
                        "failed",
                        data.errorMessage || "Unknown error",
                    );
                }
            } catch (error) {
                this.updateSyncRow(
                    ids[i],
                    "failed",
                    error.message || "Network error",
                );
            }
        }

        this.syncCloseButtonTarget.disabled = false;
    }

    closeSyncDialog() {
        if (this.hasSyncDialogTarget) {
            this.syncDialogTarget.close();
        }
        window.location.reload();
    }

    preventSyncCloseWhileRunning(event) {
        if (
            this.hasSyncCloseButtonTarget &&
            this.syncCloseButtonTarget.disabled
        ) {
            event.preventDefault();
        }
    }

    buildSyncRows(ids, names) {
        const list = this.syncListTarget;
        list.innerHTML = "";

        for (let i = 0; i < ids.length; i++) {
            const li = document.createElement("li");
            li.className = "flex items-center justify-between px-6 py-3";
            li.dataset.syncRowId = ids[i];
            li.innerHTML = `
                <div class="flex items-center gap-3 min-w-0">
                    <span data-role="icon-waiting" class="flex-shrink-0">
                        <svg class="size-5 text-muted-foreground/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>
                    </span>
                    <span data-role="icon-syncing" class="hidden flex-shrink-0">
                        <svg class="size-5 text-primary animate-spin" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                    </span>
                    <span data-role="icon-done" class="hidden flex-shrink-0">
                        <svg class="size-5 text-green-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                    </span>
                    <span data-role="icon-failed" class="hidden flex-shrink-0">
                        <svg class="size-5 text-destructive" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                    </span>
                    <span class="text-sm font-medium text-muted-foreground truncate">${this.escapeHtml(names[i])}</span>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0 ml-4">
                    <span data-role="status" class="text-sm font-medium text-muted-foreground">Waiting</span>
                    <span data-role="detail" class="text-xs text-muted-foreground"></span>
                </div>
            `;
            list.appendChild(li);
        }
    }

    updateSyncRow(id, status, detail = "") {
        const row = this.syncListTarget.querySelector(
            `[data-sync-row-id="${id}"]`,
        );
        if (!row) return;

        const icons = ["waiting", "syncing", "done", "failed"];
        for (const icon of icons) {
            const el = row.querySelector(`[data-role='icon-${icon}']`);
            if (el) el.classList.toggle("hidden", icon !== status);
        }

        const statusEl = row.querySelector("[data-role='status']");
        if (statusEl) {
            const labels = {
                waiting: "Waiting",
                syncing: "Syncing\u2026",
                done: "Done",
                failed: "Failed",
            };
            const colors = {
                waiting: "text-muted-foreground",
                syncing: "text-primary",
                done: "text-green-600",
                failed: "text-destructive",
            };
            statusEl.textContent = labels[status] || status;
            statusEl.className = `text-sm font-medium ${colors[status] || "text-muted-foreground"}`;
        }

        const detailEl = row.querySelector("[data-role='detail']");
        if (detailEl) detailEl.textContent = detail;
    }

    // --- Helpers ---

    async bulkAction(url, body) {
        const response = await fetch(url, {
            method: "POST",
            headers: {
                "X-CSRF-Token": this.csrfTokenValue,
                "Content-Type": "application/json",
            },
            body: JSON.stringify(body),
        });

        return response.json();
    }

    escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }
}
