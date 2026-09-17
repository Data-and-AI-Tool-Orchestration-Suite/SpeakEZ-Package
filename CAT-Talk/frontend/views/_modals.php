<!-- Notification Modal -->
<div class="modal fade" id="notificationModal" tabindex="-1" role="dialog" aria-labelledby="notificationModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="notificationModalLabel"></h5>
                <button type="button" class="btn-close" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="notificationModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<!-- Error Modal -->
<div class="modal fade" id="errorModal" tabindex="-1" role="dialog" aria-labelledby="errorModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger" id="errorModalLabel">Error!</h5>
                <button type="button" class="btn-close" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="errorModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-labelledby="confirmModalLabel" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="confirmModalLabel">Confirm Modal</h5>
				<button type="button" class="btn-close" aria-label="Close" onclick="hideConfirmModal();"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" id="confirmModalInternalId" value="" />
				<div class="row">
					<div class="col-sm-12 mb-3" id="confirmModalTextSpan"></div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" onclick="hideConfirmModal();">Dismiss</button>
				<button type="button" class="btn btn-danger" id="confirmModalConfirmButton">Confirm</button>
			</div>
		</div>
	</div>
</div>
<!-- Confirm Modal Version 2-->
<!-- Use in the same way as the window confirm() function -->
<div class="modal fade" id="customConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Please Confirm</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <p id="customConfirmMessage"></p>
        </div>
        <div class="modal-footer">
            <button type="button" id="customConfirmCancel" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" id="customConfirmOk" class="btn btn-primary">OK</button>
        </div>
        </div>
    </div>
