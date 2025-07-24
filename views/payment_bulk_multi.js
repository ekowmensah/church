// payment_bulk_multi.js: Multi-payment type dynamic logic
$(function(){
    // Dynamically add/remove amount fields for each selected payment type
    $('#payment_type_id').on('change', function() {
        let selected = $(this).val();
        let container = $('#multiPaymentAmounts');
        container.empty();
        if (!selected) return;
        // If Select2 multiple, selected is array; else, make it array
        if (!Array.isArray(selected)) selected = [selected];
        selected.forEach(function(ptid){
            let label = $('#payment_type_id option[value="'+ptid+'"]').text();
            let html = '<div class="form-group">'+
                '<label>Amount for '+label+'</label>'+
                '<input type="number" class="form-control form-control-sm payment-amount" name="amounts['+ptid+']" min="0.01" step="0.01" required placeholder="₵0.00">'+
                '</div>';
            container.append(html);
        });
    });

    // On preview, include all selected payment types and their amounts for each member
    // Additional logic may be needed in ajax_bulk_members.php and main submit JS
});
