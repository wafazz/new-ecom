<?php
/** The invoice popup used by every "View invoice" link. Hidden until a link opens it (see js/invoice-modal.js). */
?>
<dialog class="invoice-modal" id="invoice-modal" aria-labelledby="invoice-modal-title">
    <div class="invoice-inner">
        <header class="invoice-head">
            <h2 id="invoice-modal-title">Invoice</h2>
            <div class="invoice-actions">
                <a class="invoice-btn primary" id="invoice-download" href="#" download>Download PDF</a>
                <a class="invoice-btn" id="invoice-newtab" href="#" target="_blank" rel="noopener">Open in new tab</a>
                <button type="button" class="invoice-btn invoice-close" data-invoice-close aria-label="Close invoice">Close</button>
            </div>
        </header>
        <iframe id="invoice-frame" class="invoice-frame" title="Invoice (PDF)" src="about:blank"></iframe>
        <p class="invoice-fallback">If the invoice does not show here, use <strong>Download PDF</strong> or <strong>Open in new tab</strong>.</p>
    </div>
</dialog>
