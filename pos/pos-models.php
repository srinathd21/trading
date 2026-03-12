<?php
// pos-models.php - Contains all modal HTML
?>
<!-- Confirmation Modal -->
<div class="modal fade" id="confirmationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header p-2">
                <h6 class="modal-title mb-0" id="confirmationTitle"></h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <p id="confirmationMessage" class="mb-0" style="font-size: 0.8rem;"></p>
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="confirmActionBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Hold Invoice Modal -->
<div class="modal fade hold-invoice-modal" id="holdInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header p-2">
                <h6 class="modal-title mb-0">Hold Invoice</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <div class="mb-2">
                    <label class="form-label mb-1">Reference Note (Optional)</label>
                    <input type="text" id="holdReference" class="form-control form-control-sm"
                        placeholder="e.g., Customer name, phone, or reason">
                    <small class="text-muted">Max 100 characters</small>
                </div>
                <div class="mb-2">
                    <label class="form-label mb-1">Expires After</label>
                    <select id="holdExpiry" class="form-select form-select-sm">
                        <option value="24">24 hours</option>
                        <option value="48" selected>48 hours</option>
                        <option value="72">72 hours</option>
                        <option value="168">7 days</option>
                        <option value="720">30 days</option>
                    </select>
                    <small class="text-muted">Held invoices will be auto-deleted after expiry</small>
                </div>
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="confirmHold">Save Hold</button>
            </div>
        </div>
    </div>
</div>

<!-- Quotation Modal -->
<div class="modal fade" id="quotationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header p-2">
                <h6 class="modal-title mb-0">Save Quotation</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <div class="mb-2">
                    <label class="form-label mb-1">Quotation #</label>
                    <input type="text" id="quotationNumber" class="form-control form-control-sm"
                        value="<?= $quotation_number ?>" readonly>
                </div>
                <div class="mb-2">
                    <label class="form-label mb-1">Valid Until</label>
                    <input type="date" id="quotationValidUntil" class="form-control form-control-sm"
                        value="<?= date('Y-m-d', strtotime('+15 days')) ?>" min="<?= date('Y-m-d') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label mb-1">Notes (Optional)</label>
                    <textarea id="quotationNotes" class="form-control form-control-sm" rows="2"
                        placeholder="Add notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="saveQuotationBtn">Save Quotation</button>
            </div>
        </div>
    </div>
</div>

<!-- Loyalty Points Modal -->
<div class="modal fade points-details-modal" id="pointsDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header p-2">
                <h6 class="modal-title mb-0">Loyalty Points</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <div class="mb-2">
                    <h6>Available Points: <span id="availablePoints">0</span></h6>
                    <p class="mb-1">Each point = ₹<?= $loyalty_settings['redeem_value_per_point'] ?> discount</p>
                    <p class="mb-2">Minimum points to redeem: <?= $loyalty_settings['min_points_to_redeem'] ?></p>
                </div>
                <div class="mb-2">
                    <label class="form-label mb-1">Points to Redeem</label>
                    <input type="number" id="pointsToRedeem" class="form-control" 
                           value="0" min="0" step="1" max="0">
                </div>
                <div class="mb-2">
                    <p>Discount Amount: ₹<span id="pointsDiscount">0.00</span></p>
                </div>
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary btn-sm" id="applyPointsDiscount">Apply Discount</button>
            </div>
        </div>
    </div>
</div>