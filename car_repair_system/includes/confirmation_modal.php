<!-- =====================================================
     CONFIRMATION MODAL
     ===================================================== -->

<dialog
    id="confirmationModal"
    class="confirmation-modal"
    aria-labelledby="confirmationTitle"
    aria-describedby="confirmationMessage"
>

    <form id="confirmationForm">

        <h2 id="confirmationTitle"></h2>

        <p id="confirmationMessage"></p>

        <dl id="confirmationDetails"></dl>

        <div id="confirmationReasonGroup" hidden>

            <label for="confirmationReason" id="confirmationReasonLabel"></label>

            <textarea
                id="confirmationReason"
                rows="3"
            ></textarea>

        </div>

        <div class="confirmation-actions">

            <button type="button" id="confirmationCancel" autofocus>
                ย้อนกลับ
            </button>

            <button type="submit" id="confirmationSubmit"></button>

        </div>

    </form>

</dialog>

<script src="../../assets/js/confirmation-modal.js"></script>
