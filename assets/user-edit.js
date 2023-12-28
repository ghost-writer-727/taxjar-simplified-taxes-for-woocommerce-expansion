jQuery(function($){
	// When hidden input is changed, update the visible fields
	$('input#taxjar_expansion-certificate').on('change',function(){
		let filename = $(this).val().split("\\").splice(-1,1)[0] 
		if( filename ){
			$('#certificate_name').html(filename);
			$('input#taxjar_expansion-delete-cert').val('')
			$('#taxjar_expansion-remove-certificate').show();
			$('label[for="taxjar_expansion-certificate"]').html('Replace')
		} else {
			$('#certificate_name').html('');
			$('input#taxjar_expansion-delete-cert').val('true')
			$('#taxjar_expansion-remove-certificate').hide();
			$('label[for="taxjar_expansion-certificate"]').html('Upload')
		}
	});

	// When remove button is clicked, clear the hidden input
	$('#taxjar_expansion-remove-certificate').on('click',function(){
		$('input#taxjar_expansion-certificate').val('').trigger('change')
	})
	
	// When settings indicate that users should be auto assigned their tax exemption, disable the default field
	if( taxjarExpansion.autoAssign ){
		$('#' + taxjarExpansion.taxExemptionTypeMetaKey).attr('disabled',true)
		$('#' + taxjarExpansion.taxExemptionTypeMetaKey).next('.description').html('This field is auto-assigned based on the the TaxJar Expansion plugin settings below.')
	}

	// When 501c3 is checked, disable expiration date as it's not needed
	$('input#taxjar_expansion-501c3').on('change',function(){
		if ($(this).is(":checked")) {
			$('input#taxjar_expansion-expiration').addClass('grayed_out')
			$('input#taxjar_expansion-expiration').next(".description").addClass('grayed_out')
			$('input#taxjar_expansion-expiration').prop('disabled',true)
		} else {
			console.log( 'enabling');
			$('input#taxjar_expansion-expiration').prop('disabled',false)
			$('input#taxjar_expansion-expiration').next(".description").removeClass('grayed_out')
			$('input#taxjar_expansion-expiration').removeClass('grayed_out')
		}
	})

})