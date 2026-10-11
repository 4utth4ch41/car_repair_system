/* =====================================================
   CONFIRMATION MODAL
   ===================================================== */

(function () {
    "use strict";

    const modal = document.getElementById("confirmationModal");
    const form = document.getElementById("confirmationForm");
    const details = document.getElementById("confirmationDetails");
    const reason = document.getElementById("confirmationReason");
    const submit = document.getElementById("confirmationSubmit");

    let pendingAction = null;
    let previousFocus = null;

    window.openConfirmation = function (options, action) {

        if (modal.open) {
            return;
        }

        previousFocus = document.activeElement;
        pendingAction = action;

        document.getElementById("confirmationTitle").textContent = options.title;
        document.getElementById("confirmationMessage").textContent = options.message;

        modal.classList.toggle("danger", options.danger === true);
        submit.textContent = options.button;
        submit.disabled = false;
        details.replaceChildren();

        Object.entries(options.details || {}).forEach(function ([label, value]) {

            const term = document.createElement("dt");
            const description = document.createElement("dd");

            term.textContent = label;
            description.textContent = value || "—";
            details.append(term, description);
        });

        reason.value = "";
        reason.setCustomValidity("");
        reason.required = Boolean(options.reason);
        document.getElementById("confirmationReasonGroup").hidden = !options.reason;
        document.getElementById("confirmationReasonLabel").textContent = options.reason || "";

        modal.showModal();
    };

    document.getElementById("confirmationCancel").addEventListener("click", function () {
        modal.close();
    });

    modal.addEventListener("close", function () {
        pendingAction = null;

        if (previousFocus && previousFocus.isConnected) {
            previousFocus.focus();
        }
    });

    reason.addEventListener("input", function () {
        reason.setCustomValidity("");
    });

    form.addEventListener("submit", function (event) {
        event.preventDefault();

        if (reason.required && !reason.value.trim()) {
            reason.setCustomValidity("กรุณาระบุเหตุผลหรือหมายเหตุ");
            reason.reportValidity();
            return;
        }

        if (!pendingAction || submit.disabled) {
            return;
        }

        submit.disabled = true;
        const action = pendingAction;
        pendingAction = null;
        action(reason.value.trim());
        modal.close();
    });

    document.querySelectorAll("form[data-confirm-title]").forEach(function (target) {

        target.addEventListener("submit", function (event) {
            event.preventDefault();

            const values = JSON.parse(target.dataset.confirmDetails || "{}");

            if (target.elements.repair_detail) {
                values["รายละเอียดการซ่อม"] = target.elements.repair_detail.value;
            }

            if (target.elements.inspection_result) {
                values["ผลการตรวจสอบ"] = target.elements.inspection_result.value;
            }

            window.openConfirmation({
                title: target.dataset.confirmTitle,
                message: target.dataset.confirmMessage || "กรุณาตรวจสอบข้อมูลก่อนยืนยัน",
                button: target.dataset.confirmButton,
                danger: target.dataset.confirmDanger === "true",
                reason: target.dataset.confirmReason,
                details: values
            }, function (value) {

                if (target.elements.reason) {
                    target.elements.reason.value = value;
                }

                HTMLFormElement.prototype.submit.call(target);
            });
        });
    });

})();
