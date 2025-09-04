<?php
add_shortcode('unified_hall_booking_form', function() {
    $tariffs = get_option('hall_tariffs', []);

    // ---- DEPOSIT PRICES ----
    $main_hall_deposit_price = null;
    $crockery_deposit_price = null;

    // Find and remove deposits from tariffs for clean display
    foreach ($tariffs as $cat => &$items) {
        foreach ($items as $label => $price) {
            if (stripos($label, 'deposit') !== false) {
                if (stripos($label, 'main hall') !== false) {
                    $main_hall_deposit_price = $price;
                    unset($items[$label]);
                } elseif (stripos($label, 'crockery') !== false) {
                    $crockery_deposit_price = $price;
                    unset($items[$label]);
                }
            }
        }
    }
    unset($items); // break reference

    // Set sensible defaults if not found
    if ($main_hall_deposit_price === null) $main_hall_deposit_price = 2000;
    if ($crockery_deposit_price === null) $crockery_deposit_price = 500;

    // ---- DEFINE LABELS TO FIX THE ERROR ----
    $wifi_label = 'Wi Fi';
    $main_hall_day_label = 'Rate per day up to 24h00';
    $main_hall_hour_first_label = 'Rate per hour: for 1st hour';
    $main_hall_hour_after_label = 'Rate per hour: after 1st hour';

    ob_start();
?>
<form id="hall-booking-quote-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <h3>Contact Information</h3>
    <label for="contact_person">Your Name *</label>
    <input type="text" name="contact_person" id="contact_person" required>

    <label for="organization">Organization *</label>
    <input type="text" name="organization" id="organization" required>

    <label for="email">Your Email *</label>
    <input type="email" name="email" id="email" required>
    
    <label for="phone">Phone Number *</label>
    <input type="text" name="phone" id="phone" required>

    <div style="display:flex; gap:24px;">
        <div style="flex:2;">
            <label>Event Title*</label>
            <input type="text" name="event_title" required style="width:100%;">
        </div>
        <div style="flex:1; display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="event_privacy" id="event_privacy" value="private" style="margin-top:0;">
            <label for="event_privacy" style="margin-bottom:0;">Private Event</label>
        </div>
    </div>
    <label>Event Description</label>
    <textarea name="event_description" style="width:100%;"></textarea>
    <div style="display:flex; gap:24px;">
        <div style="flex:1;">
            <label>Preferred Space*</label>
            <select name="space" id="space" required style="width:100%;">
                <option value="Main Hall">Main Hall</option>
                <option value="Meeting Room">Meeting Room</option>
                <option value="Both Spaces">Both Spaces</option>
            </select>
        </div>
        <div style="flex:1;">
            <label>Expected Number of Guests*</label>
            <input type="number" name="guest_count" min="1" required style="width:100%;">
        </div>
    </div>
    <div style="height:12px;"></div>
    <div style="display:flex; gap:24px;">
        <div style="flex:1;">
            <label>Event Start Date*</label>
            <input type="date" name="event_start_date" id="event_start_date" required style="width:100%;">
        </div>
        <div style="flex:1;">
            <label>Event End Date*</label>
            <input type="date" name="event_end_date" id="event_end_date" required style="width:100%;">
        </div>
    </div>
    <div style="height:12px;"></div>
    <div style="display:flex; gap:24px;">
        <div style="flex:1;">
            <label>Event Time*</label>
            <select name="event_time" id="event_time" required style="width:100%;">
                <option value="Full Day">Full Day (8am-12:00am)</option>
                <option value="Morning">Morning (8am-12pm)</option>
                <option value="Afternoon">Afternoon (1pm-6pm)</option>
                <option value="Evening">Evening (6pm-12:00am)</option>
                <option value="Custom">Custom Hours</option>
            </select>
        </div>
        <div id="custom-hours" style="flex:1; display:none; align-items:center; gap:12px;">
            <label>Start:</label>
            <select name="custom_start" id="custom_start">
                <?php foreach (['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00','20:00','21:00','22:00','23:00'] as $t): ?>
                    <option><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
            <label>End:</label>
            <select name="custom_end" id="custom_end">
                <?php foreach (['09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00','20:00','21:00','22:00','23:00','24:00'] as $t): ?>
                    <option><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div style="height:12px;"></div>

    <h3>Quote & Tariff Selection</h3>
    <table style="width:100%; border-collapse:collapse;">
    <?php foreach ($tariffs as $category => $items): ?>
        <tr><th colspan="3" style="background:#f4f4f4;"><?php echo esc_html($category); ?></th></tr>
        <?php foreach ($items as $label => $price):
            $is_wifi = ($label === $wifi_label);
            $is_spotlight_sound = (strtolower($category) == "spotlights & sound");
            $is_kitchen = (strtolower($category) == "kitchen hire");
            $is_hall_day = ($label === $main_hall_day_label);
            $is_hall_hour_first = ($label === $main_hall_hour_first_label);
            $is_hall_hour_after = ($label === $main_hall_hour_after_label);
            $is_crockery = (strtolower($category) == "crockery (each)");
            $is_cutlery = (strtolower($category) == "cutlery (each)");
            $is_glassware = (strtolower($category) == "glassware (each)");
            $qty_readonly = ($is_wifi || $is_spotlight_sound || $is_kitchen || $is_hall_day || $is_hall_hour_first || $is_hall_hour_after);
        ?>
            <tr class="tariff-row"
                data-category="<?php echo esc_attr($category); ?>"
                data-label="<?php echo esc_attr($label); ?>"
            >
                <td>
                    <label>
                        <input type="checkbox"
                            class="tariff-item"
                            data-price="<?php echo esc_attr($price); ?>"
                            data-category="<?php echo esc_attr($category); ?>"
                            data-label="<?php echo esc_attr($label); ?>"
                        >
                        <?php echo esc_html($label); ?>
                    </label>
                </td>
                <td>
                    <?php if ($is_spotlight_sound || $is_kitchen): ?>
                        <span class="static-qty-display">0</span>
                    <?php else: ?>
                        <input type="number"
                            value="0" min="0" max="999"
                            style="width:80px; text-align:center;"
                            <?php echo $qty_readonly ? 'readonly' : ''; ?>
                            class="tariff-qty <?php
                                echo $is_crockery || $is_cutlery || $is_glassware ? 'crockery-qty' : '';
                            ?>"
                        >
                    <?php endif; ?>
                </td>
                <td>R <?php echo number_format((float)$price,2); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </table>
    <!-- Deposit summary area (JS will fill this in) -->
    <div id="deposit-summary" style="margin-top:18px;"></div>
    <div style="text-align:right;font-size:1.2em;">
        <strong>Total: <span id="quote-total">R 0.00</span></strong>
    </div>
    <button type="submit" style="margin-top:20px;">Submit Booking Request</button>
</form>
<script>
var mainHallDepositPrice = <?php echo json_encode($main_hall_deposit_price); ?>;
var crockeryDepositPrice = <?php echo json_encode($crockery_deposit_price); ?>;
function getDaysBetween(startDateStr, endDateStr) {
    var start = new Date(startDateStr);
    var end = new Date(endDateStr);
    if (isNaN(start.getTime()) || isNaN(end.getTime())) return 1;
    var diff = Math.floor((end - start) / (1000 * 60 * 60 * 24)) + 1;
    return diff > 0 ? diff : 1;
}

// --- Deposit calculation and display ---
function getDepositState() {
    // Main Hall deposit: 1 unless only Meeting Room selected
    var space = document.getElementById('space').value;
    var hallDeposit = (space == "Main Hall" || space == "Both Spaces") ? 1 : 0;

    // Crockery/cutlery/glassware deposit: 1 if any relevant item checked or qty>0, else 0
    var crockeryChecked = false;
    document.querySelectorAll('.tariff-item').forEach(function(el){
        var row = el.closest('tr');
        var cat = (row && row.getAttribute('data-category') || '').toLowerCase();
        if((cat === "crockery (each)" || cat === "glassware (each)" || cat === "cutlery (each)") && el.checked) crockeryChecked = true;
    });
    document.querySelectorAll('.crockery-qty').forEach(function(inp){
        if (parseInt(inp.value, 10) > 0) crockeryChecked = true;
    });
    return {
        hall: hallDeposit,
        crockery: crockeryChecked ? 1 : 0
    };
}
function updateDepositSummary() {
    var dep = getDepositState();
    var out = '';
    if (dep.hall) {
        out += '<div class="deposit-row" style="display:flex;justify-content:space-between;align-items:center;"><span>Main Hall refundable deposit</span><span>R ' + Number(mainHallDepositPrice).toFixed(2) + '</span></div>';
    }
    if (dep.crockery) {
        out += '<div class="deposit-row" style="display:flex;justify-content:space-between;align-items:center;"><span>Refundable deposit for crockery, cutlery, & glassware</span><span>R ' + Number(crockeryDepositPrice).toFixed(2) + '</span></div>';
    }
    document.getElementById('deposit-summary').innerHTML = out;
}

// --- Tariff UI logic ---
function updateTariffRows() {
    document.querySelectorAll('.tariff-row').forEach(function(row){
        var cb = row.querySelector('.tariff-item');
        var qtyInput = row.querySelector('.tariff-qty');
        var staticQty = row.querySelector('.static-qty-display');
        var isCrockery = qtyInput && qtyInput.classList.contains('crockery-qty');
        var isStatic = !!staticQty;
        // For spotlights/kitchen, just static display
        if (isStatic) {
            staticQty.textContent = cb.checked ? "1" : "0";
        }
        // Normal items: qty=1 if checked, 0 if not
        else if(qtyInput && !qtyInput.hasAttribute('readonly')) {
            qtyInput.value = cb.checked ? "1" : "0";
            qtyInput.disabled = !cb.checked;
        }
        // Crockery: like normal, but allow >1 if user sets it manually
        else if (isCrockery) {
            if(cb.checked && (!qtyInput.value || qtyInput.value == "0")) qtyInput.value = "1";
            qtyInput.disabled = !cb.checked;
            if(!cb.checked) qtyInput.value = "0";
        }
    });
}

// --- Total calculation, including deposit(s) ---
function updateTotal() {
    var total = 0;
    document.querySelectorAll('.tariff-row').forEach(function(row){
        var cb = row.querySelector('.tariff-item');
        var price = parseFloat(cb.getAttribute('data-price')) || 0;
        var staticQty = row.querySelector('.static-qty-display');
        var qtyInput = row.querySelector('.tariff-qty');
        var qty = 0;
        if(staticQty && cb.checked) qty = 1;
        else if(qtyInput && !qtyInput.disabled) qty = parseInt(qtyInput.value) || 0;
        total += price * qty;
    });
    var dep = getDepositState();
    if(dep.hall) total += Number(mainHallDepositPrice);
    if(dep.crockery) total += Number(crockeryDepositPrice);
    document.getElementById('quote-total').textContent = 'R ' + total.toFixed(2);
}

// --- Autofill logic for Hall Hire (kept as before, but now always triggers total update) ---
function autofillHallHireRates() {
    var space = document.getElementById('space').value;
    var time = document.getElementById('event_time').value;
    var startDate = document.getElementById('event_start_date').value;
    var endDate = document.getElementById('event_end_date').value;
    var days = startDate && endDate ? getDaysBetween(startDate, endDate) : 1;
    var mainHallDayRateLabel = "Main Hall Rate per day (up to 24h00)";
    var mainHallHourFirstLabel = "Rate per hour: for 1st hour";
    var mainHallHourAfterLabel = "Rate per hour: after 1st hour";
    // Reset all Hall Hire checkboxes/qty
    document.querySelectorAll('.tariff-row[data-category="Hall Hire Rates"]').forEach(function(row){
        var label = row.getAttribute('data-label');
        var checkbox = row.querySelector('.tariff-item');
        var qtyInput = row.querySelector('.tariff-qty');
        checkbox.checked = false;
        if(qtyInput) qtyInput.value = "0";
    });
    if(space == "Main Hall" || space == "Both Spaces") {
        if(time === "Full Day") {
            document.querySelectorAll('.tariff-row[data-label="' + mainHallDayRateLabel + '"]').forEach(function(row){
                var checkbox = row.querySelector('.tariff-item');
                var qtyInput = row.querySelector('.tariff-qty');
                checkbox.checked = true;
                if(qtyInput) qtyInput.value = days.toString();
            });
        } else if(time === "Morning" || time === "Afternoon" || time === "Evening") {
            var duration = 0;
            if(time === "Morning") { duration = 12 - 8; }
            if(time === "Afternoon") { duration = 18 - 13; }
            if(time === "Evening") { duration = 24 - 18; }
            var totalFirstHour = days;
            var totalAfterHour = (duration > 1) ? (days * (duration - 1)) : 0;
            document.querySelectorAll('.tariff-row[data-label="' + mainHallHourFirstLabel + '"]').forEach(function(row){
                var checkbox = row.querySelector('.tariff-item');
                var qtyInput = row.querySelector('.tariff-qty');
                checkbox.checked = true;
                if(qtyInput) qtyInput.value = totalFirstHour.toString();
            });
            if(totalAfterHour > 0) {
                document.querySelectorAll('.tariff-row[data-label="' + mainHallHourAfterLabel + '"]').forEach(function(row){
                    var checkbox = row.querySelector('.tariff-item');
                    var qtyInput = row.querySelector('.tariff-qty');
                    checkbox.checked = true;
                    if(qtyInput) qtyInput.value = totalAfterHour.toString();
                });
            }
        } else if(time === "Custom") {
            var customStart = document.getElementById('custom_start').value || "08:00";
            var customEnd = document.getElementById('custom_end').value || "09:00";
            var startParts = customStart.split(":");
            var endParts = customEnd.split(":");
            var start = parseInt(startParts[0]);
            var end = parseInt(endParts[0]);
            if(end < start) end += 24;
            var duration = end - start;
            var totalFirstHour = days;
            var totalAfterHour = (duration > 1) ? (days * (duration - 1)) : 0;
            document.querySelectorAll('.tariff-row[data-label="' + mainHallHourFirstLabel + '"]').forEach(function(row){
                var checkbox = row.querySelector('.tariff-item');
                var qtyInput = row.querySelector('.tariff-qty');
                checkbox.checked = true;
                if(qtyInput) qtyInput.value = totalFirstHour.toString();
            });
            if(totalAfterHour > 0) {
                document.querySelectorAll('.tariff-row[data-label="' + mainHallHourAfterLabel + '"]').forEach(function(row){
                    var checkbox = row.querySelector('.tariff-item');
                    var qtyInput = row.querySelector('.tariff-qty');
                    checkbox.checked = true;
                    if(qtyInput) qtyInput.value = totalAfterHour.toString();
                });
            }
        }
    }
    updateTariffRows();
    updateDepositSummary();
    updateTotal();
}

// --- Wire up all events and logic ---
document.getElementById('event_time').addEventListener('change', function(){
    document.getElementById('custom-hours').style.display = (this.value === 'Custom') ? 'flex' : 'none';
    autofillHallHireRates();
});
document.getElementById('space').addEventListener('change', autofillHallHireRates);
document.getElementById('event_start_date').addEventListener('change', autofillHallHireRates);
document.getElementById('event_end_date').addEventListener('change', autofillHallHireRates);
document.getElementById('custom_start').addEventListener('change', autofillHallHireRates);
document.getElementById('custom_end').addEventListener('change', autofillHallHireRates);
document.querySelectorAll('.tariff-item').forEach(function(cb){
    cb.addEventListener('change', function(){
        updateTariffRows();
        updateDepositSummary();
        updateTotal();
    });
});
document.querySelectorAll('.crockery-qty').forEach(function(inp){
    inp.addEventListener('input', function() {
        updateDepositSummary();
        updateTotal();
    });
});
document.querySelectorAll('.tariff-qty').forEach(function(inp){
    inp.addEventListener('input', updateTotal);
});

// Initial fill
autofillHallHireRates();
</script>
<style>
#hall-booking-quote-form select,
#hall-booking-quote-form input[type="number"],
#hall-booking-quote-form input[type="text"] {
    padding: 4px;
}
#hall-booking-quote-form td,
#hall-booking-quote-form th {
    padding:6px 8px;
    border-bottom:1px solid #eee;
}
#custom-hours label {
    margin-right: 4px;
}
.deposit-row {
    border: 1px solid #eee;
    border-radius: 4px;
    background: #f9f9f9;
    padding: 6px 12px;
    margin-bottom: 6px;
}
</style>
<?php
return ob_get_clean();
});
