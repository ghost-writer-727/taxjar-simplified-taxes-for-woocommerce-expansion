jQuery(function($){
    // Make #taxjar_expansion[statuses_to_sync] a select2
    $('.taxjar_expansion-statuses_to_sync').select2({
        placeholder: 'Select order statuses to sync',
        allowClear: true,
        closeOnSelect: false
    });
});