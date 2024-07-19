jQuery(function($){
	let originalFilenameLink = $('#certificate_name').html();	

	// Show the filename of the uploaded certificate
	$('input#taxjar_expansion-certificate').on('change',function(){
		let filename = 
			$(this).val().split("\\").splice(-1,1)[0] 
			|| 
			originalFilenameLink ;
		$('#certificate_name').html(filename);
	});

	// Disable expiration date if 501c3 is checked
	const grayClass = 'grayed_out';
	$('input#taxjar_expansion-501c3').on('change',function(){
		if ($(this).is(":checked")) {
			$('label[for="taxjar_expansion-expiration"]').addClass(grayClass)
			$('input#taxjar_expansion-expiration').addClass(grayClass)
			$('input#taxjar_expansion-expiration').siblings('.description').addClass(grayClass)
			$('input#taxjar_expansion-expiration').prop('disabled',true)
		} else {
			$('input#taxjar_expansion-expiration').prop('disabled',false)
			$('input#taxjar_expansion-expiration').siblings('.description').removeClass(grayClass)
			$('input#taxjar_expansion-expiration').removeClass(grayClass)
			$('label[for="taxjar_expansion-expiration"]').removeClass(grayClass)
		}
	});

	// Trigger 501c3 checkbox when clicking on the description
	$('.desc-501c3').on('click', function(){
        $('#taxjar_expansion-501c3').click();
    });
});