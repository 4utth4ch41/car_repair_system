<!-- =====================================================
     LOGOUT MODAL — ยืนยันก่อนออกจากระบบทุกบทบาท
     ===================================================== -->

<dialog
    id="logoutModal"
    class="logout-modal"
    aria-labelledby="logoutModalTitle"
    aria-describedby="logoutModalDescription"
>

    <!-- ไอคอนออกจากระบบในวงกลมสีแดง ใช้ SVG เพื่อไม่ต้องโหลดฟอนต์ภายนอก -->

    <div class="logout-modal-icon" aria-hidden="true">

        <svg
            class="fas fa-logout"
            viewBox="0 0 24 24"
            width="32"
            height="32"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path d="M9 5H5v14h4"></path>
            <path d="M9 12h12m-5-5 5 5-5 5"></path>
        </svg>

    </div>

    <h2 id="logoutModalTitle">
        ยืนยันออกจากระบบ
    </h2>

    <p id="logoutModalDescription">
        คุณต้องการออกจากระบบใช่หรือไม่?
    </p>

    <!-- ปุ่มยกเลิกและยืนยันออกจากระบบ -->

    <div class="logout-modal-actions">

        <button
            type="button"
            id="logoutModalCancel"
            class="logout-modal-cancel"
            autofocus
        >
            ยกเลิก
        </button>

        <button
            type="button"
            id="logoutModalConfirm"
            class="logout-modal-confirm"
        >
            ออกจากระบบ
        </button>

    </div>

</dialog>

<style>

/* =====================================================
   LOGOUT MODAL — รูปแบบร่วมของหน้าแอดมินและผู้ใช้
   ===================================================== */

.logout-modal {
    width: min(420px, calc(100% - 32px));
    max-height: calc(100dvh - 48px);
    margin: auto;
    padding: 32px 28px;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    background: white;
    color: #0f172a;
    text-align: center;
    box-sizing: border-box;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(15, 23, 42, 0.2);
}

.logout-modal::backdrop {
    background: rgba(15, 23, 42, 0.55);
}

.logout-modal-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    margin: 0 auto 20px;
    border-radius: 50%;
    background: #dc2626;
    color: white;
}

.logout-modal h2 {
    margin: 0 0 10px;
    font-size: 24px;
    line-height: 1.4;
}

.logout-modal p {
    margin: 0;
    color: #64748b;
    line-height: 1.6;
}

.logout-modal-actions {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 28px;
}

.logout-modal-actions button {
    flex: 1;
    padding: 12px 18px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}

.logout-modal-cancel {
    background: white;
    color: #475569;
}

.logout-modal-cancel:hover {
    background: #f8fafc;
}

.logout-modal-actions .logout-modal-confirm {
    border-color: #dc2626;
    background: #dc2626;
    color: white;
}

.logout-modal-confirm:hover {
    background: #b91c1c;
}

.logout-modal-actions button:focus-visible {
    outline: 3px solid #fca5a5;
    outline-offset: 3px;
}

.logout-modal-actions button:disabled {
    opacity: 0.6;
    cursor: wait;
}

</style>

<script>

/* =====================================================
   LOGOUT CONFIRMATION — เปิดกล่องก่อนตามลิงก์ออกจากระบบ
   ===================================================== */

(function () {
    "use strict";

    const modal = document.getElementById("logoutModal");
    const cancelButton = document.getElementById("logoutModalCancel");
    const confirmButton = document.getElementById("logoutModalConfirm");

    let logoutUrl = null;
    let previousFocus = null;

    // เก็บปลายทางเดิม รองรับทั้งหน้าหลักและหน้าที่อยู่ในโฟลเดอร์ย่อย

    document.querySelectorAll("a.logout-btn").forEach(function (link) {

        link.addEventListener("click", function (event) {
            event.preventDefault();

            if (modal.open) {
                return;
            }

            logoutUrl = link.href;
            previousFocus = link;
            confirmButton.disabled = false;
            modal.showModal();
        });
    });

    // ยกเลิกหรือกด Escape จะปิดกล่องและยังอยู่ในระบบ

    cancelButton.addEventListener("click", function () {
        modal.close();
    });

    modal.addEventListener("close", function () {
        logoutUrl = null;

        if (previousFocus && previousFocus.isConnected) {
            previousFocus.focus();
        }
    });

    // ออกจากระบบเมื่อยืนยันเท่านั้น และป้องกันการกดซ้ำ

    confirmButton.addEventListener("click", function () {

        if (!logoutUrl || confirmButton.disabled) {
            return;
        }

        confirmButton.disabled = true;
        window.location.assign(logoutUrl);
    });

})();

</script>
