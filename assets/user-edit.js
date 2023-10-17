jQuery(function($){
	let originalFilenameLink = $('#certificate_name').html();
	$('input#taxjar_expansion-certificate').on('change',function(){
		
		let filename = 
			$(this).val().split("\\").splice(-1,1)[0] 
			|| 
			originalFilenameLink ;
		$('#certificate_name').html(filename);
	});
	
	if( taxjarExpansion.autoAssign /* || taxjarExpansion.tempOverride */ ){
		$('#tax_exemption_type').attr('disabled',true)
	}

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
});