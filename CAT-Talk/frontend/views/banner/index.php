<?php
/** @var User $user */
$page = 'banner-settings';
include_once __DIR__ . '/../_header.php';
?>

<div class="container mt-5" style="max-width: 600px;">
    <h2>Site Banner Settings</h2>

    <form id="bannerForm">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="is_on" name="is_on" value="1" <?php if ($isOn) echo 'checked'; ?>>
            <label class="form-check-label" for="is_on">Banner On</label>
        </div>
        <div class="mb-3">
            <label for="message" class="form-label">Banner Message</label>
            <textarea class="form-control" id="message" name="message" rows="3" required><?= htmlspecialchars($message) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save Banner Settings</button>
    </form>
    <div id="bannerResult" class="mt-3"></div>

    <script>
    document.getElementById('bannerForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const isOn = document.getElementById('is_on').checked;
        const message = document.getElementById('message').value;

        const resultDiv = document.getElementById('bannerResult');
        resultDiv.textContent = '';

        try {
            const response = await fetch('/set-banner', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    is_on: isOn,
                    message: message
                })
            });
            if (response.ok) {
                const data = await response.json().catch(() => null);
                location.reload()
            } else {
                resultDiv.innerHTML = '<div class="alert alert-danger">Failed to save banner settings.</div>';
            }
        } catch (err) {
            resultDiv.innerHTML = '<div class="alert alert-danger">Error: ' + err + '</div>';
        }
    });
    </script>

    <hr>
    <h4>Current Banner Preview:</h4>
    <div id="bannerPreview">
    <?php if ($isOn && trim($message) !== ''): ?>
        <div class="alert alert-info"><?= nl2br(htmlspecialchars($message)) ?></div>
    <?php else: ?>
        <div class="text-muted">Banner is currently off.</div>
    <?php endif; ?>
    </div>
</div>

<?php
include_once __DIR__ . '/../_footer.php';
?>