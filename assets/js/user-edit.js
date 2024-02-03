jQuery(function($){

	if( taxjarExpansion.autoAssign == ''){
		// Don't do anything if disabled.
		return;
	}

	// select the first element with $('input[id^="md-multiple-roles-tax_exempt"]')
	let role_checkbox= $('input[id^="md-multiple-roles-tax_exempt"]').first();
	let role_label = $('label[for="' + role_checkbox.attr('id') + '"]');
	let exemption_type_select = $('#' + taxjarExpansion.taxExemptionTypeMetaKey);
	let certificate_name = $('#certificate_name');
	let certificate_upload_button_label = $('label[for="taxjar_expansion-certificate"]');
	let certificate_upload_input = $('#taxjar_expansion-certificate');
	let certificate_delete_checkbox = $('#taxjar_expansion-delete-cert');
	let certificate_delete_x = $('#taxjar_expansion-remove-certificate');
	let the_501c3_checkbox = $('input#taxjar_expansion-501c3');
	let expiration_date = $('#taxjar_expansion-expiration');
	let gray = 'tje-readonly-gray';
	let auto_note = 'Auto Assigned via <a href="#tax_exempt_regions">TaxJar Expansion plugin settings below</a>.';

	var certificate_uploaded, is_501c3, expiration;

	// Set the initial values
	certificate_uploaded = certificate_name.html().length > 0;
	is_501c3 = the_501c3_checkbox.is(":checked");
	expiration = expiration_date.val();

	// Make built-in related fields readonly
	role_checkbox.on('click',function(e){
		e.preventDefault();
	});
	role_label.addClass(gray).append(' (' + auto_note + ')');
	exemption_type_select.attr('disabled',true).next('.description').html(auto_note).addClass(gray);

	// Disable the expiration date if 501c3 is checked
	access_control_expiration();

	// When remove button is clicked, clear the hidden input
	certificate_delete_x.on('click',function(){
		certificate_upload_input.val('').trigger('change');
	});

	// When the hidden file upload input is changed
	certificate_upload_input.on('change',function(){
		// Get the filename
		let filename = $(this).val().split("\\").splice(-1,1)[0] 

		// If we uploaded a file
		if( filename ){
			// Set visible field name so we know the name of the file uploaded
			certificate_name.html(filename);

			// Clear the hidden input so we don't delete the file
			certificate_delete_checkbox.val('')

			// Show the delete button so we can remove it if needed
			certificate_delete_x.show();

			// Change the label to say "Replace" instead of "Upload"
			certificate_upload_button_label.html('Replace');

			certificate_uploaded = true;

		// If we removed the file
		} else {
			// Clear the visible field name
			certificate_name.html('');

			// Set the hidden input to delete the file upon saving
			certificate_delete_checkbox.val('true')

			// Hide the delete button cause there's no filename there anymore
			certificate_delete_x.hide();

			// Change the label to say "Upload" instead of "Replace"
			certificate_upload_button_label.html('Upload');

			certificate_uploaded = false;
		}
		validate_status();
	});

	// When 501c3 is checked, disable expiration date as it's not needed
	the_501c3_checkbox.on('change',function(){
		access_control_expiration();
		validate_status();
	});

	function access_control_expiration(){
		if (the_501c3_checkbox.is(":checked")) {
			expiration_date.attr('disabled',true).addClass(gray).val('').next('.description').addClass(gray);
			is_501c3 = true;
		} else {
			expiration_date.attr('disabled',false).removeClass(gray).next('.description').removeClass(gray);
			is_501c3 = false;
		}
	}

	// When expiration date is changed, validate the status
	expiration_date.on('change',function(){
		expiration = $(this).val();
		validate_status();
	});

	// Do not run validate_status() on start, as it will show the user the role and exemption type it should be, not what is actually saved in the database. The logic to set these fields in the database is in the php files, making them more secure and centralizing the logic for both front and back end forms. This validate_status() is for visual UI purposes only. While, they should always be synced, we need to be able to spot when they are not.
	function validate_status(){
		if( 
			certificate_uploaded 
			&& ( 
				is_501c3
				|| (
					expiration
					&& expiration.length > 0
					&& new Date(expiration) >= new Date() 
				)
			)
		){
			make_exempt(true);
		} else {
			make_exempt(false);
		}
	}

	function make_exempt(status){
		if( status ){
			role_checkbox.prop('checked',true);
			exemption_type_select.val(taxjarExpansion.autoAssign);
		} else {
			role_checkbox.prop('checked',false);
			exemption_type_select.val('');
		}
	}

})