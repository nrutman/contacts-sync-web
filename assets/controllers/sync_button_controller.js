import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["button", "label", "spinner"];

    submit(event) {
        event.preventDefault();

        // Disable the button to prevent double-clicks
        this.buttonTarget.disabled = true;
        this.buttonTarget.classList.add("opacity-75", "cursor-not-allowed");

        // Hide the label text and show the spinner
        if (this.hasLabelTarget) {
            this.labelTarget.classList.add("invisible");
        }

        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.remove("hidden");
        }

        // Submit the form explicitly (disabled buttons suppress native submission)
        this.element.submit();
    }
}