</div>
<!-- TOS Agreement Modal -->
<?php if (Plugin::isPluginActiveByName("user_agreement")): ?>
<div class="modal fade" id="agreementModal" tabindex="-1" role="dialog" aria-labelledby="agreementModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="agreementModalTitle">Citation Acknowledgement</h5>
			</div>
			<div class="modal-body">
				<div class="dua-body">
				
					<?php include_once __DIR__ . '/agreement.html'; ?>

				</div>
				<br>

				<div>
					<input id="agreement-accepted-checkbox" type="checkbox" style="float: left; margin-top: 5px;">
					<div style="margin-left: 25px;">
						I have read and accept the citation agreement for this project
					</div>

				</div>
			</div>
			<div class="modal-footer">
				<button id="reject-agreement-btn" type="button" class="btn btn-secondary">Reject</button>
				<button id="accept-agreement-btn" type="button" class="btn btn-primary" onclick="acceptAgreement()" disabled>Accept</button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<script>
    function showNotification(msg, title) {
        const content = document.createElement('div');
        content.style.display = 'flex'; // Apply flexbox to align elements
        content.style.justifyContent = 'space-between'; // Space between content and close button
        content.style.alignItems = 'center'; // Align items vertically
        content.innerHTML = `
            <div>
                <h6 class="text-white m-0">${title}</h6>
                <span class="text-white">${msg}</span>
            </div>`;

        Toastify({
            node: content,
            style: {
                background: '#0dcaf0',
                display: "flex",
            },
            close: true
        }).showToast();
    }

    function showSuccess(msg, title = 'Success') {
        const content = document.createElement('div');
        content.style.display = 'flex'; // Apply flexbox to align elements
        content.style.justifyContent = 'space-between'; // Space between content and close button
        content.style.alignItems = 'center'; // Align items vertically
        content.innerHTML = `
            <div>
                <h6 class="text-white m-0">${title}</h6>
                <span class="text-white">${msg}</span>
            </div>`;

        Toastify({
            node: content,
            style: {
                background: '#28a745',
                display: "flex",
            },
            close: true
        }).showToast();
    }


    function showWarning(msg, title = 'Warning') {
        const content = document.createElement('div');
        content.style.display = 'flex'; // Apply flexbox to align elements
        content.style.justifyContent = 'space-between'; // Space between content and close button
        content.style.alignItems = 'center'; // Align items vertically
        content.innerHTML = `
            <div>
                <h6 class="text-white m-0">${title}</h6>
                <span class="text-white">${msg}</span>
            </div>`;

        Toastify({
            node: content,
            style: {
                background: '#ffc107',
                display: "flex",
            },
            close: true
        }).showToast();
    }

    function showError(msg, title = 'Error') {
        const content = document.createElement('div');
        content.style.display = 'flex'; // Apply flexbox to align elements
        content.style.justifyContent = 'space-between'; // Space between content and close button
        content.style.alignItems = 'center'; // Align items vertically
        content.innerHTML = `
            <div>
                <h6 class="text-white m-0">${title}</h6>
                <span class="text-white">${msg}</span>
            </div>`;

        Toastify({
            node: content,
            style: {
                background: '#dc3545',
                display: "flex",
            },
            close: true
        }).showToast();
    }

    var confirmModal = $('#confirmModal');
    var confirmModalTitle = $('#confirmModalLabel');
    var confirmModalInternalId = $('#confirmModalInternalId');
    var confirmModalTextSpan = $('#confirmModalTextSpan');
    var confirmModalButton = $('#confirmModalConfirmButton');

    confirmModal.on('hidden.bs.modal', function() {
        confirmModalTitle.html('');
        confirmModalInternalId.val('');
        confirmModalTextSpan.html('');
    });


    function showConfirmModal(){
        $('#confirmModal').modal("show");
    }
    function hideConfirmModal(){
        $('#confirmModal').modal("hide");
    }

    function customConfirm(message) {
        return new Promise((resolve) => {
            const modalEl = document.getElementById('customConfirmModal');
            const messageEl = document.getElementById('customConfirmMessage');
            const okBtn = document.getElementById('customConfirmOk');
            const cancelBtn = document.getElementById('customConfirmCancel');
            
            messageEl.textContent = message;

            // Create Bootstrap modal instance
            const modal = new bootstrap.Modal(modalEl, {
                backdrop: 'static',
                keyboard: false
            });

            const handleOk = () => {
                resolve(true);
                modal.hide();
            };

            const handleCancel = () => {
                resolve(false);
                modal.hide();
            };

            okBtn.addEventListener('click', handleOk, { once: true });
            cancelBtn.addEventListener('click', handleCancel, { once: true });

            modal.show();
        });
    }

    $(function() {
        <?php if (isset($_SESSION['FLASH_ERROR'])): ?>
        showError('<?php echo $_SESSION['FLASH_ERROR']; unset($_SESSION['FLASH_ERROR']); ?>');
        <?php endif; ?>
        $('.modal').on('shown.bs.modal', function() {
            $(this).find('[autofocus]').trigger('focus');
        });
    });

    function acceptAgreement(){
        $.ajax({
            url: '<?= $rootURL ?>/users/accept-agreement',
            method: 'get',
            success: function(data) {
                $("#agreementModal").modal("hide");
            },
            error: function(xhr, request, error) {
                console.error(xhr.responseJSON)
                showError('Failed to accept agreement. Please try again.');
            }
        });
    }

    $("#agreement-accepted-checkbox").change(
        function() {
            if ($(this).is(":checked")){
                $("#accept-agreement-btn").prop("disabled", false);
            } else {
                $("#accept-agreement-btn").prop("disabled", true);
            }
        }
    );
    <?php if ((Plugin::isPluginActiveByName("user_agreement")) && ($user && $user->getId())): ?>
    $(function() {
        userAcceptedAgreement = <?php echo $user->getAcceptedAgreement() ? 'true' : 'false'; ?>;
        if (!userAcceptedAgreement) {
            $('#reject-agreement-btn').click(function() {
                event.preventDefault();
                alert('You must accept the agreement to use <?= $_headerTenantStyles["title_text"] ?>');
            });
            $("#agreementModal").modal({
                backdrop: 'static',
                keyboard: false
            });
            $("#agreementModal").modal("show");
        }
    });
    <?php endif; ?>


</script>